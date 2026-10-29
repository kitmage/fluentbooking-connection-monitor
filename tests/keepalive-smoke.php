<?php
/**
 * Lightweight WordPress/FluentBooking doubles for keep-alive behavior.
 * Run: php tests/keepalive-smoke.php
 */
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'WEEK_IN_SECONDS', 604800 );

$GLOBALS['test_options'] = array();
$GLOBALS['test_cron'] = array();
$GLOBALS['test_filters'] = array();
$GLOBALS['test_hooks'] = array();
$GLOBALS['test_transients'] = array();

function check_same( $expected, $actual, $label ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $label . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}
function check_true( $value, $label ) {
	if ( ! $value ) { throw new RuntimeException( $label ); }
}
function __( $message, $domain = '' ) { return $message; }
function add_filter( $tag, $callback ) { $GLOBALS['test_filters'][ $tag ][] = $callback; }
function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['test_hooks'][ $tag ][] = $callback; }
function apply_filters( $tag, $value ) {
	foreach ( $GLOBALS['test_filters'][ $tag ] ?? array() as $callback ) {
		$value = call_user_func( $callback, $value );
	}
	return $value;
}
function wp_parse_args( $args, $defaults ) { return array_merge( (array) $defaults, (array) $args ); }
function get_option( $name, $default = false ) { return $GLOBALS['test_options'][ $name ] ?? $default; }
function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['test_options'] ) ) { return false; }
	$GLOBALS['test_options'][ $name ] = $value;
	return true;
}
function update_option( $name, $value, $autoload = null ) {
	$old = get_option( $name );
	$GLOBALS['test_options'][ $name ] = $value;
	foreach ( $GLOBALS['test_hooks'][ 'update_option_' . $name ] ?? array() as $callback ) {
		call_user_func( $callback, $old, $value );
	}
	return true;
}
function wp_next_scheduled( $hook ) { return $GLOBALS['test_cron'][ $hook ] ?? false; }
function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	$schedules = apply_filters( 'cron_schedules', array() );
	if ( ! isset( $schedules[ $recurrence ] ) ) { throw new RuntimeException( 'Unknown recurrence: ' . $recurrence ); }
	$GLOBALS['test_cron'][ $hook ] = $timestamp;
	return true;
}
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['test_cron'][ $hook ] ); }
function get_transient( $key ) { return $GLOBALS['test_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $expiration = 0 ) { $GLOBALS['test_transients'][ $key ] = $value; }
function delete_transient( $key ) { unset( $GLOBALS['test_transients'][ $key ] ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class Fake_WPDB {
	public $prefix = 'wp_';
	public $table_exists = true;
	public $rows = array();
	public $last_query = '';
	public function esc_like( $value ) { return $value; }
	public function prepare( $query, $value ) { return str_replace( '%s', "'" . str_replace( "'", "''", $value ) . "'", $query ); }
	public function get_var( $query ) { return $this->table_exists ? 'wp_fcal_meta' : null; }
	public function get_results( $query, $output ) {
		$this->last_query = $query;
		return $this->rows;
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

require dirname( __DIR__ ) . '/includes/class-kitmage-fbim-monitor.php';
$monitor = new Kitmage_FBIM_Monitor();
$monitor->register_hooks();

Kitmage_FBIM_Monitor::activate();
check_true( isset( $GLOBALS['test_cron'][ Kitmage_FBIM_Monitor::CRON_HOOK ] ), 'Scan scheduled' );
check_true( isset( $GLOBALS['test_cron'][ Kitmage_FBIM_Monitor::KEEPALIVE_CRON_HOOK ] ), 'Keep-alive scheduled' );
check_same( 2, count( $GLOBALS['test_cron'] ), 'Two distinct cron events' );
Kitmage_FBIM_Monitor::schedule();
check_same( 2, count( $GLOBALS['test_cron'] ), 'Init scheduling is duplicate safe' );
check_same( 1, get_option( Kitmage_FBIM_Monitor::SETTINGS_OPTION )['keepalive_enabled'], 'Default enabled' );

$missing = $monitor->keepalive();
check_same( 'classes_unavailable', $missing['state'], 'Missing Outlook classes do not fatal' );
check_same( 0, $missing['attempted'], 'No calls without classes' );

class Fake_Outlook_Calendar {
	public $lastError;
	public function __construct( $error = null ) { $this->lastError = $error; }
}
class Fake_Outlook_Helper {
	public static $calls = array();
	public static $responses = array();
	public static $throws = array();
	public static function getApiClientByUserId( $user_id ) {
		self::$calls[] = $user_id;
		if ( isset( self::$throws[ $user_id ] ) ) { throw new RuntimeException( self::$throws[ $user_id ] ); }
		return self::$responses[ $user_id ] ?? null;
	}
}
class_alias( 'Fake_Outlook_Calendar', 'FluentBookingPro\App\Services\Integrations\Calendars\Outlook\OutlookCalendar' );
class_alias( 'Fake_Outlook_Helper', 'FluentBookingPro\App\Services\Integrations\Calendars\Outlook\OutlookHelper' );

$GLOBALS['wpdb']->rows = array(
	array( 'user_id' => 11, 'outlook_email' => 'valid@example.com' ),
	array( 'user_id' => 12, 'outlook_email' => 'expired@example.com' ),
	array( 'user_id' => 13, 'outlook_email' => 'no-client@example.com' ),
	array( 'user_id' => 14, 'outlook_email' => 'throws@example.com' ),
);
Fake_Outlook_Helper::$responses = array(
	11 => new Fake_Outlook_Calendar(),
	12 => new Fake_Outlook_Calendar( new WP_Error( 'outlook_error', 'AADSTS700082: refresh_token=SECRET-DO-NOT-STORE' ) ),
);
Fake_Outlook_Helper::$throws = array( 14 => 'AADSTS50076: authorization=SECRET-DO-NOT-STORE' );

$result = $monitor->keepalive();
check_same( 'completed', $result['state'], 'Completed state' );
check_same( 4, $result['attempted'], 'All distinct users attempted' );
check_same( 1, $result['ok'], 'Valid count' );
check_same( 2, $result['failed'], 'Failure count' );
check_same( 1, $result['skipped'], 'Null client skipped' );
check_same( array( 11, 12, 13, 14 ), Fake_Outlook_Helper::$calls, 'Every user called, even without calendars' );
check_true( strpos( $GLOBALS['wpdb']->last_query, 'fcal_calendars' ) === false, 'No calendar join' );
check_true( strpos( $GLOBALS['wpdb']->last_query, 'fcal_meta' ) !== false, 'Reads token metadata table' );
check_true( strpos( $GLOBALS['wpdb']->last_query, '_outlook_user_token' ) !== false, 'Restricts to Outlook tokens' );
check_true( strpos( $GLOBALS['wpdb']->last_query, 'value' ) === false, 'Never selects token value' );
check_same( array( 'user_id', 'calendar_email', 'error_code' ), array_keys( $result['failures'][0] ), 'Failures contain only allowed fields' );
check_same( 'AADSTS700082', $result['failures'][0]['error_code'], 'Stored AADSTS from lastError' );
check_same( 'AADSTS50076', $result['failures'][1]['error_code'], 'Stored AADSTS from exception' );
$persisted = json_encode( get_option( Kitmage_FBIM_Monitor::KEEPALIVE_STATUS_OPTION ) );
check_true( strpos( $persisted, 'SECRET-DO-NOT-STORE' ) === false, 'No credential or raw error in status' );
check_true( strpos( $persisted, 'refresh_token' ) === false, 'No token field in status' );

$settings = get_option( Kitmage_FBIM_Monitor::SETTINGS_OPTION );
$settings['keepalive_enabled'] = 0;
update_option( Kitmage_FBIM_Monitor::SETTINGS_OPTION, $settings );
check_true( ! isset( $GLOBALS['test_cron'][ Kitmage_FBIM_Monitor::KEEPALIVE_CRON_HOOK ] ), 'Disabling clears keep-alive cron' );
check_true( isset( $GLOBALS['test_cron'][ Kitmage_FBIM_Monitor::CRON_HOOK ] ), 'Disabling preserves scanner cron' );
$call_count = count( Fake_Outlook_Helper::$calls );
check_same( 'disabled', $monitor->keepalive()['state'], 'Disabled run returns state' );
check_same( $call_count, count( Fake_Outlook_Helper::$calls ), 'Disabled run never invokes clients' );

$settings['keepalive_enabled'] = 1;
update_option( Kitmage_FBIM_Monitor::SETTINGS_OPTION, $settings );
check_true( isset( $GLOBALS['test_cron'][ Kitmage_FBIM_Monitor::KEEPALIVE_CRON_HOOK ] ), 'Re-enabling reschedules' );
add_filter( 'kitmage_fbim_keepalive_interval', static function () { return 1; } );
check_same( 3600, $monitor->cron_schedules( array() )[ Kitmage_FBIM_Monitor::KEEPALIVE_CRON_SCHEDULE ]['interval'], 'Keep-alive minimum 3600s' );
Kitmage_FBIM_Monitor::deactivate();
check_same( 0, count( $GLOBALS['test_cron'] ), 'Deactivation clears both events' );

echo "Keep-alive smoke tests passed.\n";
