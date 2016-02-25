<?php

// If uninstall is not called from WordPress, exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit();
}

// The only uninstall work needed is to remove any stored settings.
delete_option( 'aadsso_settings' );
