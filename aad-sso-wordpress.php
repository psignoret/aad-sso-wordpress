<?php
/**
 * Azure Active Directory Single Sign-on for WordPress
 *
 * @package aad-sso-wordpress
 * @license MIT
 *
 * @wordpress-plugin
 * Plugin Name: Azure Active Directory Single Sign-on for WordPress
 * Plugin URI: http://github.com/psignoret/aad-sso-wordpress
 * Description: Allows you to use your organization's Azure Active Directory user accounts to log in to WordPress. If your organization is using Office 365, your user accounts are already in Azure Active Directory. This plugin uses OAuth 2.0 to authenticate users, and the Microsoft Graph API to get group membership and other details.
 * Author: Philippe Signoret
 * Version: 1.0.0
 * Author URI: https://www.psignoret.com/
 * Text Domain: aad-sso-wordpress
 * Domain Path: /languages/
 */

defined( 'ABSPATH' ) || die( 'No script kiddies please!' );

define( 'AADSSO', 'aad-sso-wordpress' );
define( 'AADSSO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AADSSO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * This constant is used to determine if the plugin is installed as a must-use plugin.
 *
 * @since 1.0.0
 */
define( 'AADSSO_IS_WPMU_PLUGIN', str_starts_with( AADSSO_PLUGIN_DIR, WPMU_PLUGIN_DIR ) );

/**
 * This constant is used to determine if the plugin is installed as a regular plugin.
 *
 * @since 1.0.0
 */
define( 'AADSSO_IS_WP_PLUGIN', str_starts_with( AADSSO_PLUGIN_DIR, WP_PLUGIN_DIR ) );

/**
 * This determines the number of nonces to use for the antiforgery token.
 *
 * The default value is 3, which means that three nonces will be generated and validated for each login.
 * Please keep in mind that the nonce is passed in the query string, so many nonces will cause a VERY
 * long URL.  Even once nonce should be sufficient.
 */
defined( 'AADSSO_NONCE_PASSES' ) || define( 'AADSSO_NONCE_PASSES', 3 );

/**
 * Used to engage certain debugging behaviors.  By default, this is whatever the WP_DEBUG value is.
 *
 * When enabled, the following will occur:
 * - Nonced actions will include an `aadsso_nonce_hint` so you can trace the actions as redirects occur.
 *
 * @since 1.0.0
 * @var bool
 */
defined( 'AADSSO_DEBUG' ) || define( 'AADSSO_DEBUG', constant( 'WP_DEBUG' ) );

/**
 * These logging constants can be used to affect the verbosity of the logging.
 * Higher numbers are more verbose.  The default is 0, which means that only fatal errors are logged.
 * These are numbered using modified fibbonacci numbers so that you can easily add new levels in between.
 */
define( 'AADSSO_LOG_FATAL', 0 );
define( 'AADSSO_LOG_ERROR', 1 );
define( 'AADSSO_LOG_WARNING', 2 );
define( 'AADSSO_LOG_INFO', 3 );
define( 'AADSSO_LOG_VERBOSE', 5 );
define( 'AADSSO_LOG_SILLY', 8 );

defined( 'AADSSO_LOG_LEVEL' ) || define( 'AADSSO_LOG_LEVEL', AADSSO_LOG_ERROR );

/**
 * Polyfills for PHP functions that are not available in older versions of PHP.
 */
if ( ! function_exists( 'str_starts_with' ) ) {
	/**
	 * Checks if a string starts with a given substring.
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The substring to search for in $haystack.
	 * @since 1.0.0
	 * @return bool True if $haystack starts with $needle, false otherwise.
	 */
	function str_starts_with( $haystack, $needle ) {
		return (string) '' === $needle && strncmp( $haystack, $needle, strlen( $needle ) ) === 0;
	}
}

/**
 * Load the classes used to implement the plugin.
 */
require_once AADSSO_PLUGIN_DIR . '/class-aadsso.php';
require_once AADSSO_PLUGIN_DIR . '/class-aadsso-settings.php';
require_once AADSSO_PLUGIN_DIR . '/class-aadsso-settings-page.php';
require_once AADSSO_PLUGIN_DIR . '/class-aadsso-authorization-helper.php';
require_once AADSSO_PLUGIN_DIR . '/class-aadsso-graph-helper.php';
require_once AADSSO_PLUGIN_DIR . '/class-aadsso-html-helper.php';

// TODO: Auto-load the php-jwt library.
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/src/JWT.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/src/BeforeValidException.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/src/ExpiredException.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/src/SignatureInvalidException.php';

// Load settings JSON contents from DB and initialize the plugin.
$aadsso_settings_instance = AADSSO_Settings::init();
$aadsso                   = AADSSO::get_instance( $aadsso_settings_instance );
