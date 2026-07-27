<?php

/*
Plugin Name: Single Sign-on with Microsoft Entra ID
Plugin URI: http://github.com/psignoret/aad-sso-wordpress
Description: Allows you to use your organization's Microsoft Entra ID (formerly known as Azure Active Directory) user accounts to log in to WordPress. If your organization is using Office 365, your user accounts are already in Microsoft Entra ID. This plugin uses OAuth 2.0 to authenticate users, and the Microsoft Graph API to get group membership and other details.
Author: Philippe Signoret
Version: 0.11.4
Requires PHP: 5.6
Author URI: https://www.psignoret.com/
Text Domain: aad-sso-wordpress
Domain Path: /languages/
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

define( 'AADSSO', 'aad-sso-wordpress' );
define( 'AADSSO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AADSSO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

defined( 'AADSSO_DEBUG' ) or define( 'AADSSO_DEBUG', FALSE );
defined( 'AADSSO_DEBUG_LEVEL' ) or define( 'AADSSO_DEBUG_LEVEL', 0 );

// Proxy to be used for calls, should be useful for tracing with Fiddler
// BUGBUG: Doesn't actually work, at least not with WP running on WAMP stack
//define( 'WP_PROXY_HOST', '127.0.0.1' );
//define( 'WP_PROXY_PORT', '8888' );

require_once AADSSO_PLUGIN_DIR . '/Settings.php';
require_once AADSSO_PLUGIN_DIR . '/CredentialHelper.php';
require_once AADSSO_PLUGIN_DIR . '/SettingsPage.php';
require_once AADSSO_PLUGIN_DIR . '/AuthorizationHelper.php';
require_once AADSSO_PLUGIN_DIR . '/GraphHelper.php';
require_once AADSSO_PLUGIN_DIR . '/PhotoHelper.php';

// TODO: Auto-load the ( the exceptions at least )
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/src/JWT.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/src/BeforeValidException.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/src/ExpiredException.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/src/SignatureInvalidException.php';

//define ('AADSSO_DEBUG', true);

class AADSSO {

	static $instance = FALSE;

	private $settings = null;

	public function __construct( $settings ) {
		$this->settings = $settings;

		// Setup the admin settings page
		$this->setup_admin_settings();

		// Some debugging locations
		//add_action( 'admin_notices', array( $this, 'print_debug' ) );
		//add_action( 'login_footer', array( $this, 'print_debug' ) );

		// Add a link to the Settings page in the list of plugins
		add_filter(
			'plugin_action_links_' . plugin_basename( __FILE__ ),
			array( $this, 'add_settings_link' )
		);

		// Register activation and deactivation hooks
		register_activation_hook( __FILE__, array( 'AADSSO', 'activate' ) );
		register_deactivation_hook( __FILE__, array( 'AADSSO', 'deactivate' ) );

		// If plugin is not configured, we shouldn't proceed.
		if ( ! $this->plugin_is_configured() ) {
			add_action( 'all_admin_notices', array( $this, 'print_plugin_not_configured' ) );
			return;
		}

		// Add the hook that starts the SESSION
		add_action( 'login_init', array( $this, 'register_session' ), 10 );

		// The authenticate filter
		add_filter( 'authenticate', array( $this, 'authenticate' ), 1, 3 );

		// Add the <style> element to the login page
		add_action( 'login_enqueue_scripts', array( $this, 'print_login_css' ) );

		// Add the link to the organization's sign-in page
		add_action( 'login_form', array( $this, 'print_login_link' ) ) ;

		// Clear session variables when logging out
		add_action( 'wp_logout', array( $this, 'logout' ) );

		// If configured, bypass the login form and redirect straight to AAD
		add_action( 'login_init', array( $this, 'save_redirect_and_maybe_bypass_login' ), 20 );

		// Redirect user back to original location
		add_filter( 'login_redirect', array( $this, 'redirect_after_login' ), 20, 3 );

		// Register the textdomain for localization after all plugins are loaded
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		// Allow users to refresh their own Microsoft Graph profile photo.
		add_action( 'admin_post_aadsso_sync_my_photo', array( $this, 'start_manual_photo_sync' ) );
		add_action( 'show_user_profile', array( $this, 'render_core_profile_photo_sync' ) );
		add_filter( 'um_profile_tabs', array( $this, 'add_um_photo_sync_tab' ), 1000 );
		add_action(
			'um_profile_content_aadsso_photo_sync',
			array( $this, 'render_um_profile_photo_sync' )
		);

		// Continue displaying already synchronized local avatars even if future sync is disabled.
		add_filter( 'pre_get_avatar_data', array( 'AADSSO_PhotoHelper', 'filter_avatar_data' ), 999, 2 );
		add_filter( 'get_avatar_url', array( 'AADSSO_PhotoHelper', 'filter_avatar_url' ), 999, 3 );
		add_filter( 'get_avatar', array( 'AADSSO_PhotoHelper', 'filter_avatar_html' ), 999, 5 );
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
	public static function deactivate() { }

	/**
	 * Load the textdomain for localization.
	 */
	public function load_textdomain()
	{
		load_plugin_textdomain(
			'aad-sso-wordpress',
			false, // deprecated
			dirname( plugin_basename( __FILE__ ) ) . '/languages/'
		);
	}

	/**
	 * Determine if required plugin settings are stored.
	 *
	 * @return bool Whether plugin is configured
	 */
	public function plugin_is_configured() {
		$has_client_credential =
			( 'certificate' === $this->settings->client_auth_method
				&& ! empty( $this->settings->client_certificate )
				&& ! empty( $this->settings->client_private_key_encrypted ) )
			|| ( 'secret' === $this->settings->client_auth_method
				&& ! empty( $this->settings->client_secret ) );

		return
			   ! empty( $this->settings->client_id )
			&& $has_client_credential
			&& ! empty( $this->settings->redirect_uri )
		;
	}

	/**
	 * Gets the (only) instance of the plugin. Initializes an instance if it hasn't yet.
	 *
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
		 * redirect to Microsoft Entra ID, displaying the login form instead. This check is intentionally
		 * done after the 'aad_auto_forward_login' filter is applied, to ensure it also overrides
		 * any filters.
		 */ 
		if ( isset( $_GET['aadsso_no_redirect'] ) ) {
			AADSSO::debug_log( 'Skipping automatic redirects to Microsoft Entra ID.' );
			$auto_redirect = FALSE;
		}

		/*
		 * If the user is attempting to log out AND the auto-forward to AAD
		 * login is set then we need to ensure we do not auto-forward the user and get
		 * them stuck in an infinite logout loop.
		 */
		if( $this->wants_to_login() ) {

			// Save the redirect_to query param ( if present ) to session
			if ( isset( $_GET['redirect_to'] ) ) {
				$_SESSION['aadsso_redirect_to'] = $_GET['redirect_to'];
			}

			/*
			 * $_POST['log'] is set when the login form is submitted. It's important to check
			 * for this condition also because we want to allow the login form to be usable
			 * when the 'aadsso_no_redirect' anti-lockout option is used.
			 */
			if ( $auto_redirect && ! isset( $_GET['code'] ) && ! isset( $_POST['log'] ) ) {
				wp_redirect( $this->get_login_url() );
				die();
			}
		}
	}

	/**
	 * Restores the session variable that stored the original 'redirect_to' so that after
	 * authenticating with AAD, the user is returned to the right place.
	 *
	 * @param string $redirect_to
	 * @param string $requested_redirect_to
	 * @param WP_User|WP_Error $user
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
		$wants_to_login = false;
		// Cover default WordPress behavior
		$action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : 'login';
		// And now the exceptions
		$action = isset( $_GET['loggedout'] ) ? 'loggedout' : $action;
		if( 'login' == $action ) {
			$wants_to_login = true;
		}
		return $wants_to_login;
	}

	/**
	 * Authenticates the user with Microsoft Entra ID and WordPress.
	 *
	 * This method, invoked as an 'authenticate' filter, implements the OpenID Connect
	 * Authorization Code Flow grant to sign the user in to Microsoft Entra ID (if they aren't already),
	 * obtain an ID Token to identify the current user, and obtain an Access Token to access
	 * the Microsoft Graph API.
	 *
	 * @param WP_User|WP_Error $user A WP_User, if the user has already authenticated.
	 * @param string $username The username provided during form-based signing. Not used.
	 * @param string $password The password provided during form-based signing. Not used.
	 *
	 * @return WP_User|WP_Error The authenticated WP_User, or a WP_Error if there were errors.
	 */
	function authenticate( $user, $username, $password ) {

		// Don't re-authenticate if already authenticated
		if ( is_a( $user, 'WP_User' ) ) { return $user; }

		/* If 'code' is present, this is the Authorization Response from Microsoft Entra ID, and 'code' has
		 * the Authorization Code, which will be exchanged for an ID Token and an Access Token.
		 */
		if ( isset( $_GET['code'] ) ) {

			if ( ! isset( $_SESSION['aadsso_antiforgery-id'] ) ) {
				$this->clear_pending_photo_sync_request();
				return new WP_Error(
					'missing_antiforgery_id',
					__( 'Session does not contain antiforgery ID.', 'aad-sso-wordpress')
				);
			}

			$antiforgery_id = $_SESSION['aadsso_antiforgery-id'];
			$state_is_missing = ! isset( $_GET['state'] );
			$state_doesnt_match = $_GET['state'] != $antiforgery_id;

			if ( $state_is_missing || $state_doesnt_match ) {
				$this->clear_pending_photo_sync_request();
				return new WP_Error(
					'antiforgery_id_mismatch',
					sprintf( __( 'ANTIFORGERY_ID mismatch. Expecting %s', 'aad-sso-wordpress' ), $antiforgery_id )
				);
			}

			// Looks like we got a valid authorization code, let's try to get an access token with it
			$code_verifier = isset( $_SESSION['aadsso_pkce_verifier'] )
				? $_SESSION['aadsso_pkce_verifier'] : '';
			unset( $_SESSION['aadsso_pkce_verifier'] );
			$token = AADSSO_AuthorizationHelper::get_access_token(
				$_GET['code'],
				$this->settings,
				$code_verifier
			);

			// Happy path
			if ( isset( $token->access_token ) ) {

				try {
					$jwt = AADSSO_AuthorizationHelper::validate_id_token(
						$token->id_token,
						$this->settings,
						$antiforgery_id
					);

					$object_id = isset( $jwt->oid ) ? $jwt->oid : '';
					AADSSO::debug_log( 'ID Token: iss: \'' . $jwt->iss . '\', oid: \'' . $object_id, 10 );
					AADSSO::debug_log( json_encode( $jwt ), 50 );

				} catch ( Exception $e ) {
					$this->clear_pending_photo_sync_request();
					return new WP_Error(
						'invalid_id_token',
						sprintf( __( 'ERROR: Invalid id_token. %s', 'aad-sso-wordpress' ), $e->getMessage() )
					);
				}

				// Retrieve group membership details, if needed
				$group_memberships = false;
				if ( true === $this->settings->enable_aad_group_to_wp_role ) {

					// If we're mapping Microsoft Entra ID groups to WordPress roles, make the Graph API call here
					AADSSO_GraphHelper::$settings  = $this->settings;

					// Of the AAD groups defined in the settings, get only those where the user is a member
					$group_ids         = array_keys( $this->settings->aad_group_to_wp_role_map );
					$group_memberships = AADSSO_GraphHelper::user_check_member_groups( 'me', $group_ids );
					
					// Validate response to throw an early error if unable to check group membership.
					if ( isset( $group_memberships->value ) ) {
						AADSSO::debug_log( sprintf(
							'Microsoft Entra ID user \'%s\' is a member of [%s]',
							$object_id, implode( ',', $group_memberships->value ) ), 20
						);
					} elseif ( isset ( $group_memberships->error ) ) {
						AADSSO::debug_log( 'Error when checking group membership: ' . json_encode( $group_memberships ) );
						$this->clear_pending_photo_sync_request();
						return new WP_Error(
							'error_checking_group_membership',
							sprintf(
								__( 'ERROR: Unable to check group membership with Microsoft Graph: '
									. '<b>%s</b> %s<br />%s', 'aad-sso-wordpress' ),
								$group_memberships->error->code, 
								$group_memberships->error->message,
								json_encode( $group_memberships->error->innerError )
							)
						);
					} else {
						AADSSO::debug_log( 'Unexpected response to checkMemberGroups: ' . json_encode( $group_memberships ) );
						$this->clear_pending_photo_sync_request();
						return new WP_Error(
							'unexpected_response_to_checkMemberGroups',
							__( 'ERROR: Unexpected response when checking group membership with Microsoft Graph.', 
								'aad-sso-wordpress' )
						);
					}
				}

				// Invoke any configured matching and auto-provisioning strategy and get the user. We include
				// group membership details in case they're needed to decide whether or not to create the user.
				$user = $this->get_wp_user_from_aad_user( $jwt, $group_memberships );

				if ( is_a( $user, 'WP_User' ) ) {

					// At this point, we have an authorization code, an access token and the user
					// exists in WordPress (either because it already existed, or we created it
					// on-the-fly). All that's left is to set the roles based on group membership.
					// 4. If a user was created or found above, we can pass the groups here to have them assigned normally
					if ( true === $this->settings->enable_aad_group_to_wp_role ) {
						$user = $this->update_wp_user_roles( $user, $group_memberships );
					}
					if ( is_a( $user, 'WP_User' ) ) {
						$user = $this->maybe_sync_profile_photo_after_authentication( $user );
					}
				}
			} elseif ( isset( $token->error ) ) {

				// Unable to get an access token ( although we did get an authorization code )
				$this->clear_pending_photo_sync_request();
				return new WP_Error(
					$token->error,
					sprintf(
						__( 'ERROR: Could not get an access token to Microsoft Graph. %s', 'aad-sso-wordpress' ),
						$token->error_description
					)
				);
			} else {

				// None of the above, I have no idea what happened.
				$this->clear_pending_photo_sync_request();
				return new WP_Error( 'unknown', __( 'ERROR: An unknown error occured.', 'aad-sso-wordpress' ) );
			}

		} elseif ( isset( $_GET['error'] ) ) {

			// The attempt to get an authorization code failed.
			$this->clear_pending_photo_sync_request();
			return new WP_Error(
				$_GET['error'],
				sprintf(
					__( 'ERROR: Access denied to Microsoft Graph. %s', 'aad-sso-wordpress' ),
					$_GET['error_description']
				)
			);
		}

		if ( is_a( $user, 'WP_User' ) ) {
			$_SESSION['aadsso_signed_in_with_azuread'] = true;
		}

		return $user;
	}

	/**
	 * Clears a queued manual photo refresh when the Entra round trip cannot complete.
	 */
	private function clear_pending_photo_sync_request() {
		unset( $_SESSION['aadsso_force_photo_sync'], $_SESSION['aadsso_photo_sync_user_id'] );
	}

	/**
	 * Synchronizes a missing photo during login, or force-refreshes one after a profile request.
	 *
	 * Photo failures never block a normal login. A manual request is rejected if the Entra
	 * identity maps to a different WordPress account than the user who requested the refresh.
	 *
	 * @param WP_User $user Authenticated WordPress user.
	 *
	 * @return WP_User|WP_Error User, or an identity mismatch error.
	 */
	private function maybe_sync_profile_photo_after_authentication( $user ) {
		$is_manual_sync = ! empty( $_SESSION['aadsso_force_photo_sync'] );
		$requested_user_id = isset( $_SESSION['aadsso_photo_sync_user_id'] )
			? (int) $_SESSION['aadsso_photo_sync_user_id'] : 0;
		$this->clear_pending_photo_sync_request();

		if ( $is_manual_sync && $requested_user_id !== (int) $user->ID ) {
			return new WP_Error(
				'photo_sync_identity_mismatch',
				__( 'Photo sync was cancelled because the Microsoft account does not match the current WordPress user.', 'aad-sso-wordpress' )
			);
		}

		if ( true !== $this->settings->enable_profile_photo_sync ) {
			if ( $is_manual_sync ) {
				$this->store_photo_sync_result(
					$user->ID,
					'warning',
					__( 'Microsoft Graph profile photo sync is disabled for this site.', 'aad-sso-wordpress' )
				);
			}
			return $user;
		}

		AADSSO_GraphHelper::$settings = $this->settings;
		$result = AADSSO_PhotoHelper::sync_current_user_photo( $user->ID, $is_manual_sync );
		if ( $is_manual_sync ) {
			if ( true === $result ) {
				$this->store_photo_sync_result(
					$user->ID,
					'success',
					__( 'Your profile photo was synchronized from Microsoft.', 'aad-sso-wordpress' )
				);
			} else {
				$this->store_photo_sync_result(
					$user->ID,
					'warning',
					$result->get_error_message()
				);
			}
		} elseif ( is_wp_error( $result )
			&& ! in_array( $result->get_error_code(), array( 'photo_exists', 'graph_photo_not_found' ), true )
		) {
			AADSSO::debug_log(
				'Automatic profile photo sync failed for user ' . (int) $user->ID
				. ': ' . $result->get_error_message()
			);
		}

		return $user;
	}

	/**
	 * Stores a short-lived result for display after the SSO round trip.
	 */
	private function store_photo_sync_result( $user_id, $type, $message ) {
		set_transient(
			'aadsso_photo_sync_result_' . (int) $user_id,
			array(
				'type' => $type,
				'message' => $message,
			),
			120
		);
	}

	function get_wp_user_from_aad_user( $jwt, $group_memberships ) {

		// Try to find an existing user in WP where the upn or unique_name of the current Microsoft Entra ID user is
		// (depending on config) the 'login' or 'email' field in WordPress
		$unique_name = isset( $jwt->preferred_username )
			? $jwt->preferred_username
			: ( isset( $jwt->upn )
				? $jwt->upn
				: ( isset( $jwt->unique_name ) ? $jwt->unique_name : null ) );
		if ( null === $unique_name ) {
			return new WP_Error(
				'unique_name_not_found',
				__( 'ERROR: None of \'preferred_username\', \'upn\', or \'unique_name\' were found in the ID Token.',
					'aad-sso-wordpress' )
			);
		}

		$user = get_user_by( $this->settings->field_to_match_to_upn, $unique_name );

		if ( true === $this->settings->match_on_upn_alias ) {
			if ( ! is_a( $user, 'WP_User' ) ) {
				$username = explode( sprintf( '@%s', $this->settings->org_domain_hint ), $unique_name );
				$user = get_user_by( $this->settings->field_to_match_to_upn, $username[0] );
			}
		}

		if ( is_a( $user, 'WP_User' ) ) {
			AADSSO::debug_log( sprintf(
				'Matched Microsoft Entra ID user [%s] to existing WordPress user [%s].', $unique_name, $user->ID ), 10 );
		} else {

			// Since the user was authenticated with Microsoft Entra ID, but not found in WordPress,
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
							__( 'ERROR: Access denied. You\'re not a member of any group granting you '
							    . 'access to this site. You\'re signed in as \'%s\'.',
							'aad-sso-wordpress' ),
							$unique_name
						)
					);
				}

				// Setup the minimum required user data
				// TODO: Is null better than a random password?
				// TODO: Look for otherMail, or proxyAddresses before UPN for email
				$userdata = array(
					'user_email' => $unique_name,
					'user_login' => $unique_name,
					'first_name' => ! empty( $jwt->given_name ) ? $jwt->given_name : '',
					'last_name'  => ! empty( $jwt->family_name ) ? $jwt->family_name : '',
					'user_pass'  => null,
				);

				$new_user_id = wp_insert_user( $userdata );

				if ( is_wp_error( $new_user_id ) ) {
					// The user was authenticated, but not found in WP and auto-provisioning is disabled
					return new WP_Error(
						'user_not_registered',
						sprintf(
							__( 'ERROR: Error creating user \'%s\'.', 'aad-sso-wordpress' ),
							$unique_name
						)
					);
				} else {
					AADSSO::debug_log( 'Created new user: \'' . $unique_name . '\', user id ' . $new_user_id . '.' );
					$user = new WP_User( $new_user_id );
				}
			} else {

				// The user was authenticated, but not found in WP and auto-provisioning is disabled
				return new WP_Error(
					'user_not_registered',
					sprintf(
						__( 'ERROR: The authenticated user \'%s\' is not a registered user in this site.', 
						    'aad-sso-wordpress' ),
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
		* @param WP_User $user
		* @param mixed   $group_memberships The response to the checkMemberGroups request.
		*
		* @return WP_User|WP_Error Return the WP_User with updated roles, or WP_Error if failed.
		*/
	function update_wp_user_roles( $user, $group_memberships ) {

		// Determine which WordPress role the AAD group corresponds to.
		$roles_to_set = array();

		if ( ! empty( $group_memberships->value ) ) {
			foreach ( $this->settings->aad_group_to_wp_role_map as $aad_group => $wp_role ) {
				if ( in_array( $aad_group, $group_memberships->value ) ) {
					array_push( $roles_to_set, $wp_role );
				}
			}
		}

		if ( ! empty( $roles_to_set ) ) {
			$user->set_role( '' );
			foreach ( $roles_to_set as $role ) {
				$user->add_role( $role );
			}
			AADSSO::debug_log( sprintf(
				'Set roles [%s] for user [%s].', implode( ', ', $roles_to_set ), $user->ID ), 10 );
		} else if ( ! empty( $this->settings->default_wp_role ) ) {
			$user->set_role( $this->settings->default_wp_role );
			AADSSO::debug_log( sprintf( 
				'Set default role [%s] for user [%s].', $this->settings->default_wp_role, $user->ID ), 10 );
		} else {
			$error_message = sprintf(
				__( 'ERROR: Microsoft Entra ID user %s is not a member of any group granting a role.', 'aad-sso-wordpress' ),
				$aad_user_id
			);
			AADSSO::debug_log( $error_message, 10 );
			return new WP_Error( 'user_not_member_of_required_group', $error_message );
		}

		return $user;
	}

	/**
	 * Adds a link to the settings page.
	 *
	 * @param array $links The existing list of links
	 *
	 * @return array The new list of links to display
	 */
	function add_settings_link( $links ) {
		$link_to_settings =
			'<a href="' . admin_url( 'options-general.php?page=aadsso_settings' ) . '">Settings</a>';
		array_push( $links, $link_to_settings );
		return $links;
	}

	/**
	 * Generates the URL used to initiate a sign-in with Microsoft Entra ID.
	 *
	 * @return string The authorization URL used to initiate a sign-in to Microsoft Entra ID.
	 */
	function get_login_url() {
		$antiforgery_id = AADSSO_AuthorizationHelper::generate_pkce_verifier();
		$_SESSION['aadsso_antiforgery-id'] = $antiforgery_id;
		$code_verifier = AADSSO_AuthorizationHelper::generate_pkce_verifier();
		$_SESSION['aadsso_pkce_verifier'] = $code_verifier;
		$code_challenge = AADSSO_AuthorizationHelper::get_pkce_challenge( $code_verifier );
		return AADSSO_AuthorizationHelper::get_authorization_url(
			$this->settings,
			$antiforgery_id,
			$code_challenge
		);
	}

	/**
	 * Starts an Entra authorization round trip to force-refresh the current user's photo.
	 */
	public function start_manual_photo_sync() {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		check_admin_referer( 'aadsso_sync_my_photo' );

		if ( true !== $this->settings->enable_profile_photo_sync ) {
			wp_die(
				esc_html__( 'Microsoft Graph profile photo sync is disabled for this site.', 'aad-sso-wordpress' )
			);
		}

		$user_id = get_current_user_id();
		$this->register_session();
		$_SESSION['aadsso_force_photo_sync'] = true;
		$_SESSION['aadsso_photo_sync_user_id'] = $user_id;

		$fallback_url = admin_url( 'profile.php' );
		$return_url = wp_get_referer();
		$return_url = wp_validate_redirect( $return_url, $fallback_url );
		$_SESSION['aadsso_redirect_to'] = $return_url;

		wp_safe_redirect( $this->get_login_url() );
		exit;
	}

	/**
	 * Adds the manual synchronization control to the current user's WordPress profile.
	 *
	 * @param WP_User $profile_user User whose profile is being rendered.
	 */
	public function render_core_profile_photo_sync( $profile_user ) {
		if ( true !== $this->settings->enable_profile_photo_sync
			|| get_current_user_id() !== (int) $profile_user->ID
		) {
			return;
		}

		echo '<h2>' . esc_html__( 'Microsoft profile photo', 'aad-sso-wordpress' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tr><th>'
			. esc_html__( 'Profile photo sync', 'aad-sso-wordpress' )
			. '</th><td>';
		$this->render_photo_sync_result( $profile_user->ID );
		$this->render_photo_sync_button( $profile_user->ID );
		echo '</td></tr></table>';
	}

	/**
	 * Adds an owner-only Ultimate Member profile tab.
	 *
	 * @param array $tabs Ultimate Member profile tabs.
	 *
	 * @return array Updated tabs.
	 */
	public function add_um_photo_sync_tab( $tabs ) {
		if ( true !== $this->settings->enable_profile_photo_sync || ! is_user_logged_in() ) {
			return $tabs;
		}
		$tabs['aadsso_photo_sync'] = array(
			'name' => __( 'Microsoft Photo', 'aad-sso-wordpress' ),
			'icon' => 'um-faicon-camera',
			'custom' => true,
			'default_privacy' => 3,
		);
		return $tabs;
	}

	/**
	 * Renders the owner-only Ultimate Member photo synchronization tab.
	 */
	public function render_um_profile_photo_sync() {
		$profile_user_id = function_exists( 'um_profile_id' )
			? (int) um_profile_id() : get_current_user_id();
		if ( true !== $this->settings->enable_profile_photo_sync
			|| get_current_user_id() !== $profile_user_id
		) {
			return;
		}

		echo '<div class="aadsso-photo-sync-profile">';
		echo '<h3>' . esc_html__( 'Microsoft profile photo', 'aad-sso-wordpress' ) . '</h3>';
		$this->render_photo_sync_result( $profile_user_id );
		$this->render_photo_sync_button( $profile_user_id );
		echo '</div>';
	}

	/**
	 * Renders a nonce-protected manual synchronization link and status.
	 */
	private function render_photo_sync_button( $user_id ) {
		$sync_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=aadsso_sync_my_photo' ),
			'aadsso_sync_my_photo'
		);
		echo '<p><a class="button" href="' . esc_url( $sync_url ) . '">'
			. esc_html__( 'Sync latest photo from Microsoft', 'aad-sso-wordpress' )
			. '</a></p>';
		echo '<p class="description">'
			. esc_html__( 'This reconnects to Microsoft and replaces your local profile photo with the latest available image.', 'aad-sso-wordpress' )
			. '</p>';

		$synced_at = get_user_meta( $user_id, AADSSO_PhotoHelper::SYNCED_AT_META_KEY, true );
		if ( $synced_at ) {
			echo '<p class="description">'
				. esc_html__( 'Last synchronized:', 'aad-sso-wordpress' ) . ' '
				. esc_html( $synced_at )
				. '</p>';
		}
	}

	/**
	 * Displays and consumes a short-lived photo synchronization result.
	 */
	private function render_photo_sync_result( $user_id ) {
		$transient_key = 'aadsso_photo_sync_result_' . (int) $user_id;
		$result = get_transient( $transient_key );
		if ( ! is_array( $result ) || empty( $result['message'] ) ) {
			return;
		}
		delete_transient( $transient_key );
		$type = isset( $result['type'] )
			&& in_array( $result['type'], array( 'success', 'warning', 'error', 'info' ), true )
				? $result['type'] : 'info';
		printf(
			'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $result['message'] )
		);
	}

	/**
	 * Generates the URL for logging out of Microsoft Entra ID. (Does not log out of WordPress.)
	 */
	function get_logout_url() {

		// logout_redirect_uri is not a required setting, use default value if none is set
		$logout_redirect_uri = $this->settings->logout_redirect_uri;
		if ( empty( $logout_redirect_uri ) ) {
			$logout_redirect_uri = AADSSO_Settings::get_defaults('logout_redirect_uri');
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
	function register_session() {
		if ( ! session_id() ) {
			session_start();
		}
	}

	/**
	 * Clears the current the session (e.g. as part of logout).
	 */
	function clear_session() {
		if ( session_id() ) {
			session_destroy();
		}
	}

	/**
	 * Clears the current the session, and triggers a full Microsoft Entra ID logout if needed.
	 */
	function logout() {

		$signed_in_with_azuread = isset( $_SESSION['aadsso_signed_in_with_azuread'] ) 
									&& true === $_SESSION['aadsso_signed_in_with_azuread'];
		$this->clear_session();

		if ( $signed_in_with_azuread && $this->settings->enable_full_logout ) {
			wp_redirect( $this->get_logout_url() );
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


	/*** View ***/

	/**
	 * Renders the error message shown if this plugin is not correctly configured.
	 */
	function print_plugin_not_configured() {
		echo '<div id="message" class="error"><p>'
		. __( 'Single Sign-on with Microsoft Entra ID required settings are not defined. '
		      . 'Update them under Settings > Microsoft Entra ID.', 'aad-sso-wordpress' )
		      .'</p></div>';
	}

	/**
	 * Renders some debugging data.
	 */
	function print_debug() {
		echo '<p>SESSION</p><pre>' . var_export( $_SESSION, TRUE ) . '</pre>';
		echo '<p>GET</pre><pre>' . var_export( $_GET, TRUE ) . '</pre>';
		echo '<p>Database settings</p><pre>' .var_export( get_option( 'aadsso_settings' ), true ) . '</pre>';
		echo '<p>Plugin settings</p><pre>' . var_export( $this->settings, true ) . '</pre>';
	}

	/**
	 * Renders the CSS used by the HTML injected into the login page.
	 */
	function print_login_css() {
		wp_enqueue_style( AADSSO, AADSSO_PLUGIN_URL . '/login.css' );
	}

	/**
	 * Renders the link used to initiate the login to Microsoft Entra ID.
	 */
	function print_login_link() {
		$html = '<p class="aadsso-login-form-text">';
		$html .= '<a href="%s">';
		$html .= sprintf( __( 'Sign in with your %s account', 'aad-sso-wordpress' ),
		                  htmlentities( $this->settings->org_display_name ) );
		$html .= '</a><br /><a class="dim" href="%s">'
		         . __( 'Sign out', 'aad-sso-wordpress' ) . '</a></p>';
		printf(
			$html,
			$this->get_login_url(),
			$this->get_logout_url()
		);
	}

	/**
	 * Emits debug details to the logs. The higher the level, the more verbose.
	 *
	 * If there are multiple lines in the message, they will each be emitted as a log line.
	 */
	public static function debug_log( $message, $level = 0 ) {
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
		 * @since 0.6.3
		 * @param int
		 */
		$debug_level = apply_filters( 'aadsso_debug_level', AADSSO_DEBUG_LEVEL );


		if ( true === $debug_enabled && $debug_level >= $level ) {
			if ( false === strpos( $message, "\n" ) ) {
				error_log( 'AADSSO: ' . $message );
			} else {
				$lines = explode( "\n", str_replace( "\r\n", "\n", $message ) );
				foreach ( $lines as $line ) {
					AADSSO::debug_log( $line, $level );
				}
			}
		}
	}

	/**
	 * Prints the debug backtrace using this class' debug_log function.
	 */
	public static function debug_print_backtrace( $level = 10 ) {
		ob_start();
		debug_print_backtrace();
		$trace = ob_get_contents();
		ob_end_clean();
		self::debug_log( $trace, $level );
	}
}

/*** Utility functions ***/

if ( ! function_exists( 'com_create_guid' ) ) {
	/**
	 * Generates a globally unique identifier ( Guid ).
	 *
	 * @return string A new random globally unique identifier.
	 */
	function com_create_guid() {
		mt_srand( (int)( (float) microtime() * 10000 ) );
		$charid = strtoupper( md5( uniqid( rand(), true ) ) );
		$hyphen = chr( 45 ); // "-"
		$uuid = chr( 123 ) // "{"
			.substr( $charid, 0, 8 ) . $hyphen
			.substr( $charid, 8, 4 ) . $hyphen
			.substr( $charid, 12, 4 ) . $hyphen
			.substr( $charid, 16, 4 ) . $hyphen
			.substr( $charid, 20, 12 )
			.chr( 125 ); // "}"
		return $uuid;
	}
}

// Load settings JSON contents from DB and initialize the plugin
$aadsso_settings_instance = AADSSO_Settings::init();
$aadsso = AADSSO::get_instance( $aadsso_settings_instance, com_create_guid() );
