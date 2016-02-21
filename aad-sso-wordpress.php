<?php

/*
Plugin Name: Azure Active Directory Single Sign-on for WordPress
Plugin URI: http://github.com/psignoret/aad-sso-wordpress
Description: Allows you to use your organization's Azure Active Directory user accounts to log in to WordPress. If your organization is using Office 365, your user accounts are already in Azure Active Directory. This plugin uses OAuth 2.0 to authenticate users, and the Azure Active Directory Graph to get group membership and other details.
Author: Philippe Signoret
Version: 0.5a
Author URI: http://psignoret.com/
*/

defined('ABSPATH') or die("No script kiddies please!");

define( 'AADSSO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AADSSO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Proxy to be used for calls, should be useful for tracing with Fiddler
// BUGBUG: Doesn't actually work, at least not with WP running on WAMP stack
//define( 'WP_PROXY_HOST', '127.0.0.1' );
//define( 'WP_PROXY_PORT', '8888' );

require_once AADSSO_PLUGIN_DIR . '/Settings.php';
require_once AADSSO_PLUGIN_DIR . '/AuthorizationHelper.php';
require_once AADSSO_PLUGIN_DIR . '/GraphHelper.php';

// TODO: Auto-load the (the exceptions at least)
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/Authentication/JWT.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/Exceptions/BeforeValidException.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/Exceptions/ExpiredException.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/Exceptions/SignatureInvalidException.php';

class AADSSO {

	static $instance = FALSE;

	private $settings = NULL;
	const ANTIFORGERY_ID_KEY = 'antiforgery-id';

	public function __construct( $settings ) {
		$this->settings = $settings;

		// Set the redirect urls
		$this->settings->redirect_uri = wp_login_url();
		$this->settings->logout_redirect_uri = wp_login_url();

		// Some debugging locations
		//add_action( 'admin_notices', array( $this, 'printDebug' ) );
		//add_action( 'login_footer', array( $this, 'printDebug' ) );

		// If plugin is not configured, we shouldn't proceed.
		if ( ! $this->plugin_is_configured() ) {
			add_action( 'all_admin_notices', array( $this, 'print_plugin_not_configured' ) );
			return;
		}

		// Add the hook that starts the SESSION
		add_action( 'init', array($this, 'register_session') );

		// The authenticate filter
		add_filter( 'authenticate', array( $this, 'authenticate' ), 1, 3 );

		// Add the <style> element to the login page
		add_action( 'login_enqueue_scripts', array( $this, 'printLoginCss' ) );

		// Add the link to the organization's sign-in page
		add_action( 'login_form', array( $this, 'printLoginLink' ) ) ;

		// Clear session variables when logging out
		add_action( 'wp_logout', array( $this, 'clearSession' ) );

		// If configured, bypass the login form and redirect straight to AAD
		add_action( 'login_init', array( $this, 'save_redirect_and_maybe_bypass_login' ) );

		// Redirect user back to original location
		add_filter( 'login_redirect', array( $this, 'redirect_after_login' ), 20, 3 );
	}

	/**
	 * Determine if required plugin settings are stored
	 *
	 * @return bool Whether plugin is configured
	 */
	public function plugin_is_configured() {
		return
			isset( $this->settings->client_id, $this->settings->client_secret )
			 && $this->settings->client_id
			 && $this->settings->client_secret;
	}

	public static function getInstance( $settings ) {
		if ( ! self::$instance ) {
			self::$instance = new self( $settings );
		}
		return self::$instance;
	}

	/**
	 * Based on settings and current page, bypasses the login form and forwards straight to AAD.
	 **/
	public function save_redirect_and_maybe_bypass_login() {

		$_SESSION['settings'] = $this->settings;

		$bypass = apply_filters(
			'aad_auto_forward_login',
			$this->settings->enable_auto_forward_to_aad
		);

		/*
		 * If the user is attempting to log out AND the auto-forward to AAD
		 * login is set then we need to ensure we do not auto-forward the user and get
		 * them stuck in an infinite logout loop.
		 */
		if( $this->wants_to_login() ) {

			// Save the redirect_to query param (if present) to session
			if ( isset( $_GET['redirect_to'] ) ) {
				$_SESSION['redirect_to'] = $_GET['redirect_to'];
			}

			if ( $bypass && ! isset( $_GET['code'] ) ) {
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
	 * @return string
	 */
	public function redirect_after_login( $redirect_to, $requested_redirect_to, $user ) {
		if ( is_a( $user, 'WP_User' ) && isset( $_SESSION['redirect_to'] ) ) {
			$redirect_to = $_SESSION['redirect_to'];
		}

		return $redirect_to;
	}

	/**
	* Checks to determine if the user wants to login on wp-login
	*
	* This function mostly exists to cover the exceptions to login
	* that may exist as other parameters to $_GET[action] as $_GET[action]
	* does not have to exist. By default WordPress assumes login if an action
	* is not set, however this may not be true, as in the case of logout
	* where $_GET[loggedout] is instead set
	*
	* @return boolean
	**/
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

	function register_session() {
		if ( ! session_id() ) {
			session_start();
		}
	}

	function authenticate( $user, $username, $password ) {

		// Don't re-authenticate if already authenticated
		if ( is_a( $user, 'WP_User' ) ) { return $user; }

		if ( isset( $_GET['code'] ) ) {

			$antiforgery_id = $_SESSION[ self::ANTIFORGERY_ID_KEY ];
			$state_is_missing = !isset( $_GET['state'] );
			$state_doesnt_match = $_GET['state'] != $antiforgery_id;

			if ( $state_is_missing || $state_doesnt_match ) {
				return new WP_Error(
					'antiforgery_id_mismatch',
					sprintf( 'ANTIFORGERY_ID mismatch. Expecting %s', $antiforgery_id )
				);
			}

			// Looks like we got an authorization code, let's try to get an access token with it
			$token = AADSSO_AuthorizationHelper::getAccessToken( $_GET['code'], $this->settings );

			// Happy path
			if ( isset( $token->access_token ) ) {

				try {
					$jwt = AADSSO_AuthorizationHelper::validateIdToken(
						$token->id_token,
						$this->settings,
						$antiforgery_id
					);
				} catch ( Exception $e ) {
					return new WP_Error(
						'invalid_id_token',
						sprintf( 'ERROR: Invalid id_token. %s', $e->getMessage() )
					);
				}

				// Invoke any configured matching and auto-provisioning strategy and get the user.
				$user = $this->getWPUserFromAADUser( $jwt );

				if ( is_a( $user, 'WP_User' ) ) {

					// At this point, we have an authorization code, an access token and the user
					// exists in WordPress (either because it already existed, or we created it
					// on-the-fly. All that's left is to set the roles based on group membership.
					if ( $this->settings->enable_aad_group_to_wp_role ) {
						$user = $this->updateUserRoles( $user, $jwt->upn, $jwt->tid );
					}
				}

			} elseif ( isset( $token->error ) ) {

				// Unable to get an access token (although we did get an authorization code)
				return new WP_Error(
					$token->error,
					sprintf(
						'ERROR: Could not get an access token to Azure Active Directory. %s',
						$token->error_description
					)
				);
			} else {

				// None of the above, I have no idea what happened.
				return new WP_Error( 'unknown', 'ERROR: An unknown error occured.' );
			}

		} elseif ( isset( $_GET['error'] ) ) {

			// The attempt to get an authorization code failed.
			return new WP_Error(
				$_GET['error'],
				sprintf(
					'ERROR: Access denied to Azure Active Directory. %s',
					$_GET['error_description']
				)
			);
		}

		return $user;
	}

	function getWPUserFromAADUser($jwt) {

		// Try to find an existing user in WP where the UPN of the current AAD user is
		// (depending on config) the 'login' or 'email' field
		$user = get_user_by( $this->settings->field_to_match_to_upn, $jwt->upn );

		if ( !is_a( $user, 'WP_User' ) ) {

			// Since the user was authenticated with AAD, but not found in WordPress,
			// need to decide whether to create a new user in WP on-the-fly, or to stop here.
			if( $this->settings->enable_auto_provisioning ) {

				// Setup the minimum required user data
				// TODO: Is null better than a random password?
				// TODO: Look for otherMail, or proxyAddresses before UPN for email
				$userdata = array(
					'user_email' => $jwt->upn,
					'user_login' => $jwt->upn,
					'first_name' => $jwt->given_name,
					'last_name'  => $jwt->family_name,
					'user_pass'  => null
				);

				$new_user_id = wp_insert_user( $userdata );

				$user = new WP_User( $new_user_id );
			} else {

				// The user was authenticated, but not found in WP and auto-provisioning is disabled
				return new WP_Error(
					'user_not_registered',
					sprintf(
						'ERROR: The authenticated user %s is not a registered user in this blog.',
						$jwt->upn
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
	  * @param string $aad_user_id The AAD object id of the user
	  * @param string $aad_tenant_id The AAD directory tenant ID
	  * @return WP_User|WP_Error Return the WP_User with updated rols, or WP_Error if failed.
	  */
	function updateUserRoles( $user, $aad_user_id, $aad_tenant_id ) {

		// Pass the settings to GraphHelper
		AADSSO_GraphHelper::$settings = $this->settings;
		AADSSO_GraphHelper::$tenant_id = $aad_tenant_id;

		// Of the AAD groups defined in the settings, get only those where the user is a member
		$group_ids = array_keys( $this->settings->aad_group_to_wp_role_map );
		$group_memberships = AADSSO_GraphHelper::userCheckMemberGroups( $aad_user_id, $group_ids );

		// Determine which WordPress role the AAD group corresponds to.
		// TODO: Check for error in the group membership response
		$role_to_set = $this->settings->default_wp_role;
		if ( ! empty($group_memberships->value ) ) {
			foreach ( $this->settings->aad_group_to_wp_role_map as $aad_group => $wp_role) {
				if ( in_array( $aad_group, $group_memberships->value ) ) {
					$role_to_set = $wp_role;
					break;
				}
			}
		}

		if ( NULL != $role_to_set || "" != $role_to_set ) {
			// Set the role on the WordPress user
			$user->set_role($role_to_set);
		} else {
			return new WP_Error(
				'user_not_member_of_required_group',
				sprintf(
					'ERROR: AAD user %s is not a member of any group granting a role.',
					$aad_user_id
				)
			);
		}

		return $user;
	}

	function clearSession() {
		session_destroy();
	}

	function get_login_url() {
		$antiforgery_id = com_create_guid ();
		$_SESSION[ self::ANTIFORGERY_ID_KEY ] = $antiforgery_id;
		return AADSSO_AuthorizationHelper::getAuthorizationURL( $this->settings, $antiforgery_id );
	}

	function getLogoutUrl() {
		return $this->settings->end_session_endpoint
			. '?'
			. http_build_query(
				array( 'post_logout_redirect_uri' => $this->settings->logout_redirect_uri )
			);
	}

	/*** View ****/

	function print_plugin_not_configured() {
		echo '<div id="message" class="error"><p>'
			. __( 'Azure Active Directory Single Sign-on for WordPress required settings are not defined. Update them under '
			. 'Settings > Azure AD.', 'aad-sso-wordpress' )
			.'</p></div>';
	}

	function printDebug() {
		if ( isset( $_SESSION['aadsso_debug'] ) ) {
			echo '<pre>'. print_r( $_SESSION['aadsso_var'], TRUE ) . '</pre>';
		}
		echo '<p>DEBUG</p><pre>' . print_r( $_SESSION, TRUE ) . '</pre>';
		echo '<pre>' . print_r( $_GET, TRUE ) . '</pre>';
		echo '<pre>' . print_r( $this->settings, true ) . '</pre>';
	}

	function printLoginCss() {
		wp_enqueue_style( 'aad-sso-wordpress', AADSSO_PLUGIN_URL . '/login.css' );
	}

	function printLoginLink() {
		$html = <<<EOF
			<p class="aadsso-login-form-text">
				<a href="%s">Sign in with your %s account</a><br />
				<a class="dim" href="%s">Sign out</a>
			</p>
EOF;
		printf(
			$html,
			$this->get_login_url(),
			htmlentities( $this->settings->org_display_name ),
			$this->getLogoutUrl()
		);
	}
}

// make sure the plugin is configured to find its configuration file
if( ! defined('AADSSO_SETTINGS_PATH') ) {
	function aadsso_settings_path_undefined () {
		echo '<div id="message" class="error"><p>'
			. __(
				'Azure Active Directory Single Sign-on does not know where your configuration file is located. '
					. ' Add the following line to wp-config.php',
				'aad-sso-wordpress'
			)
			. '<p><code>'
			. "define( 'AADSSO_SETTINGS_PATH', '/path/to/Settings.json' );"
			. '</code>'
			.'</p></div>';
	}
	add_action( 'all_admin_notices', 'aadsso_settings_path_undefined');

	return;
}
$settings = AADSSO_Settings::load_settings_from_json_file( AADSSO_SETTINGS_PATH );
$aadsso = AADSSO::getInstance($settings);

	if ( ! file_exists( AADSSO_SETTINGS_PATH ) ) {
		function addsso_settings_missing_notice () {
			echo '<div id="message" class="error"><p>'
				. __(
					'Azure Active Directory Single Sign-on for WordPress requires a Settings.json file '
						. ' to be added to the plugin.',
					'aad-sso-wordpress'
				)
				.'</p></div>';
		}
		add_action( 'all_admin_notices', 'addsso_settings_missing_notice' );
	} else {
	}

// show a warning if Settings.json is in a publicly accessible location.
if( strpos( AADSSO_SETTINGS_PATH, AADSSO_PLUGIN_DIR ) === 0 ) {
	function addsso_dangerous_settings_json_location () {
		echo '<div id="settings_json_location" class="update-nag"><p>'
			. __(
				'The Settings.json file is in a publicly accessible location. '
					.'Consider moving the file and adjusting AADSSO_SETTINGS_PATH.',
				'aad-sso-wordpress'
			)
			.'</p></div>';
	}
	add_action( 'all_admin_notices', 'addsso_dangerous_settings_json_location' );
}

if ( ! function_exists( 'com_create_guid' ) ) {
	function com_create_guid(){
		mt_srand((double)microtime()*10000);//optional for php 4.2.0 and up.
		$charid = strtoupper(md5(uniqid(rand(), true)));
		$hyphen = chr(45);// "-"
		$uuid = chr(123)// "{"
			.substr($charid, 0, 8).$hyphen
			.substr($charid, 8, 4).$hyphen
			.substr($charid,12, 4).$hyphen
			.substr($charid,16, 4).$hyphen
			.substr($charid,20,12)
			.chr(125);// "}"
		return $uuid;
	}
 }
