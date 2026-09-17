<?php
/** Minimal standalone regression test for the confirmed FluentBooking schema adapter. */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
}

function __( $value ) { return $value; }
function apply_filters( $hook, $value ) { return $value; }
function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function is_serialized( $value ) { return is_string( $value ) && preg_match( '/^[aObisdN]:/', trim( $value ) ); }
function is_email( $value ) { return (bool) filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function wp_kses_post( $value ) { return $value; }
function wpautop( $value ) { return '<p>' . str_replace( "\n\n", '</p><p>', $value ) . '</p>'; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function get_option( $name ) {
	if ( 'kitmage_fbim_settings' === $name ) { return array( 'recipients' => 'admin@example.com', 'subject' => 'Error [calendar_email]', 'body' => '<strong>[calendar_name]</strong><br>[error_message]' ); }
	if ( 'date_format' === $name ) { return 'Y-m-d'; }
	if ( 'time_format' === $name ) { return 'H:i'; }
	if ( 'admin_email' === $name ) { return 'admin@example.com'; }
	return '';
}
function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
function admin_url( $path ) { return 'https://example.com/wp-admin/' . $path; }
function home_url() { return 'https://example.com/'; }
function get_bloginfo() { return 'Example'; }
function wp_mail( $emails, $subject, $body, $headers ) {
	$GLOBALS['kitmage_fbim_test_mail'] = compact( 'emails', 'subject', 'body', 'headers' );
	return true;
}

final class Kitmage_FBIM_Test_WPDB {
	public $prefix = 'site_';
	public $queries = array();
	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) { $query = preg_replace( '/%s/', "'" . addslashes( $arg ) . "'", $query, 1 ); }
		return $query;
	}
	public function get_var( $query ) {
		$this->queries[] = $query;
		if ( false !== strpos( $query, 'site\\_fcal\\_calendars' ) ) { return 'site_fcal_calendars'; }
		if ( false !== strpos( $query, 'site\\_fcal\\_meta' ) ) { return 'site_fcal_meta'; }
		if ( false !== strpos( $query, 'COUNT(*)' ) ) { return '2'; }
		return null;
	}
	public function get_results( $query ) {
		$this->queries[] = $query;
		return array(
			array(
				'calendar_id' => '14', 'user_id' => '74', 'title' => 'Alli Megill MS, BCBA, LBA',
				'outlook_meta_id' => '9', 'outlook_email' => 'Alli@kitmagebehavioral.com',
				'outlook_state' => '{"access_token":"NEVER-RETURN-THIS","error":"Outlook Calendar API Error: AADSTS50173: The provided grant has expired due to it being revoked"}',
				'outlook_updated_at' => '2026-09-17 12:00:00',
			),
			array(
				'calendar_id' => '15', 'user_id' => '74', 'title' => 'Alli Follow-up Calendar',
				'outlook_meta_id' => '9', 'outlook_email' => 'Alli@kitmagebehavioral.com',
				'outlook_state' => serialize( array( 'refresh_token' => 'ALSO-SECRET', 'error' => 'invalid_grant: Microsoft token was revoked' ) ),
				'outlook_updated_at' => '2026-09-17 12:00:00',
			),
		);
	}
}

require dirname( __DIR__ ) . '/includes/class-kitmage-fbim-monitor.php';

$GLOBALS['wpdb'] = new Kitmage_FBIM_Test_WPDB();
$method = new ReflectionMethod( 'Kitmage_FBIM_Monitor', 'discover' );
$method->setAccessible( true );
$result = $method->invoke( new Kitmage_FBIM_Monitor() );
$snapshot = $result['snapshots'][0];

assert( 2 === $result['calendars_total'] );
assert( '14' === $snapshot['id'] );
assert( '74' === $snapshot['user_id'] );
assert( 'Alli Megill MS, BCBA, LBA' === $snapshot['name'] );
assert( 'Alli@kitmagebehavioral.com' === $snapshot['email'] );
assert( 'AADSTS50173' === $snapshot['error_code'] );
assert( false !== strpos( $snapshot['error_message'], 'expired' ) );
assert( 2 === count( $result['snapshots'] ) );
assert( 'invalid_grant' === $result['snapshots'][1]['error_code'] );
assert( false === strpos( serialize( $result ), 'NEVER-RETURN-THIS' ) );
assert( false === strpos( serialize( $result ), 'ALSO-SECRET' ) );
assert( false !== strpos( implode( "\n", $GLOBALS['wpdb']->queries ), "m.object_type = '_outlook_user_token'" ) );
assert( false === strpos( implode( "\n", $GLOBALS['wpdb']->queries ), 'fluent_booking_' ) );

$notify = new ReflectionMethod( 'Kitmage_FBIM_Monitor', 'notify' );
$notify->setAccessible( true );
$sent = $notify->invoke(
	new Kitmage_FBIM_Monitor(),
	array(
		'calendar_id' => '14', 'calendar_name' => 'Calendar <One>', 'calendar_email' => 'owner@example.com',
		'error_code' => 'AADSTS50173', 'error_message' => '<script>unsafe()</script>', 'first_detected' => 1,
	)
);
assert( true === $sent );
assert( in_array( 'Content-Type: text/html; charset=UTF-8', $GLOBALS['kitmage_fbim_test_mail']['headers'], true ) );
assert( false !== strpos( $GLOBALS['kitmage_fbim_test_mail']['body'], '<strong>Calendar &lt;One&gt;</strong>' ) );
assert( false === strpos( $GLOBALS['kitmage_fbim_test_mail']['body'], '<script>' ) );

echo "Discovery regression test passed.\n";
