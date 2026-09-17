<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
delete_option( 'aspen_fbim_settings' );
delete_option( 'aspen_fbim_incidents' );
delete_option( 'aspen_fbim_status' );
wp_clear_scheduled_hook( 'aspen_fbim_scan_event' );
