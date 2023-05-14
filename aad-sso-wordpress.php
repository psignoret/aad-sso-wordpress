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
 * Version: 0.8.0
 * Author URI: https://www.psignoret.com/
 * Text Domain: aad-sso-wordpress
 * Domain Path: /languages/
 */

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * Polyfills for PHP functions that are not available in older versions of PHP.
 */
if ( ! function_exists( 'str_starts_with' ) ) {
	/**
	 * Checks if a string starts with a given substring.
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The substring to search for in $haystack.
	 */
	function str_starts_with( $haystack, $needle ) {
		return (string) $needle !== '' && strncmp( $haystack, $needle, strlen( $needle ) ) === 0;
	}
}

if ( ! function_exists( 'com_create_guid' ) ) {
	/**
	 * Generates a globally unique identifier ( Guid ).
	 *
	 * @return string A new random globally unique identifier.
	 */
	function com_create_guid() {
		mt_srand( (int) ( (float) microtime() * 10000 ) );
		$charid = strtoupper( md5( uniqid( rand(), true ) ) );
		$hyphen = chr( 45 ); // "-"
		$uuid   = chr( 123 ) // "{"
			. substr( $charid, 0, 8 ) . $hyphen
			. substr( $charid, 8, 4 ) . $hyphen
			. substr( $charid, 12, 4 ) . $hyphen
			. substr( $charid, 16, 4 ) . $hyphen
			. substr( $charid, 20, 12 )
			. chr( 125 ); // "}"
		return $uuid;
	}
}

define( 'AADSSO', 'aad-sso-wordpress' );
define( 'AADSSO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AADSSO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

define( 'AADSSO_IS_WP_PLUGIN', str_starts_with( AADSSO_PLUGIN_DIR, WP_PLUGIN_DIR ) );
define( 'AADSSO_IS_WPMU_PLUGIN', str_starts_with( AADSSO_PLUGIN_DIR, WPMU_PLUGIN_DIR ) );

defined( 'AADSSO_DEBUG' ) or define( 'AADSSO_DEBUG', false );
defined( 'AADSSO_DEBUG_LEVEL' ) or define( 'AADSSO_DEBUG_LEVEL', 0 );

// Proxy to be used for calls, should be useful for tracing with Fiddler
// BUGBUG: Doesn't actually work, at least not with WP running on WAMP stack
// define( 'WP_PROXY_HOST', '127.0.0.1' );
// define( 'WP_PROXY_PORT', '8888' );

require_once AADSSO_PLUGIN_DIR . '/class-aadsso.php';
require_once AADSSO_PLUGIN_DIR . '/class-aadsso-settings.php';
require_once AADSSO_PLUGIN_DIR . '/class-aadsso-settings-page.php';
require_once AADSSO_PLUGIN_DIR . '/class-aadsso-authorization-helper.php';
require_once AADSSO_PLUGIN_DIR . '/class-aadsso-graph-helper.php';
require_once AADSSO_PLUGIN_DIR . '/class-aadsso-html-helper.php';

// TODO: Auto-load the ( the exceptions at least )
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/src/JWT.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/src/BeforeValidException.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/src/ExpiredException.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/src/SignatureInvalidException.php';


// Load settings JSON contents from DB and initialize the plugin.
$aadsso_settings_instance = AADSSO_Settings::init();
$aadsso                   = AADSSO::get_instance( $aadsso_settings_instance, com_create_guid() );
