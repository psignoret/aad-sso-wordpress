<?php

/*
Plugin Name: Single Sign-on with Azure Active Directory
Plugin URI: http://github.com/psignoret/aad-sso-wordpress
Description: Allows you to use your organization's Azure Active Directory user accounts to log in to WordPress. If your organization is using Office 365, your user accounts are already in Azure Active Directory. This plugin uses OAuth 2.0 to authenticate users, and the Azure Active Directory Graph to get group membership and other details.
Author: Philippe Signoret
Version: 0.6.3
Author URI: https://www.psignoret.com/
Text Domain: aad-sso-wordpress
Domain Path: /languages/
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

define( 'AADSSO', 'aad-sso-wordpress' );
define( 'AADSSO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AADSSO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

defined( 'AADSSO_DEBUG' ) or define( 'AADSSP_DEBUG', FALSE );
defined( 'AADSSO_DEBUG_LEVEL' ) or define( 'AADSSO_DEBUG_LEVEL', 0 );

// Proxy to be used for calls, should be useful for tracing with Fiddler
// BUGBUG: Doesn't actually work, at least not with WP running on WAMP stack
//define( 'WP_PROXY_HOST', '127.0.0.1' );
//define( 'WP_PROXY_PORT', '8888' );

require_once AADSSO_PLUGIN_DIR . '/Settings.php';
require_once AADSSO_PLUGIN_DIR . '/SettingsPage.php';
require_once AADSSO_PLUGIN_DIR . '/AuthorizationHelper.php';
require_once AADSSO_PLUGIN_DIR . '/GraphHelper.php';

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
		add_action( 'wp_logout', array( $this, 'clear_session' ) );

		// If configured, bypass the login form and redirect straight to AAD
		add_action( 'login_init', array( $this, 'save_redirect_and_maybe_bypass_login' ), 20 );

		// Redirect user back to original location
		add_filter( 'login_redirect', array( $this, 'redirect_after_login' ), 20, 3 );

		// Register the textdomain for localization after all plugins are loaded
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
		return
			   ! empty( $this->settings->client_id )
			&& ! empty( $this->settings->client_secret )
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
		 * redirect to Azure AD, displaying the login form instead. This check is intentionally
		 * done after the 'aad_auto_forward_login' filter is applied, to ensure it also overrides
		 * any filters.
		 */ 
		if ( isset( $_GET['aadsso_no_redirect'] ) ) {
			AADSSO::debug_log( 'Skipping automatic redirects to Azure AD.' );
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
	 * Authenticates the user with Azure AD and WordPress.
	 *
	 * This method, invoked as an 'authenticate' filter, implements the OpenID Connect
	 * Authorization Code Flow grant to sign the user in to Azure AD (if they aren't already),
	 * obtain an ID Token to identify the current user, and obtain an Access Token to access
	 * the Azure AD Graph API.
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

		/* If 'code' is present, this is the Authorization Response from Azure AD, and 'code' has
		 * the Authorization Code, which will be exchanged for an ID Token and an Access Token.
		 */
		if ( isset( $_GET['code'] ) ) {

			$antiforgery_id = $_SESSION['aadsso_antiforgery-id'];
			$state_is_missing = ! isset( $_GET['state'] );
			$state_doesnt_match = $_GET['state'] != $antiforgery_id;

			if ( $state_is_missing || $state_doesnt_match ) {
				return new WP_Error(
					'antiforgery_id_mismatch',
					sprintf( __( 'ANTIFORGERY_ID mismatch. Expecting %s', 'aad-sso-wordpress' ), $antiforgery_id )
				);
			}

			// Looks like we got a valid authorization code, let's try to get an access token with it
			$token = AADSSO_AuthorizationHelper::get_access_token( $_GET['code'], $this->settings );

			/**
			 * Allow filtering of the token in order to use alternate AAD applications to sign in.
			 *
			 * @param mixed           $token The token returned from the standard auth call
			 * @param string                 The $_GET['code'] variable
			 * @param \AADSSO_Settings       The settings object for the AADSSO instance.
			 * @since 0.6.2
			 *
			 * @return mixed
			 */
			$token = apply_filters( 'addsso_auth_token', $token, $_GET['code'], $this->settings );

			// Happy path
			if ( isset( $token->access_token ) ) {

				try {
					$jwt = AADSSO_AuthorizationHelper::validate_id_token(
						$token->id_token,
						$this->settings,
						$antiforgery_id
					);

					AADSSO::debug_log( 'ID Token: iss: \'' . $jwt->iss . '\', oid: \'' . $jwt->oid, 10 );
					AADSSO::debug_log( json_encode( $jwt ), 50 );

				} catch ( Exception $e ) {
					return new WP_Error(
						'invalid_id_token',
						sprintf( __( 'ERROR: Invalid id_token. %s', 'aad-sso-wordpress' ), $e->getMessage() )
					);
				}

				// Set a default value for group_memberships.
				$group_memberships = false;

				if ( true === $this->settings->enable_aad_group_to_wp_role ) {
					// 1. Retrieve the Groups for this user once here so we can pass them around as needed.
					// Pass the settings to GraphHelper
					AADSSO_GraphHelper::$settings  = $this->settings;
					AADSSO_GraphHelper::$tenant_id = $jwt->tid;

					// Of the AAD groups defined in the settings, get only those where the user is a member
					$group_ids         = array_keys( $this->settings->aad_group_to_wp_role_map );
					$group_memberships = AADSSO_GraphHelper::user_check_member_groups( $jwt->oid, $group_ids );
				}


				// Invoke any configured matching and auto-provisioning strategy and get the user.
				// 2. Pass the Group Membership to allow us to control when a user is created if auto-provisioning is enabled.
				$user = $this->get_wp_user_from_aad_user( $jwt, $group_memberships );

				if ( is_a( $user, 'WP_User' ) ) {

					// At this point, we have an authorization code, an access token and the user
					// exists in WordPress (either because it already existed, or we created it
					// on-the-fly). All that's left is to set the roles based on group membership.
					// 4. If a user was created or found above, we can pass the groups here to have them assigned normally
					if ( true === $this->settings->enable_aad_group_to_wp_role ) {
						$user = $this->update_wp_user_roles( $user, $group_memberships );
					}
				}
			} elseif ( isset( $token->error ) ) {

				// Unable to get an access token ( although we did get an authorization code )
				return new WP_Error(
					$token->error,
					sprintf(
						__( 'ERROR: Could not get an access token to Azure Active Directory. %s', 'aad-sso-wordpress' ),
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
				$_GET['error'],
				sprintf(
					__( 'ERROR: Access denied to Azure Active Directory. %s', 'aad-sso-wordpress' ),
					$_GET['error_description']
				)
			);
		}

		return $user;
	}

	function get_wp_user_from_aad_user( $jwt, $group_memberships ) {

		// Try to find an existing user in WP where the upn or unique_name of the current AAD user is
		// (depending on config) the 'login' or 'email' field in WordPress
		$unique_name = isset( $jwt->upn ) ? $jwt->upn : ( isset( $jwt->unique_name ) ? $jwt->unique_name : null );
		if ( null === $unique_name ) {
			return new WP_Error(
				'unique_name_not_found',
				__( 'ERROR: Neither \'upn\' nor \'unique_name\' claims not found in ID Token.',
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
				'Matched Azure AD user [%s] to existing WordPress user [%s].', $unique_name, $user->ID ), 10 );
		} else {

			// Since the user was authenticated with AAD, but not found in WordPress,
			// need to decide whether to create a new user in WP on-the-fly, or to stop here.
			if ( true === $this->settings->enable_auto_provisioning ) {

				// 3. If we are configured to check, and there are no groups for this user, we should not be creating it.
				if ( true === $this->settings->enable_aad_group_to_wp_role && empty( $group_memberships->value ) ) {
					// The user was authenticated, but is not a member a role-granting group.
					return new WP_Error(
						'user_not_assigned_to_group',
						sprintf(
							__( 'ERROR: The authenticated user \'%s\' does not have a group assignment for this site.',
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
					'first_name' => $jwt->given_name,
					'last_name'  => $jwt->family_name,
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
		
		// Check for errors in the group membership check response
		if ( isset( $group_memberships->value ) ) {
			AADSSO::debug_log( sprintf(
				'Out of [%s], user \'%s\' is a member of [%s]',
				implode( ',', $group_ids ), $aad_user_id, implode( ',', $group_memberships->value ) ), 20
			);
		} elseif ( isset ( $group_memberships->{'odata.error'} ) ) {
			AADSSO::debug_log( 'Error when checking group membership: ' . json_encode( $group_memberships ) );
			return new WP_Error(
				'error_checking_group_membership',
				sprintf(
					__( 'ERROR: Unable to check group membership in Azure AD: <b>%s</b>.', 
					    'aad-sso-wordpress' ), $group_memberships->{'odata.error'}->code )
			);
		} else {
			AADSSO::debug_log( 'Unexpected response to checkMemberGroups: ' . json_encode( $group_memberships ) );
			return new WP_Error(
				'unexpected_response_to_checkMemberGroups',
				__( 'ERROR: Unexpected response when checking group membership in Azure AD.', 
			        'aad-sso-wordpress' )
			);
		}

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
				__( 'ERROR: Azure AD user %s is not a member of any group granting a role.', 'aad-sso-wordpress' ),
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
	 * Generates the URL used to initiate a sign-in with Azure AD.
	 *
	 * @return string The authorization URL used to initiate a sign-in to Azure AD.
	 */
	function get_login_url() {
		$antiforgery_id = com_create_guid();
		$_SESSION['aadsso_antiforgery-id'] = $antiforgery_id;
		return AADSSO_AuthorizationHelper::get_authorization_url( $this->settings, $antiforgery_id );
	}

	/**
	 * Generates the URL for logging out of Azure AD. (Does not log out of WordPress.)
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
		session_destroy();
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
		. __( 'Single Sign-on with Azure Active Directory required settings are not defined. '
		      . 'Update them under Settings > Azure AD.', 'aad-sso-wordpress' )
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
	 * Renders the link used to initiate the login to Azure AD.
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
		mt_srand( ( double )microtime() * 10000 );
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
