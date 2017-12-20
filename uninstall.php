<?php
/**
 * Uninstall: uninstall.php
 *
 * Contains all tasks to perform whenever the plugin is uninstalled.
 *
 * @package AADSSO
 */

// If uninstall is not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

// The only uninstall work needed is to remove any stored settings.
delete_option( 'aadsso_settings' );
