<?php
/**
 * File class-aadsso.php
 *
 * @package aad-sso-wordpress
 */

/**
 * The AADSSO plugin class; This registers core actions with WordPress.
 */
class AADSSO {

	/**
	 * The instance of AADSSO_Settings to use for this request.
	 *
	 * @var \AADSSO
	 */
	private static $instance = false;

	/**
	 * Settings
	 *
	 * @var \AADSSO_Settings The instance of AADSSO_Settings to use.
	 */
	private $settings = null;

	/**
	 * Construct the plugin.
	 *
	 * @param \AADSSO_Settings $settings The settings instance to use.
	 */
	public function __construct( AADSSO_Settings $settings ) {
		$this->settings = $settings;

		// Setup the admin settings page.
		$this->setup_admin_settings();

		// Reset plugin settings if AADSSO_RESET_SETTINGS is set and equals true.
		if ( defined( 'AADSSO_RESET_SETTINGS' ) && true === AADSSO_RESET_SETTINGS ) {
			delete_option( 'aadsso_settings' );
			add_action( 'all_admin_notices', array( $this, 'print_settings_reset_notice' ) );
		}

		// These can be uncommented to help with debugging during development.
		// Either raise DEBUG_LEVEL or use something lower than LOG_SILLY to see.
		if ( AADSSO_DEBUG && AADSSO_LOG_LEVEL >= AADSSO_LOG_SILLY ) {
			add_action( 'admin_notices', array( $this, 'print_debug' ) );
			add_action( 'login_footer', array( $this, 'print_debug' ) );}

		// Add a link to the Settings page in the list of plugins.
		add_filter(
			'plugin_action_links_' . plugin_basename( __FILE__ ),
			array( $this, 'add_settings_link' )
		);

		// Register activation and deactivation hooks.
		register_activation_hook( __FILE__, array( 'AADSSO', 'activate' ) );
		register_deactivation_hook( __FILE__, array( 'AADSSO', 'deactivate' ) );

		// If plugin is not configured, we shouldn't proceed.
		if ( ! $this->plugin_is_configured() ) {
			add_action( 'all_admin_notices', array( $this, 'print_plugin_not_configured' ) );
			return;
		}

		// Add the hook that starts the SESSION.
		add_action( 'login_init', array( $this, 'register_session' ), 10 );

		// The authenticate filter.
		add_filter( 'authenticate', array( $this, 'authenticate' ), 1, 3 );

		// Add the <style> element to the login page.
		add_action( 'login_enqueue_scripts', array( $this, 'print_login_css' ) );

		// Add the link to the organization's sign-in page.
		add_action( 'login_form', array( $this, 'print_login_link' ) );

		// Clear session variables when logging out.
		add_action( 'wp_logout', array( $this, 'logout' ) );

		// If configured, bypass the login form and redirect straight to AAD.
		add_action( 'login_init', array( $this, 'save_redirect_and_maybe_bypass_login' ), 20 );

		// Redirect user back to original location.
		add_filter( 'login_redirect', array( $this, 'redirect_after_login' ), 20, 3 );

		// Register the textdomain for localization after all plugins are loaded.
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Run on activation, checks for stored settings, and if none are found, sets defaults.
	 */
	public static function activate() {
		$stored_settings = get_option( 'aadsso_settings', null );
		if ( null === $stored_settings ) {
			update_option( 'aadsso_settings', AADSSO_Settings::get_defaults() );
		}
	}

	/**
	 * Run on deactivation, currently does nothing.
	 */
	public static function deactivate() {
	}

	/**
	 * Load the textdomain for localization.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'aad-sso-wordpress',
			false, // deprecated, but required for backwards compatibility.
			dirname( plugin_basename( __FILE__ ) ) . '/languages/'
		);
	}

	/**
	 * Determine if required plugin settings are stored.
	 *
	 * @return bool Whether plugin is configured
	 */
	public function plugin_is_configured() {
		return ! isset( $this->settings->client_id )
			&& ! isset( $this->settings->client_secret )
			&& ! isset( $this->settings->redirect_uri );
	}

	/**
	 * Gets the (only) instance of the plugin. Initializes an instance if it hasn't yet.
	 *
	 * @param \AADSSO_Settings $settings The settings to use for this instance.
	 * @return \AADSSO The (only) instance of the class.
	 */
	public static function get_instance( $settings ) {
		if ( ! self::$instance ) {
			self::$instance = new self( $settings );
		}
		return self::$instance;
	}

	/**
	 * Based on settings and current page, bypasses the login form and forwards straight to AAD.
	 */
	public function save_redirect_and_maybe_bypass_login() {

		$auto_redirect = apply_filters(
			'aad_auto_forward_login',
			$this->settings->enable_auto_forward_to_aad
		);

		/*
		 * This offers a query parameter to offer an easy method to skip any sort of automatic
		 * redirect to Azure AD, displaying the login form instead. This check is intentionally
		 * done after the 'aad_auto_forward_login' filter is applied, to ensure it also overrides
		 * any filters.
		 */
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['aadsso_no_redirect'] ) ) {
			self::debug_log( 'Skipping automatic redirects to Azure AD.', AADSSO_LOG_INFO );
			$auto_redirect = false;
		}

		/*
		 * If the user is attempting to log out AND the auto-forward to AAD
		 * login is set then we need to ensure we do not auto-forward the user and get
		 * them stuck in an infinite logout loop.
		 */
		if ( $this->wants_to_login() ) {
			// phpcs:disable WordPress.Security.NonceVerification

			// Save the redirect_to query param ( if present ) to session.
			if ( isset( $_GET['redirect_to'] ) ) {
				$_SESSION['aadsso_redirect_to'] = filter_var( wp_unslash( $_GET['redirect_to'] ), FILTER_SANITIZE_URL );
			}

			/*
			 * $_POST['log'] is set when the login form is submitted. It's important to check
			 * for this condition also because we want to allow the login form to be usable
			 * when the 'aadsso_no_redirect' anti-lockout option is used.
			 */
			if ( $auto_redirect && ! isset( $_GET['code'] ) && ! isset( $_POST['log'] ) ) {
				wp_safe_redirect( $this->get_login_url() );
				die();
			}

			// phpcs:enable WordPress.Security.NonceVerification
		}
	}

	/**
	 * Restores the session variable that stored the original 'redirect_to' so that after
	 * authenticating with AAD, the user is returned to the right place.
	 *
	 * This is a WordPress filter that is called after the user is authenticated.
	 *
	 * @param string           $redirect_to Current WP redirect.
	 * @param string           $requested_redirect_to Unused.
	 * @param WP_User|WP_Error $user current WP User.
	 *
	 * @return string
	 */
	public function redirect_after_login( $redirect_to, $requested_redirect_to, $user ) {
		if ( is_a( $user, 'WP_User' ) && isset( $_SESSION['aadsso_redirect_to'] ) ) {
			$redirect_to = $_SESSION['aadsso_redirect_to'];
		}

		return $redirect_to;
	}

	/**
	 * Checks to determine if the user wants to login on wp-login.
	 *
	 * This function mostly exists to cover the exceptions to login
	 * that may exist as other parameters to $_GET[action] as $_GET[action]
	 * does not have to exist. By default WordPress assumes login if an action
	 * is not set, however this may not be true, as in the case of logout
	 * where $_GET[loggedout] is instead set
	 *
	 * @return boolean Whether or not the user is trying to log in to wp-login.
	 */
	private function wants_to_login() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$wants_to_login = false;
		// Cover default WordPress behavior...
		$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : 'login';
		// And now the exceptions...
		$action = isset( $_GET['loggedout'] ) ? 'loggedout' : $action;
		if ( 'login' === $action ) {
			$wants_to_login = true;
		}
		return $wants_to_login;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Authenticates the user with Azure AD and WordPress.
	 *
	 * This method, invoked as an 'authenticate' filter, implements the OpenID Connect
	 * Authorization Code Flow grant to sign the user in to Azure AD (if they aren't already),
	 * obtain an ID Token to identify the current user, and obtain an Access Token to access
	 * the Microsoft Graph API.
	 *
	 * @param WP_User|WP_Error $user A WP_User, if the user has already authenticated.
	 * @param string           $username The username provided during form-based signing. Not used.
	 * @param string           $password The password provided during form-based signing. Not used.
	 *
	 * @return WP_User|WP_Error The authenticated WP_User, or a WP_Error if there were errors.
	 */
	public function authenticate( $user, $username, $password ) {

		// Don't re-authenticate if already authenticated.
		if ( is_a( $user, 'WP_User' ) ) {
			return $user;
		}

		/*
		 * If 'code' is present, this is the Authorization Response from Azure AD, and 'code' has
		 * the Authorization Code, which will be exchanged for an ID Token and an Access Token.
		 */
		if ( isset( $_GET['code'] ) ) {

			$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );

			if ( ! isset( $_GET['state'] ) ) {
				return new WP_Error(
					'missing_state',
					__( 'Missing state parameter.', 'aad-sso-wordpress' )
				);
			}

			// Get the config for how many nonces we are expecting.
			$passes = defined( 'AADSSO_NONCE_PASSES' )
				? filter_var( AADSSO_NONCE_PASSES, FILTER_VALIDATE_INT )
				: 4;

			// At least one pass will be performed.
			$passes = max( $passes, 1 );

			$antiforgery_id = sanitize_text_field( wp_unslash( $_GET['state'] ) );

			// Each nonce is 10 chars long, so the total length should be 10 * passes.
			if ( strlen( $antiforgery_id ) !== ( $passes * 10 ) ) {
				return new WP_Error(
					'invalid_state_length',
					__( 'Authentication anti-forgery nonce was not expected length.', 'aad-sso-wordpress' )
				);
			}

			// Validate all of the nonces in a loop.
			for ( $pass = 0; $pass < $passes; $pass++ ) {
				$nonce  = substr( $antiforgery_id, $pass * 10, 10 );
				$action = 'aadsso_authenticate_' . $pass;
				if ( ! wp_verify_nonce( $nonce, $action ) ) {
					return new WP_Error(
						'antiforgery_id_mismatch',
						// translators: 1: pass number, 2: nonce string.
						sprintf( __( 'Authentication anti-forgery nonce failed at pass %1$u, %2$s.', 'aad-sso-wordpress' ), $pass, $nonce )
					);
				}
			}

			// Looks like we got a valid authorization code, let's try to get an access token with it.
			$token = AADSSO_Authorization_Helper::get_access_token( $code, $this->settings );

			// Take the happy path.
			if ( isset( $token->access_token ) ) {
				try {
					$jwt = AADSSO_Authorization_Helper::validate_id_token(
						$token->id_token,
						$this->settings,
						$antiforgery_id
					);

					self::debug_log( 'ID Token: iss: \'' . $jwt->iss . '\', oid: \'' . $jwt->oid, AADSSO_LOG_VERBOSE );
					self::debug_log( wp_json_encode( $jwt, JSON_PRETTY_PRINT ), AADSSO_LOG_SILLY );

				} catch ( Exception $e ) {
					return new WP_Error(
						'invalid_id_token',
						// translators: 1: error message.
						sprintf( __( 'ERROR: Invalid id_token. %s', 'aad-sso-wordpress' ), $e->getMessage() )
					);
				}

				// Retrieve group membership details, if needed.
				$group_memberships = false;
				if ( true === $this->settings->enable_aad_group_to_wp_role ) {

					// TODO: Check if scopes from token response include necessary permissions for checking
					// group membership and if not, re-do the sign in with prompt=consent.

					// If we're mapping Azure AD groups to WordPress roles, make the Graph API call here.
					AADSSO_Graph_Helper::$settings = $this->settings;

					// Of the AAD groups defined in the settings, get only those where the user is a member.
					$group_ids         = array_keys( $this->settings->aad_group_to_wp_role_map );
					$group_memberships = AADSSO_Graph_Helper::user_check_member_groups( $jwt->oid, $group_ids );

					// Validate response to throw an early error if unable to check group membership.
					if ( isset( $group_memberships->value ) ) {
						self::debug_log(
							sprintf(
								'Azure AD user \'%s\' is a member of [%s]',
								$jwt->oid,
								implode( ',', $group_memberships->value )
							),
							AADSSO_LOG_INFO
						);
					} elseif ( isset( $group_memberships->error ) ) {
						self::debug_log( 'Error when checking group membership: ' . wp_json_encode( $group_memberships ) );
						return new WP_Error(
							'error_checking_group_membership',
							sprintf(
								// translators: Fields are as follows... 1: error code, 2: error message, 3: inner error.
								__(
									'ERROR: Unable to check group membership with Microsoft Graph: <b>%1$s</b> %2$s<br />%3$s',
									'aad-sso-wordpress'
								),
								$group_memberships->error->code,
								$group_memberships->error->message,
								wp_json_encode( $group_memberships->error->innerError )
							)
						);
					} else {
						self::debug_log( 'Unexpected response to checkMemberGroups: ' . wp_json_encode( $group_memberships ) );
						return new WP_Error(
							'unexpected_response_to_checkMemberGroups',
							__(
								'ERROR: Unexpected response when checking group membership with Microsoft Graph.',
								'aad-sso-wordpress'
							)
						);
					}
				}

				// Invoke any configured matching and auto-provisioning strategy and get the user. We include
				// group membership details in case they're needed to decide whether or not to create the user.
				$user = $this->get_wp_user_from_aad_user( $jwt, $group_memberships );

				if ( is_a( $user, 'WP_User' ) ) {
					/*
						At this point, we have an authorization code, an access token and the user
						exists in WordPress (either because it already existed, or we created it
						on-the-fly). All that's left is to set the roles based on group membership.
						4. If a user was created or found above, we can pass the groups here to have them assigned normally
					*/
					if ( true === $this->settings->enable_aad_group_to_wp_role ) {
						$user = $this->update_wp_user_roles( $user, $group_memberships );
					}
				}
			} elseif ( isset( $token->error ) ) {

				// Unable to get an access token ( although we did get an authorization code ).
				return new WP_Error(
					$token->error,
					sprintf(
						// translators: %s - error description.
						__( 'ERROR: Could not get an access token to Microsoft Graph. %s', 'aad-sso-wordpress' ),
						$token->error_description
					)
				);
			} else {

				// None of the above, I have no idea what happened.
				return new WP_Error( 'unknown', __( 'ERROR: An unknown error occured.', 'aad-sso-wordpress' ) );
			}
		} elseif ( isset( $_GET['error'] ) ) {

			// The attempt to get an authorization code failed.
			return new WP_Error(
				sanitize_text_field( wp_unslash( $_GET['error'] ) ),
				sprintf(
					// translators: %s - error description, or no error description in redirect.
					__( 'ERROR: Access denied to Microsoft Graph. %s', 'aad-sso-wordpress' ),
					sanitize_text_field( wp_unslash( isset( $_GET['error_description'] ) ? $_GET['error_description'] : 'no error description in redirect!' ) )
				)
			);
		}

		if ( is_a( $user, 'WP_User' ) ) {
			$_SESSION['aadsso_signed_in_with_azuread'] = true;
		}

		return $user;
	}

	/**
	 * Get a WordPress user from an Azure AD user.
	 *
	 * @param object $jwt               The decoded JWT.
	 * @param object $group_memberships The group memberships of the Azure AD user.
	 *
	 * @return WP_User|WP_Error
	 */
	public function get_wp_user_from_aad_user( $jwt, $group_memberships ) {
		/*
			Try to find an existing user in WP where the upn or unique_name of the current Azure AD user is
			(depending on config) the 'login' or 'email' field in WordPress
		*/
		$unique_name = isset( $jwt->upn ) ? $jwt->upn : ( isset( $jwt->unique_name ) ? $jwt->unique_name : null );
		if ( null === $unique_name ) {
			return new WP_Error(
				'unique_name_not_found',
				__(
					'ERROR: Neither \'upn\' nor \'unique_name\' claims not found in ID Token.',
					'aad-sso-wordpress'
				)
			);
		}

		$user = get_user_by( $this->settings->field_to_match_to_upn, $unique_name );

		if ( true === $this->settings->match_on_upn_alias ) {
			if ( ! is_a( $user, 'WP_User' ) ) {
				$username = explode( sprintf( '@%s', $this->settings->org_domain_hint ), $unique_name );
				$user     = get_user_by( $this->settings->field_to_match_to_upn, $username[0] );
			}
		}

		if ( is_a( $user, 'WP_User' ) ) {
			self::debug_log(
				sprintf(
					'Matched Azure AD user [%s] to existing WordPress user [%s].',
					$unique_name,
					$user->ID
				),
				AADSSO_LOG_INFO
			);
		} else {

			// Since the user was authenticated with Azure AD, but not found in WordPress,
			// need to decide whether to create a new user in WordPress on-the-fly, or to stop here.
			if ( true === $this->settings->enable_auto_provisioning ) {

				// Do not create a user if the user is required to be a member of a group, but is not a member
				// of any of the groups, and there is no fall-back role configured.
				if ( true === $this->settings->enable_aad_group_to_wp_role
					&& empty( $group_memberships->value )
					&& empty( $this->settings->default_wp_role ) ) {

					// The user was authenticated, but is not a member a role-granting group, and there is
					// no default role defined. Deny access.
					return new WP_Error(
						'user_not_assigned_to_group',
						sprintf(
							// translators: %s - user's UPN; typically an email address.
							__(
								'ERROR: Access denied. You\'re not a member of any group granting you access to this site. You\'re signed in as \'%s\'.',
								'aad-sso-wordpress'
							),
							$unique_name
						)
					);
				}

				/**
				 * Set up the required minimum user profile.  `user_pass` is set to a random password.
				 * The WordPress behavior for a null password is undocumented, so a new random password
				 * is recommended, per https://wordpress.stackexchange.com/questions/218350/user-password-field-is-empty
				 *
				 * TODO: use otherMail or proxyAddresses to set the user's email address.
				 *
				 * @see https://developer.wordpress.org/reference/functions/wp_insert_user/
				 */
				$userdata = array(
					'user_email' => $unique_name,
					'user_login' => $unique_name,
					'first_name' => ! empty( $jwt->given_name ) ? $jwt->given_name : '',
					'last_name'  => ! empty( $jwt->family_name ) ? $jwt->family_name : '',
					'user_pass'  => wp_generate_password(),
				);

				$new_user_id = wp_insert_user( $userdata );

				if ( is_wp_error( $new_user_id ) ) {
					// The user was authenticated, but not found in WP and auto-provisioning is disabled.
					return new WP_Error(
						'user_not_registered',
						sprintf(
							// translators: %s - user's UPN, typically an email address.
							__( 'ERROR: Error creating user \'%s\'.', 'aad-sso-wordpress' ),
							$unique_name
						)
					);
				} else {
					self::debug_log( 'Created new user: \'' . $unique_name . '\', user id ' . $new_user_id . '.', AADSSO_LOG_INFO );
					$user = new WP_User( $new_user_id );
				}
			} else {

				// The user was authenticated, but not found in WP and auto-provisioning is disabled.
				return new WP_Error(
					'user_not_registered',
					sprintf(
						// translators: %s - user's UPN, typically an email address.
						__(
							'ERROR: The authenticated user \'%s\' is not a registered user in this site.',
							'aad-sso-wordpress'
						),
						$unique_name
					)
				);
			}
		}

		return $user;
	}

	/**
	 * Sets a WordPress user's role based on their AAD group memberships
	 *
	 * @param WP_User $user the logged-in user.
	 * @param mixed   $group_memberships The response to the checkMemberGroups request.
	 *
	 * @return WP_User|WP_Error Return the WP_User with updated roles, or WP_Error if failed.
	 */
	public function update_wp_user_roles( $user, $group_memberships ) {

		// Determine which WordPress role the AAD group corresponds to.
		$roles_to_set = array();

		if ( ! empty( $group_memberships->value ) ) {
			foreach ( $this->settings->aad_group_to_wp_role_map as $aad_group => $wp_role ) {
				if ( in_array( $aad_group, $group_memberships->value, true ) ) {
					array_push( $roles_to_set, $wp_role );
				}
			}
		}

		if ( ! empty( $roles_to_set ) ) {
			$user->set_role( '' );
			foreach ( $roles_to_set as $role ) {
				$user->add_role( $role );
			}
			self::debug_log(
				sprintf(
					'Set roles [%s] for user [%s].',
					implode( ', ', $roles_to_set ),
					$user->ID
				),
				AADSSO_LOG_INFO
			);
		} elseif ( ! empty( $this->settings->default_wp_role ) ) {
			$user->set_role( $this->settings->default_wp_role );
			self::debug_log(
				sprintf(
					'Set default role [%s] for user [%s].',
					$this->settings->default_wp_role,
					$user->ID
				),
				AADSSO_LOG_INFO
			);
		} else {
			$error_message = sprintf(
				// translators: %s is the user's login id, such as bob or bob@contoso.com.
				__( 'ERROR: Azure AD user %s is not a member of any group granting a role.', 'aad-sso-wordpress' ),
				$user->user_login
			);
			self::debug_log( $error_message, AADSSO_LOG_ERROR );
			return new WP_Error( 'user_not_member_of_required_group', $error_message );
		}

		return $user;
	}

	/**
	 * Adds a link to the settings page.
	 *
	 * @param array $links The existing list of links.
	 *
	 * @return array The new list of links to display
	 */
	public function add_settings_link( $links ) {
		$link_to_settings = AADSSO_Html_Helper::get_tag(
			'a',
			array( 'href' => admin_url( 'options-general.php?page=aadsso_settings' ) ),
			esc_html_x( 'Settings', 'shortcut to settings page', 'aad-sso-wordpress' )
		);
		array_push( $links, $link_to_settings );
		return $links;
	}

	/**
	 * Generates the URL used to initiate a sign-in with Azure AD.
	 *
	 * @return string The authorization URL used to initiate a sign-in to Azure AD.
	 */
	public function get_login_url() {
		// Generate several nonces to be used as antiforgery_id.
		$passes = defined( 'AADSSO_NONCE_PASSES' )
			? filter_var( AADSSO_NONCE_PASSES, FILTER_VALIDATE_INT )
			: 3;

		// Generate at least one nonce.
		$passes = max( $passes, 1 );

		$nonces = array();
		for ( $i = 0; $i < $passes; $i++ ) {
			$nonces[] = wp_create_nonce( 'aadsso_authenticate_' . $i );
		}

		// implode the nonces without delimiter.
		$antiforgery_id = implode(
			'',
			$nonces
		);
		return AADSSO_Authorization_Helper::get_authorization_url( $this->settings, $antiforgery_id );
	}

	/**
	 * Generates the URL for logging out of Azure AD. (Does not log out of WordPress.)
	 */
	public function get_logout_url() {

		// logout_redirect_uri is not a required setting, use default value if none is set.
		$logout_redirect_uri = $this->settings->logout_redirect_uri;
		if ( empty( $logout_redirect_uri ) ) {
			$logout_redirect_uri = AADSSO_Settings::get_defaults( 'logout_redirect_uri' );
		}

		return $this->settings->end_session_endpoint
			. '?'
			. http_build_query(
				array( 'post_logout_redirect_uri' => $logout_redirect_uri )
			);
	}

	/**
	 * Starts a new session.
	 */
	public function register_session() {
		if ( ! session_id() ) {
			session_start();
		}
	}

	/**
	 * Clears the current the session (e.g. as part of logout).
	 */
	public function clear_session() {
		if ( session_id() ) {
			session_destroy();
		}
	}

	/**
	 * Clears the current the session, and triggers a full Azure AD logout if needed.
	 */
	public function logout() {

		$signed_in_with_azuread = isset( $_SESSION['aadsso_signed_in_with_azuread'] )
			&& true === $_SESSION['aadsso_signed_in_with_azuread'];
		$this->clear_session();

		if ( $signed_in_with_azuread && $this->settings->enable_full_logout ) {
			wp_safe_redirect( $this->get_logout_url() );
			die();
		}
	}

	/*** Settings ***/

	/**
	 * Add filters and actions for admin settings.
	 */
	public function setup_admin_settings() {
		if ( is_admin() ) {
			$azure_active_directory_settings = new AADSSO_Settings_Page();
		}
	}

	/**
	 * Renders the an admin notice when settings have been reset.
	 */
	public function print_settings_reset_notice() {
		echo '<div id="message" class="updated"><p>' . implode(
			' ',
			array(
				esc_html__( 'Single Sign-on with Azure Active Directory has been reset.', 'aad-sso-wordpress' ),
				esc_html__( 'Please unset the <code>AADSSO_RESET_SETTINGS</code> constant to reconfigure.', 'aad-sso-wordpress' ),
			)
		)
			. '</p></div>';
	}

	/**
	 * Renders the error message shown if this plugin is not correctly configured.
	 */
	public function print_plugin_not_configured() {
		echo '<div id="message" class="error"><p>'
			. esc_html__( 'Single Sign-on with Azure Active Directory is not configured.', 'aad-sso-wordpress' )
			. ' '
			. esc_html__( 'Please configure the plugin under Settings > Azure AD.', 'aad-sso-wordpress' )
			. '</p></div>';
	}

	/**
	 * Renders some debugging data.
	 */
	public function print_debug() {
		// phpcs:disable
		$current         = array();
		$constant_values = array();
		foreach ( AADSSO_Settings::get_defaults() as $key => $value ) {
			$current[ $key ] = $this->settings->{$key};
			$constant_key    = 'AADSSO_' . strtoupper( $key );
			if ( defined( $constant_key ) ) {
				$constant_values[ $constant_key ] = constant( $constant_key );
			}
		}

		$debugs = array(
			'SESSION'               => isset( $_SESSION ) ? $_SESSION : null,
			'GET'                   => isset( $_GET ) ? $_GET : null,
			'POST'                  => isset( $_POST ) ? $_POST : null,
			'plugin_dir_path'       => plugin_dir_path( __FILE__ ),
			'WP_PLUGIN_DIR'         => WP_PLUGIN_DIR,
			'WPMU_PLUGIN_DIR'       => WPMU_PLUGIN_DIR,
			'AADSSO_IS_WP_PLUGIN'   => AADSSO_IS_WP_PLUGIN,
			'AADSSO_IS_WPMU_PLUGIN' => AADSSO_IS_WPMU_PLUGIN,
			'CONSTANT settings'     => $constant_values,
			'DB settings'           => get_option( 'aadsso_settings' ),
			'DEFAULT settings'      => AADSSO_Settings::get_defaults(),
			'CURRENT settings'      => $current,
			'CURRENT json'          => wp_json_encode( $current ),
		);

		$debugs_string = array_map(
			function ( $key, $value ) {
				return AADSSO_Html_Helper::get_tag(
					'dt',
					array(),
					$key
				) . AADSSO_Html_Helper::get_tag(
					'dd',
					array(),
					// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export
					isset( $value )
						? AADSSO_Html_Helper::get_tag( 'pre', array(), esc_html( var_export( $value, true ) ) )
						: '<em>not set</em>'
				);
			},
			array_keys( $debugs ),
			$debugs
		);

		AADSSO_Html_Helper::tag( 'dl', array(), implode( '', $debugs_string ) );
		// phpcs:enable
	}

	/**
	 * Helper method to ensure get_plugin_data is available.
	 */
	public function get_plugin_data() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return get_plugin_data( AADSSO_PLUGIN_DIR . '/aad-sso-wordpress.php' );
	}

	/**
	 * Renders the CSS used by the HTML injected into the login page.
	 */
	public function print_login_css() {
		$plugin_data = $this->get_plugin_data();
		wp_enqueue_style( AADSSO, AADSSO_PLUGIN_URL . '/login.css', array(), $plugin_data['Version'] );
	}

	/**
	 * Renders the link used to initiate the login to Azure AD.
	 */
	public function print_login_link() {
		AADSSO_Html_Helper::tag(
			'p',
			array( 'class' => 'aadsso-login-form-text' ),
			AADSSO_Html_Helper::get_tag(
				'a',
				array( 'href' => $this->get_login_url() ),
				esc_html(
					sprintf(
						// translators: %s is the name of the organization the user expects to sign into.
						__( 'Sign in with your %s account', 'aad-sso-wordpress' ),
						$this->settings->org_display_name
					)
				)
			) . '<br>' . AADSSO_Html_Helper::get_tag(
				'a',
				array(
					'class' => 'dim',
					'href'  => $this->get_logout_url(),
				),
				__( 'Sign out', 'aad-sso-wordpress' )
			)
		);
	}

	/**
	 * Emits debug details to the logs.
	 *
	 * @param string $message The message to log.
	 * @param int    $level The level of the message. 0 is the default, and the higher the number the more verbose.
	 */
	public static function debug_log( $message, $level = AADSSO_LOG_FATAL ) {
		/**
		 * Fire an action when logging.
		 *
		 * This allows external services to tie into these logs. We're adding it here so this can be used in prod for services such as Stream
		 *
		 * @since 0.6.2
		 *
		 * @param string $message The message being logged.
		 */
		do_action( 'aadsso_debug_log', $message );

		/**
		 * Allow other plugins or themes to set the debug status of this plugin.
		 *
		 * @since 0.6.3
		 * @param bool The current debug status.
		 */
		$debug_enabled = apply_filters( 'aadsso_debug', AADSSO_DEBUG );

		/**
		 * Allow other plugins or themes to set the debug level
		 *
		 * @since 0.6.3
		 * @param int
		 */
		$debug_level = apply_filters( 'aadsso_debug_level', AADSSO_LOG_LEVEL );

		if ( true === $debug_enabled && $debug_level >= $level ) {
			if ( false === strpos( $message, "\n" ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions
				error_log( 'AADSSO: ' . $message );
			} else {
				$lines = explode( "\n", str_replace( "\r\n", "\n", $message ) );
				foreach ( $lines as $line ) {
					self::debug_log( $line, $level );
				}
			}
		}
	}

	/**
	 * Prints the debug backtrace using this class' debug_log function.
	 *
	 * @param int $level The level of the message. 0 is the default, and the higher the number the more verbose.
	 */
	public static function debug_print_backtrace( $level = AADSSO_LOG_ERROR ) {
		ob_start();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions
		debug_print_backtrace();
		$trace = ob_get_contents();
		ob_end_clean();
		self::debug_log( $trace, $level );
	}
}
