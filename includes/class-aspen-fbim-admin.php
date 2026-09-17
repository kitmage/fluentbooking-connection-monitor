<?php

defined( 'ABSPATH' ) || exit;

final class Aspen_FBIM_Admin {
	private $monitor;
	public function __construct( Aspen_FBIM_Monitor $monitor ) { $this->monitor = $monitor; }
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_post_aspen_fbim_scan', array( $this, 'run_scan' ) );
		add_action( 'admin_post_aspen_fbim_purge', array( $this, 'purge' ) );
	}
	public function menu() {
		add_management_page( __( 'Booking Monitor', 'aspen-fluentbooking-integration-monitor' ), __( 'Booking Monitor', 'aspen-fluentbooking-integration-monitor' ), 'manage_options', 'aspen-fbim', array( $this, 'page' ) );
	}
	public function settings() {
		register_setting( 'aspen_fbim', Aspen_FBIM_Monitor::SETTINGS_OPTION, array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize_settings' ), 'default' => Aspen_FBIM_Monitor::defaults() ) );
		add_settings_section( 'aspen_fbim_notifications', __( 'Notification Settings', 'aspen-fluentbooking-integration-monitor' ), array( $this, 'section_help' ), 'aspen-fbim' );
		foreach ( array( 'recipients' => __( 'Recipients', 'aspen-fluentbooking-integration-monitor' ), 'subject' => __( 'Subject', 'aspen-fluentbooking-integration-monitor' ), 'body' => __( 'Body', 'aspen-fluentbooking-integration-monitor' ) ) as $key => $label ) {
			add_settings_field( 'aspen_fbim_' . $key, $label, array( $this, 'field' ), 'aspen-fbim', 'aspen_fbim_notifications', array( 'key' => $key ) );
		}
	}
	public function sanitize_settings( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) { return get_option( Aspen_FBIM_Monitor::SETTINGS_OPTION, Aspen_FBIM_Monitor::defaults() ); }
		$defaults = Aspen_FBIM_Monitor::defaults();
		return array(
			'recipients' => sanitize_text_field( isset( $input['recipients'] ) ? wp_unslash( $input['recipients'] ) : $defaults['recipients'] ),
			'subject' => sanitize_text_field( isset( $input['subject'] ) ? wp_unslash( $input['subject'] ) : $defaults['subject'] ),
			'body' => sanitize_textarea_field( isset( $input['body'] ) ? wp_unslash( $input['body'] ) : $defaults['body'] ),
		);
	}
	public function section_help() {
		echo '<p>' . esc_html__( 'Comma-separate recipients. Invalid rendered addresses are discarded. Available variables:', 'aspen-fluentbooking-integration-monitor' ) . ' <code>' . esc_html( '[calendar_id] [calendar_name] [calendar_email] [error_code] [error_message] [detected_at] [review_url] [site_name] [site_url] [admin_email]' ) . '</code></p>';
	}
	public function field( $args ) {
		$key = $args['key']; $value = wp_parse_args( get_option( Aspen_FBIM_Monitor::SETTINGS_OPTION, array() ), Aspen_FBIM_Monitor::defaults() )[ $key ];
		$name = Aspen_FBIM_Monitor::SETTINGS_OPTION . '[' . $key . ']';
		if ( 'body' === $key ) { printf( '<textarea class="large-text code" rows="14" name="%1$s">%2$s</textarea>', esc_attr( $name ), esc_textarea( $value ) ); }
		else { printf( '<input class="regular-text" type="text" name="%1$s" value="%2$s">', esc_attr( $name ), esc_attr( $value ) ); }
	}
	public function run_scan() {
		$this->authorize( 'aspen_fbim_scan' );
		$result = $this->monitor->scan();
		if ( empty( $result['ok'] ) ) { $message = $result['message']; $type = 'error'; }
		elseif ( empty( $result['active_errors'] ) ) { $message = __( 'Scan complete. No Outlook calendar errors were detected.', 'aspen-fluentbooking-integration-monitor' ); $type = 'success'; }
		else {
			$message = sprintf( __( 'Scan complete. %1$d calendars checked. %2$d Outlook integrations checked. %3$d active errors found. %4$d new notifications sent.', 'aspen-fluentbooking-integration-monitor' ), $result['calendars'], $result['integrations'], $result['active_errors'], $result['notifications'] ); $type = $result['mail_failures'] ? 'warning' : 'success';
		}
		$this->redirect_notice( $message, $type );
	}
	public function purge() {
		$this->authorize( 'aspen_fbim_purge' );
		delete_option( Aspen_FBIM_Monitor::INCIDENT_OPTION );
		$this->redirect_notice( __( 'Stored Booking Monitor error history has been purged.', 'aspen-fluentbooking-integration-monitor' ), 'success' );
	}
	private function authorize( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to perform this action.', 'aspen-fluentbooking-integration-monitor' ), 403 ); }
		check_admin_referer( $action );
	}
	private function redirect_notice( $message, $type ) {
		set_transient( 'aspen_fbim_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'tools.php?page=aspen-fbim' ) ); exit;
	}
	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$notice = get_transient( 'aspen_fbim_notice_' . get_current_user_id() );
		if ( $notice ) { delete_transient( 'aspen_fbim_notice_' . get_current_user_id() ); printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) ); }
		$status = get_option( Aspen_FBIM_Monitor::STATUS_OPTION, array() ); $incidents = get_option( Aspen_FBIM_Monitor::INCIDENT_OPTION, array() );
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Booking Monitor', 'aspen-fluentbooking-integration-monitor' ); ?></h1>
		<form method="post" action="options.php"><?php settings_fields( 'aspen_fbim' ); do_settings_sections( 'aspen-fbim' ); submit_button(); ?></form>
		<hr><h2><?php esc_html_e( 'Monitor Actions', 'aspen-fluentbooking-integration-monitor' ); ?></h2>
		<div style="display:flex;gap:8px">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="aspen_fbim_scan"><?php wp_nonce_field( 'aspen_fbim_scan' ); ?><button class="button button-primary" type="submit"><?php esc_html_e( 'Run Scan Now', 'aspen-fluentbooking-integration-monitor' ); ?></button></form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Purge all Booking Monitor incident history? This does not alter FluentBooking.', 'aspen-fluentbooking-integration-monitor' ) ); ?>');"><input type="hidden" name="action" value="aspen_fbim_purge"><?php wp_nonce_field( 'aspen_fbim_purge' ); ?><button class="button" type="submit"><?php esc_html_e( 'Purge Previous Errors', 'aspen-fluentbooking-integration-monitor' ); ?></button></form>
		</div>
		<h2><?php esc_html_e( 'Status', 'aspen-fluentbooking-integration-monitor' ); ?></h2>
		<?php if ( empty( $status['scanned_at'] ) ) : ?><p><?php esc_html_e( 'No scan has run yet.', 'aspen-fluentbooking-integration-monitor' ); ?></p><?php else : ?>
		<ul><li><strong><?php esc_html_e( 'Last scan:', 'aspen-fluentbooking-integration-monitor' ); ?></strong> <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status['scanned_at'] ) ); ?></li>
		<li><strong><?php esc_html_e( 'Calendars / integrations / active errors:', 'aspen-fluentbooking-integration-monitor' ); ?></strong> <?php echo esc_html( (int) $status['calendars'] . ' / ' . (int) $status['integrations'] . ' / ' . (int) $status['active_errors'] ); ?></li>
		<li><strong><?php esc_html_e( 'Last successful notification:', 'aspen-fluentbooking-integration-monitor' ); ?></strong> <?php $sent = array_filter( (array) $incidents, static function ( $item ) { return ! empty( $item['mail_success'] ); } ); $times = array_column( $sent, 'notified_at' ); echo esc_html( $times ? $this->date( max( $times ) ) : __( 'None', 'aspen-fluentbooking-integration-monitor' ) ); ?></li></ul><?php endif; ?>
		<?php if ( $incidents ) : ?><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Calendar', 'aspen-fluentbooking-integration-monitor' ); ?></th><th><?php esc_html_e( 'Email', 'aspen-fluentbooking-integration-monitor' ); ?></th><th><?php esc_html_e( 'Code / message', 'aspen-fluentbooking-integration-monitor' ); ?></th><th><?php esc_html_e( 'First / last detected', 'aspen-fluentbooking-integration-monitor' ); ?></th><th><?php esc_html_e( 'Notification', 'aspen-fluentbooking-integration-monitor' ); ?></th><th><?php esc_html_e( 'Status', 'aspen-fluentbooking-integration-monitor' ); ?></th></tr></thead><tbody>
		<?php foreach ( array_reverse( $incidents ) as $incident ) : ?><tr><td><?php echo esc_html( $incident['calendar_name'] . ' (#' . $incident['calendar_id'] . ')' ); ?></td><td><?php echo esc_html( $incident['calendar_email'] ); ?></td><td><strong><?php echo esc_html( $incident['error_code'] ); ?></strong><br><?php echo esc_html( $incident['error_message'] ); ?></td><td><?php echo esc_html( $this->date( $incident['first_detected'] ) . ' / ' . $this->date( $incident['last_detected'] ) ); ?></td><td><?php echo esc_html( $incident['notified_at'] ? ( $incident['mail_success'] ? __( 'Sent ', 'aspen-fluentbooking-integration-monitor' ) : __( 'Failed ', 'aspen-fluentbooking-integration-monitor' ) ) . $this->date( $incident['notified_at'] ) : __( 'Not attempted', 'aspen-fluentbooking-integration-monitor' ) ); ?></td><td><?php echo esc_html( $incident['active'] ? __( 'Active', 'aspen-fluentbooking-integration-monitor' ) : __( 'Resolved', 'aspen-fluentbooking-integration-monitor' ) ); ?></td></tr><?php endforeach; ?>
		</tbody></table><?php endif; ?></div><?php
	}
	private function date( $timestamp ) { return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp ); }
}
