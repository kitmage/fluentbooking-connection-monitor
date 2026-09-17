<?php
/**
 * Plugin Name: Aspen FluentBooking Integration Monitor
 * Description: Monitors FluentBooking Microsoft/Outlook calendar connections and notifies administrators of stored integration failures.
 * Version: 1.0.0
 * Author: Aspen
 * License: GPL-2.0-or-later
 * Text Domain: aspen-fluentbooking-integration-monitor
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'ASPEN_FBIM_VERSION', '1.0.0' );
define( 'ASPEN_FBIM_FILE', __FILE__ );
define( 'ASPEN_FBIM_DIR', plugin_dir_path( __FILE__ ) );

require_once ASPEN_FBIM_DIR . 'includes/class-aspen-fbim-monitor.php';
require_once ASPEN_FBIM_DIR . 'includes/class-aspen-fbim-admin.php';

register_activation_hook( __FILE__, array( 'Aspen_FBIM_Monitor', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Aspen_FBIM_Monitor', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		$monitor = new Aspen_FBIM_Monitor();
		$monitor->register_hooks();
		if ( is_admin() ) {
			( new Aspen_FBIM_Admin( $monitor ) )->register_hooks();
		}
	}
);
