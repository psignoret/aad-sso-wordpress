<?php

/*
Plugin Name: Azure Active Directory Single Sign-on for WordPress
Plugin URI: http://github.com/psignoret/aad-sso-wordpress
Description: Allows you to use your organization's Azure Active Directory user accounts to log in to WordPress. If your organization is using Office 365, your user accounts are already in Azure Active Directory. This plugin uses OAuth 2.0 to authenticate users, and the Azure Active Directory Graph to get group membership and other details.
Author: Philippe Signoret
Version: 0.2a
Author URI: http://psignoret.com/
*/

defined('ABSPATH') or die("No script kiddies please!");

define( 'AADSSO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AADSSO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

define( 'AADSSO_SETTINGS_PATH', AADSSO_PLUGIN_DIR . '/Settings.json' );

require_once AADSSO_PLUGIN_DIR . '/Settings.php';
require_once AADSSO_PLUGIN_DIR . '/AuthorizationHelper.php';
require_once AADSSO_PLUGIN_DIR . '/GraphHelper.php';
require_once AADSSO_PLUGIN_DIR . '/JWT.php';

class AADSSO {

	static $instance = FALSE;

	private $settings = NULL;
	const ANTIFORGERY_ID_KEY = 'antiforgery-id';

	public function __construct( $settings ) {
		$this->settings = $settings;

		// Set the redirect urls
		$this->settings->redirectURI = wp_login_url();
		$this->settings->logoutRedirectURI = wp_login_url();

		// Add the hook that starts the SESSION
		add_action( 'init', array($this, 'register_session') );

		// The authenticate filter
		add_filter( 'authenticate', array( $this, 'authenticate' ), 1, 3 );

		// Some debugging locations 
		//add_action( 'admin_notices', array( $this, 'printDebug' ) );
		//add_action( 'login_footer', array( $this, 'printDebug' ) );

		// Add the <style> element to the login page
		add_action( 'login_enqueue_scripts', array( $this, 'printLoginCss' ) );

		// Add the link to the organization's sign-in page
		add_action( 'login_form', array( $this, 'printLoginLink' ) ) ;
	}

	public static function getInstance( $settings ) {
		if ( ! self::$instance ) {
			self::$instance = new self( $settings );
		}
		return self::$instance;
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
			if ( ! isset( $_GET['state'] ) || $_GET['state'] != $_SESSION[ self::ANTIFORGERY_ID_KEY ] ) {
				return new WP_Error( 'antiforgery_id_mismatch', sprintf( 'ANTIFORGERY_ID_KEY mismatch. Expecting %s', $_SESSION[ self::ANTIFORGERY_ID_KEY ] ) );
			}

			// Looks like we got an authorization code, let's try to get an access token
			$token = AADSSO_AuthorizationHelper::getAccessToken( $_GET['code'], $this->settings );
			processToken($token);

			// Happy path
			if ( isset( $token->access_token ) ) {
				// BUGBUG: validate the token! Until this is done, DO NOT use in production environment
				$jwt = JWT::decode( $token->id_token );
				$_SESSION['access_token'] = $token->access_token;
				$_SESSION['token_type']   = $token->token_type;

				// Try to find an existing user in WP where the UPN of the currect AAD user is 
				// (depending on config) the 'login' or 'email' field
				$user = get_user_by( $this->settings->field_to_match_to_upn, $jwt->upn );

				if ( is_a( $user, 'WP_User' ) ) {
					// At this point, we have an authorization code, an access token and the user exists in WordPress.
					// All that's left is to set the roles based on group membership.
					if ( $this->settings->enable_aad_group_to_wp_role ) {
						$this->updateUserRoles( $user, $jwt->oid );
					}
				} else {
					// TODO: Auto-provision (if desired).
					$user = new WP_Error( 'user_not_registered', sprintf( 'ERROR: The authenticated user %s is not a registered user in this blog.', $jwt->upn ) );
				}
			} elseif ( isset( $token->error ) ) {
				// Unable to get an access token (although we did get an authorization code)
				$user = new WP_Error( $token->error, sprintf( 'ERROR: Could not get an access token to Azure Active Directory. %s', $token->error_description ) );
			} else {
				// None of the above, I have no idea what happened.
				$user = new WP_Error( 'unknown', 'ERROR: An unknown error occured.' );
			}

		} elseif ( isset( $_GET['error'] ) ) {
			// The attempt to get an authorization code failed (i.e., the reply from the STS was "No.")
			$user = new WP_Error( $_GET['error'], sprintf( 'ERROR: Access denied to Azure Active Directory. %s', $_GET['error_description']) );
		}

		return $user;
	}

	// Users AAD group memberships to set WordPress role
	function updateUserRoles( $user, $aad_object_id ) {
		// Pass the settings to GraphHelper
		AADSSO_GraphHelper::$settings = $this->settings;

		// Of the AAD groups defined in the settings, get only those where the user is a member
		$group_ids = array_keys( $this->settings->aad_group_to_wp_role_map );
		$group_memberships = AADSSO_GraphHelper::userCheckMemberGroups( $aad_object_id, $group_ids );

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

		if ( NULL != $role_to_set ) {
			// Set the role on the WordPress user
			$user->set_role($role_to_set);
		} else {
			$user = new WP_Error( 'user_not_member_of_required_group', sprintf( 'ERROR: The authenticated user %s is not a member of any group granting a role.', $jwt->upn ) );
		}
	}

	function getLoginUrl() {
		$antiforgery_id = com_create_guid ();
		$_SESSION[ self::ANTIFORGERY_ID_KEY ] = $antiforgery_id;
		return AADSSO_AuthorizationHelper::getAuthorizationURL( $this->settings, $antiforgery_id );
	}

	function getLogoutUrl() {
		return $this->settings->signOutEndpoint . http_build_query( array( 'post_logout_redirect_uri' => $this->settings->logoutRedirectURI ) );
	}

	function processToken() {

		// Add the token information to the session header so that we can use it to access Graph
        $_SESSION['token_type']=$tokenOutput->{'token_type'};
        $_SESSION['access_token']=$tokenOutput->{'access_token'};
        
        // Get the full response and decode the JWT token
        $_SESSION['response'] = json_decode($output, TRUE);
        if(isset($_SESSION['response']['id_token'])) {

            $_SESSION['response']['id_token'] = JWT::decode($_SESSION['response']['id_token']);
            $_SESSION['tenant_id'] = &$_SESSION['response']['id_token']->tid;
        
            // If we got an authorization code _and_ access token, then we're "logged in"
            $_SESSION['logged_in'] = TRUE;
        }
    }

	/*** View ****/

	function printDebug() {
		if ( isset( $_SESSION['aadsso_debug'] ) ) {
			echo '<pre>'. print_r( $_SESSION['aadsso_var'], TRUE ) . '</pre>';
		}
		echo '<p>DEBUG</p><pre>' . print_r( $_SESSION, TRUE ) . '</pre>'; 
		echo '<pre>' . print_r( $_GET, TRUE ) . '</pre>'; 
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
		printf ( $html, $this->getLoginUrl(), htmlentities( $this->settings->org_display_name ), $this->getLogoutUrl() );
	}
}

$settings = AADSSO_Settings::loadSettingsFromJSON(AADSSO_SETTINGS_PATH);
$aadsso = AADSSO::getInstance($settings);