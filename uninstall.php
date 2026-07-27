<?php

// If uninstall is not called from WordPress, exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit();
}

/*
 * Preserve configuration by default. This is important for manual updates and for installations
 * that have used more than one directory name for the same plugin, because all copies share these
 * option names. Administrators can remove the data explicitly with Reset Settings before
 * uninstalling, or opt in to destructive uninstall behavior in wp-config.php.
 */
if ( defined( 'AADSSO_DELETE_DATA_ON_UNINSTALL' )
	&& true === AADSSO_DELETE_DATA_ON_UNINSTALL
) {
	delete_option( 'aadsso_settings' );
	delete_option( 'aadsso_settings_backup' );
	delete_transient( 'aadsso_openid_configuration' );
}
