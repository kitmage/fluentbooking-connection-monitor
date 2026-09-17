<?php

defined( 'ABSPATH' ) || exit;

/** Core scheduler, read-only FluentBooking discovery, incident state and mailer. */
final class Aspen_FBIM_Monitor {
	const CRON_HOOK       = 'aspen_fbim_scan_event';
	const CRON_SCHEDULE   = 'aspen_fbim_every_fifteen_minutes';
	const SETTINGS_OPTION = 'aspen_fbim_settings';
	const INCIDENT_OPTION = 'aspen_fbim_incidents';
	const STATUS_OPTION   = 'aspen_fbim_status';
	const LOCK_TRANSIENT  = 'aspen_fbim_scan_lock';

	public static function defaults() {
		return array(
			'recipients' => '[admin_email]',
			'subject'    => 'FluentBooking Outlook Connection Error — [calendar_email]',
			'body'       => "A FluentBooking Outlook calendar connection error has been detected.\n\nCalendar: [calendar_name]\nCalendar Email: [calendar_email]\nCalendar ID: [calendar_id]\n\nError Code: [error_code]\n\nError:\n[error_message]\n\nDetected:\n[detected_at]\n\nReview the calendar connection:\n[review_url]\n",
		);
	}

	public static function activate() {
		if ( false === get_option( self::SETTINGS_OPTION, false ) ) {
			add_option( self::SETTINGS_OPTION, self::defaults(), '', false );
		}
		// Activation runs after plugins_loaded, so make the custom recurrence available explicitly.
		$monitor = new self();
		add_filter( 'cron_schedules', array( $monitor, 'cron_schedules' ) );
		self::schedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_transient( self::LOCK_TRANSIENT );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	public function register_hooks() {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( $this, 'scan' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public function cron_schedules( $schedules ) {
		$interval = max( 60, (int) apply_filters( 'aspen_fbim_scan_interval', 15 * MINUTE_IN_SECONDS ) );
		$schedules[ self::CRON_SCHEDULE ] = array( 'interval' => $interval, 'display' => __( 'Every 15 minutes (Booking Monitor)', 'aspen-fluentbooking-integration-monitor' ) );
		return $schedules;
	}

	/**
	 * Run one scan. This same entry point is used by cron and the manual action.
	 *
	 * @return array<string,mixed>
	 */
	public function scan() {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return array( 'ok' => false, 'message' => __( 'A Booking Monitor scan is already running.', 'aspen-fluentbooking-integration-monitor' ) );
		}
		set_transient( self::LOCK_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );
		$result = array( 'ok' => true, 'calendars' => 0, 'integrations' => 0, 'active_errors' => 0, 'notifications' => 0, 'mail_failures' => 0, 'message' => '' );
		try {
			$discovery = $this->discover();
			if ( is_wp_error( $discovery ) ) {
				$result['ok']      = false;
				$result['message'] = $discovery->get_error_message();
				return $this->finish_scan( $result );
			}
			$result['calendars']    = count( $discovery );
			$result['integrations'] = count( $discovery );
			$incidents              = get_option( self::INCIDENT_OPTION, array() );
			$incidents              = is_array( $incidents ) ? $incidents : array();
			$seen                    = array();

			foreach ( $discovery as $calendar ) {
				try {
					$error = $this->detect_error( $calendar );
					if ( ! $error ) {
						continue;
					}
					++$result['active_errors'];
					$key          = $this->incident_key( $calendar, $error );
					$seen[ $key ] = true;
					$now          = time();
					if ( isset( $incidents[ $key ] ) && ! empty( $incidents[ $key ]['active'] ) ) {
						$incidents[ $key ]['last_detected'] = $now;
						continue;
					}
					$incident = array(
						'key' => $key, 'calendar_id' => (string) $calendar['id'], 'calendar_name' => $calendar['name'],
						'calendar_email' => $calendar['email'], 'provider' => 'microsoft', 'error_code' => $error['code'],
						'error_message' => $error['message'], 'first_detected' => $now, 'last_detected' => $now,
						'notified_at' => 0, 'mail_success' => false, 'active' => true, 'resolved_at' => 0,
					);
					$mail = $this->notify( $incident );
					$incident['mail_success'] = $mail;
					$incident['notified_at']  = time(); // Attempt recorded; avoid a failing mail transport causing a 15-minute mail storm.
					$mail ? ++$result['notifications'] : ++$result['mail_failures'];
					$incidents[ $key ] = $incident;
				} catch ( Throwable $e ) {
					// A malformed calendar must not prevent remaining calendars from being inspected.
					continue;
				}
			}

			foreach ( $incidents as $key => &$incident ) {
				if ( ! empty( $incident['active'] ) && ! isset( $seen[ $key ] ) ) {
					$incident['active']      = false;
					$incident['resolved_at'] = time();
				}
			}
			unset( $incident );
			if ( count( $incidents ) > 200 ) {
				uasort( $incidents, static function ( $a, $b ) { return (int) $b['last_detected'] <=> (int) $a['last_detected']; } );
				$incidents = array_slice( $incidents, 0, 200, true );
			}
			update_option( self::INCIDENT_OPTION, $incidents, false );
		} catch ( Throwable $e ) {
			$result['ok']      = false;
			$result['message'] = __( 'The scan could not be completed because FluentBooking returned unexpected data.', 'aspen-fluentbooking-integration-monitor' );
		}
		return $this->finish_scan( $result );
	}

	private function finish_scan( $result ) {
		$result['scanned_at'] = time();
		update_option( self::STATUS_OPTION, $result, false );
		delete_transient( self::LOCK_TRANSIENT );
		do_action( 'aspen_fbim_scan_completed', $result );
		return $result;
	}

	/**
	 * Read-only discovery. FluentBooking has no public monitoring API. Its calendar and
	 * calendar-meta table schemas have varied, so columns are discovered rather than
	 * assuming a version-specific model. No token is retained or returned.
	 */
	private function discover() {
		global $wpdb;
		$base = $wpdb->prefix . 'fluent_booking_';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifier prefix is generated by WordPress.
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $base ) . '%' ) );
		if ( empty( $tables ) ) {
			return new WP_Error( 'aspen_fbim_unavailable', __( 'FluentBooking data was not found. Confirm that FluentBooking is active and has created its database tables.', 'aspen-fluentbooking-integration-monitor' ) );
		}
		$calendar_table = '';
		foreach ( $tables as $table ) {
			if ( preg_match( '/fluent_booking_calendars$/', $table ) ) { $calendar_table = $table; break; }
		}
		if ( ! $calendar_table ) {
			return new WP_Error( 'aspen_fbim_schema', __( 'The installed FluentBooking calendar schema is not supported by this monitor.', 'aspen-fluentbooking-integration-monitor' ) );
		}
		// Table identifiers originate exclusively from SHOW TABLES and are backtick escaped.
		$safe_calendar = '`' . str_replace( '`', '``', $calendar_table ) . '`';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$calendar_rows = $wpdb->get_results( "SELECT * FROM {$safe_calendar}", ARRAY_A );
		$snapshots = array();
		foreach ( (array) $calendar_rows as $row ) {
			$id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( '' === $id ) { continue; }
			$related = array();
			foreach ( $tables as $table ) {
				if ( $table === $calendar_table || false === stripos( $table, 'meta' ) ) { continue; }
				$safe = '`' . str_replace( '`', '``', $table ) . '`';
				// Determine relation columns from the live schema; never assume one FluentBooking release.
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$columns = $wpdb->get_col( "DESCRIBE {$safe}" );
				foreach ( array( 'calendar_id', 'object_id', 'model_id' ) as $relation ) {
					if ( in_array( $relation, (array) $columns, true ) ) {
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifier is allow-listed above.
						$related = array_merge( $related, (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$safe} WHERE `{$relation}` = %s", $id ), ARRAY_A ) );
						break;
					}
				}
			}
			$all_text = wp_json_encode( array( $row, $related ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! preg_match( '/outlook|microsoft|office[ _-]?365|aadsts|invalid_grant/i', (string) $all_text ) ) { continue; }
			$snapshots[] = array(
				'id' => $id, 'name' => $this->first_value( $row, array( 'title', 'name', 'event_title' ), 'Calendar ' . $id ),
				'email' => $this->calendar_email( $row, $related ), 'diagnostic_text' => $this->safe_diagnostic_text( $all_text ),
			);
		}
		/** Filters sanitized calendar snapshots; useful for version-specific FluentBooking adapters. */
		return apply_filters( 'aspen_fbim_calendar_snapshots', $snapshots, $calendar_rows, $tables );
	}

	private function calendar_email( $calendar, $related ) {
		// Prefer the connected Outlook account's email in related integration metadata.
		foreach ( $related as $record ) {
			$text = wp_json_encode( $record );
			if ( preg_match( '/outlook|microsoft|office[ _-]?365/i', $text ) && preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m ) && is_email( $m[0] ) ) { return sanitize_email( $m[0] ); }
		}
		// FluentBooking calendar/owner email is the explicit documented fallback.
		foreach ( array( 'email', 'host_email', 'author_email', 'owner_email' ) as $key ) {
			if ( ! empty( $calendar[ $key ] ) && is_email( $calendar[ $key ] ) ) { return sanitize_email( $calendar[ $key ] ); }
		}
		return '';
	}

	private function first_value( $row, $keys, $fallback ) {
		foreach ( $keys as $key ) { if ( ! empty( $row[ $key ] ) && is_scalar( $row[ $key ] ) ) { return sanitize_text_field( (string) $row[ $key ] ); } }
		return $fallback;
	}

	private function safe_diagnostic_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		// Remove credential-bearing JSON fields and common bearer/JWT/token strings before any persistence or mail.
		$text = preg_replace( '/["\']?(?:access[_ -]?token|refresh[_ -]?token|client[_ -]?secret|authorization)["\']?\s*[:=]\s*["\']?[^"\',}\s]+/i', '[redacted]', $text );
		$text = preg_replace( '/\bBearer\s+\S+|\beyJ[A-Za-z0-9_\-.]{20,}/i', '[redacted]', $text );
		return substr( $text, 0, 12000 );
	}

	private function detect_error( $calendar ) {
		$text = (string) $calendar['diagnostic_text'];
		$is_error = (bool) preg_match( '/Outlook Calendar API Error|AADSTS\d+|invalid_grant|(?:microsoft|outlook|entra).{0,100}(?:auth|token|grant).{0,80}(?:error|expired|invalid|revoked|fail)/is', $text );
		$is_error = (bool) apply_filters( 'aspen_fbim_is_detected_error', $is_error, $text, $calendar );
		if ( ! $is_error ) { return null; }
		$code = '';
		if ( preg_match( '/\b(AADSTS\d+)\b/i', $text, $match ) ) { $code = strtoupper( $match[1] ); }
		elseif ( preg_match( '/\binvalid_grant\b/i', $text ) ) { $code = 'invalid_grant'; }
		$message = $this->extract_message( $text );
		return array( 'code' => $code, 'message' => $message );
	}

	private function extract_message( $text ) {
		if ( preg_match( '/(?:Outlook Calendar API Error\s*:\s*)?((?:AADSTS\d+|invalid_grant)\s*:\s*[^"}\]\r\n]{1,1000})/i', $text, $m ) ) { return sanitize_textarea_field( $m[1] ); }
		if ( preg_match( '/(Outlook Calendar API Error[^"}\]\r\n]{0,1000})/i', $text, $m ) ) { return sanitize_textarea_field( $m[1] ); }
		return __( 'A Microsoft/Outlook authentication or calendar API failure was reported by FluentBooking.', 'aspen-fluentbooking-integration-monitor' );
	}

	private function incident_key( $calendar, $error ) {
		$identity = $error['code'] ? strtolower( $error['code'] ) : strtolower( preg_replace( '/\d{4}-\d\d-\d\d|\b[0-9a-f-]{16,}\b/i', '', $error['message'] ) );
		return hash( 'sha256', $calendar['id'] . '|microsoft|' . $identity );
	}

	public function template_variables( $incident ) {
		$format = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$variables = array(
			'[calendar_id]' => $incident['calendar_id'], '[calendar_name]' => $incident['calendar_name'], '[calendar_email]' => $incident['calendar_email'],
			'[error_code]' => $incident['error_code'], '[error_message]' => $incident['error_message'],
			'[detected_at]' => wp_date( $format, (int) $incident['first_detected'] ),
			'[review_url]' => admin_url( 'admin.php?page=fluent-booking#/calendars/' . rawurlencode( $incident['calendar_id'] ) . '/settings/remote-calendars' ),
			'[site_name]' => get_bloginfo( 'name' ), '[site_url]' => home_url( '/' ), '[admin_email]' => get_option( 'admin_email' ),
		);
		return apply_filters( 'aspen_fbim_template_variables', $variables, $incident );
	}

	public function render_template( $template, $incident ) { return strtr( (string) $template, $this->template_variables( $incident ) ); }

	private function notify( $incident ) {
		$settings = wp_parse_args( get_option( self::SETTINGS_OPTION, array() ), self::defaults() );
		$raw       = apply_filters( 'aspen_fbim_notification_recipients', $this->render_template( $settings['recipients'], $incident ), $incident );
		$emails    = array_values( array_unique( array_filter( array_map( 'sanitize_email', preg_split( '/[,;\s]+/', (string) $raw ) ), 'is_email' ) ) );
		if ( ! $emails ) { return false; }
		$subject = apply_filters( 'aspen_fbim_notification_subject', $this->render_template( $settings['subject'], $incident ), $incident );
		$body    = apply_filters( 'aspen_fbim_notification_body', $this->render_template( $settings['body'], $incident ), $incident );
		$send    = apply_filters( 'aspen_fbim_send_notification', true, $emails, $subject, $body, $incident );
		if ( ! $send ) { return false; }
		return (bool) wp_mail( $emails, wp_strip_all_tags( $subject ), $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}
}
