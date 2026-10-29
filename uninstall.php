<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
delete_option( 'kitmage_fbim_settings' );
delete_option( 'kitmage_fbim_incidents' );
delete_option( 'kitmage_fbim_status' );
delete_option( 'kitmage_fbim_keepalive_status' );
wp_clear_scheduled_hook( 'kitmage_fbim_scan_event' );
wp_clear_scheduled_hook( 'kitmage_fbim_keepalive_event' );
