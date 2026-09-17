<?php
/**
 * Plugin Name: Kitmage FluentBooking Integration Monitor
 * Description: Monitors FluentBooking Microsoft/Outlook calendar connections and notifies administrators of stored integration failures.
 * Version: 1.0.0
 * Author: Mike@KitMage
 * Author URI: https://kitmage.com
 * License: GPL-2.0-or-later
 * Text Domain: kitmage-fluentbooking-integration-monitor
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'KITMAGE_FBIM_VERSION', '1.0.0' );
define( 'KITMAGE_FBIM_FILE', __FILE__ );
define( 'KITMAGE_FBIM_DIR', plugin_dir_path( __FILE__ ) );

require_once KITMAGE_FBIM_DIR . 'includes/class-kitmage-fbim-monitor.php';
require_once KITMAGE_FBIM_DIR . 'includes/class-kitmage-fbim-admin.php';

register_activation_hook( __FILE__, array( 'Kitmage_FBIM_Monitor', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Kitmage_FBIM_Monitor', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		$monitor = new Kitmage_FBIM_Monitor();
		$monitor->register_hooks();
		if ( is_admin() ) {
			( new Kitmage_FBIM_Admin( $monitor ) )->register_hooks();
		}
	}
);
