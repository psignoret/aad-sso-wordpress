<?php
/**
 * @package AAD_Single_Sign_On
 * @version 0.1a
 */
/*
Plugin Name: Azure Active Directory Single Sign-on for WordPress
Plugin URI: http://psignoret.com/aad-sso
Description: Allows you to use your organization's Azure Active Directory user accounts to log in to WordPress. If your organization is using Office 365, your user accounts are already in Azure Active Directory. This plugin uses OAuth 2.0 to authenticate users, and the Azure Active Directory Graph to get role and group membership and other details.
Author: Philippe Signoret
Version: 0.1a
Author URI: http://psignoret.com/
*/

// Avoid calling this page directly
if ( !function_exists( 'add_action' ) ) {
	echo 'This is a WordPress plugin. You can\'t do anything if you call it directly.';
	exit;
}

define('AADSSO_PLUGIN_URL', plugin_dir_url( __FILE__ ));
define('AADSSO_PLUGIN_DIR', plugin_dir_path( __FILE__ ));


require_once AADSSO_PLUGIN_DIR . '/Settings.php';
require_once AADSSO_PLUGIN_DIR . '/AuthorizationHelper.php';
require_once AADSSO_PLUGIN_DIR . '/JWT.php';

class AADSSO {

	static $instance = FALSE;

	private $prefix = "aadsso_";
	private $settings = NULL;

	public function __construct ($settings) {

		$this->settings = $settings;

		// Set the redirect url
		$this->settings->redirectURI = wp_login_url();

		// Add the hook that starts the SESSION
		//add_action( 'admin_init', array($this, 'startSession'), 1, 0);
		
		// The authenticate filter
		add_filter('authenticate', array($this, 'authenticate'), 1, 3);

		// Some debugging locations 
		//add_action( 'admin_notices', array($this, 'printDebug'));
		//add_action( 'login_footer', array($this, 'printDebug'));

		// Add the <style> element to the login page
		add_action( 'login_enqueue_scripts', array($this, 'printLoginCss'));

		// Add the link to the organization's sign-in page
		add_action( 'login_form', array($this, 'printLoginLink'), 1, 0);

	}

	public static function getInstance ($settings) {
		if (!self::$instance) {
			self::$instance = new self($settings);
		}
		return self::$instance;
	}

	function authenticate($user, $username, $password) {
		
		// Don't re-authenticate if already authenticated
		if ( is_a($user, 'WP_User') ) { return $user; }


		if ( isset($_GET['code']) ) {

			// Looks like we got an authorization code, let's try to get an access token
			$token = AADSSO_AuthorizationHelper::getAccessToken($_GET['code'], $this->settings);

			// Happy path
			if ( isset($token->access_token) ) {
				
				// TODO: validate the token?
				$jwt = JWT::decode($token->id_token);

				// TODO: should be able to map to 'email' or 'login' fields.
				$user = get_user_by( 'login', $jwt->upn );

				if ( is_a($user, 'WP_User') ) {

					// At this point, we got an authorization code, and access token and the user exists.
					// TODO: Update roles based on group membership

				} else {

					// TODO: Auto-provision (if desired).
					$user = new WP_Error( 'user_not_registered', 
						'<p><b>ERROR: The authenticated user \'' . $jwt->upn 
						. '\' is not a registered user in this blog.</b></p>' );
				}
			} elseif ( isset($token->error) ) {
				
				// Unable to get an access token (although we did get an authorization code)
				$user = new WP_Error( $token->error, 
					'<p><b>ERROR: Could not get an access token to Azure Active Directory</b></p><p>' 
						. $token->error_description . '</p>' );
			} else {

				// None of the above, I have no idea what happened.
				$user = new WP_Error( 'unknown', '<p><b>ERROR: An unknown error occured.</b></p>');
			}

		} else

		// The attempt to get an authorization code failed (i.e., the reply from the STS was "No.")
		if ( isset($_GET['error']) ) {
			$user = new WP_Error( $_GET['error'], 
					'<p><b>ERROR: Access denied to Azure Active Directory</b></p><p>' 
						. stripslashes($_GET['error_description']) . '</p>' );
		}

		return $user;
	}

	function getLoginUrl() {
		return AADSSO_AuthorizationHelper::getAuthorizationURL($this->settings);
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
		if (isset($_SESSION['aadsso_debug'])) {
			echo '<pre>'. print_r($_SESSION['aadsso_var'], TRUE) . '</pre>';
		}
		echo '<p>DEBUG</p><pre>' . print_r($_SESSION, TRUE) . '</pre>'; 
		echo '<pre>' . print_r($_GET, TRUE) . '</pre>'; 
	}

	function printLoginCss() {
	    ?><link rel="stylesheet" href="<?php echo plugins_url('login.css', __FILE__); ?>" type="text/css" media="all" /><?php
	}

	function printLoginLink() {
		echo '<p class="aadsso-login-form-text"><a href="' . $this->getLoginUrl() . '">' 
				. 'Sign in with your ' . $this->settings->org_display_name . ' account</a></p>';
	}
}

$settings = AADSSO_Settings::getInstance();
$aadsso = AADSSO::getInstance($settings);