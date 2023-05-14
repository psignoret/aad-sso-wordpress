<?php
/**
 * Loads the AAD SSO WordPress plugin as a must-use plugin. This file should be copied/symlinked to wp-content/mu-plugins.
 *
 * @package aad-sso-wordpress
 *
 * Plugin Name: AAD SSO WordPress Loader
 * Description: Loads the AAD SSO WordPress plugin as a must-use plugin.
 * Version: 1.0.0
 * Author: Brad Kovach <https://bradkovach.com>
 * Author URI: https://bradkovach.com
 * License: MIT
 */

require WPMU_PLUGIN_DIR . '/aad-sso-wordpress/aad-sso-wordpress.php';
