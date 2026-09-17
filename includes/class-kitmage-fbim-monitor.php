<?php

defined( 'ABSPATH' ) || exit;

/** Core scheduler, read-only FluentBooking discovery, incident state and mailer. */
final class Kitmage_FBIM_Monitor {
	const CRON_HOOK       = 'kitmage_fbim_scan_event';
	const CRON_SCHEDULE   = 'kitmage_fbim_every_fifteen_minutes';
	const SETTINGS_OPTION = 'kitmage_fbim_settings';
	const INCIDENT_OPTION = 'kitmage_fbim_incidents';
	const STATUS_OPTION   = 'kitmage_fbim_status';
	const LOCK_TRANSIENT  = 'kitmage_fbim_scan_lock';

	public static function defaults() {
		return array(
			'recipients' => '[admin_email]',
			'subject'    => 'FluentBooking Outlook Connection Error — [calendar_email]',
			'body'       => '<p>A FluentBooking Outlook calendar connection error has been detected.</p>
<p><strong>Calendar:</strong> [calendar_name]<br>
<strong>Calendar Email:</strong> [calendar_email]<br>
<strong>Calendar ID:</strong> [calendar_id]</p>
<p><strong>Error Code:</strong> [error_code]</p>
<p><strong>Error:</strong><br>
[error_message]</p>
<p><strong>Detected:</strong><br>
[detected_at]</p>
<p><a href="[review_url]">Review the calendar connection</a></p>',
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
		$interval = max( 60, (int) apply_filters( 'kitmage_fbim_scan_interval', 15 * MINUTE_IN_SECONDS ) );
		$schedules[ self::CRON_SCHEDULE ] = array( 'interval' => $interval, 'display' => __( 'Every 15 minutes (Booking Monitor)', 'kitmage-fluentbooking-integration-monitor' ) );
		return $schedules;
	}

	/**
	 * Run one scan. This same entry point is used by cron and the manual action.
	 *
	 * @return array<string,mixed>
	 */
	public function scan() {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return array( 'ok' => false, 'message' => __( 'A Booking Monitor scan is already running.', 'kitmage-fluentbooking-integration-monitor' ) );
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
			$result['calendars']    = (int) $discovery['calendars_total'];
			$result['integrations'] = count( $discovery['snapshots'] );
			$incidents              = get_option( self::INCIDENT_OPTION, array() );
			$incidents              = is_array( $incidents ) ? $incidents : array();
			$seen                    = array();

			foreach ( $discovery['snapshots'] as $calendar ) {
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
			$result['message'] = __( 'The scan could not be completed because FluentBooking returned unexpected data.', 'kitmage-fluentbooking-integration-monitor' );
		}
		return $this->finish_scan( $result );
	}

	private function finish_scan( $result ) {
		$result['scanned_at'] = time();
		update_option( self::STATUS_OPTION, $result, false );
		delete_transient( self::LOCK_TRANSIENT );
		do_action( 'kitmage_fbim_scan_completed', $result );
		return $result;
	}

	/**
	 * Read the confirmed FluentBooking calendar-to-Outlook relationship. The raw meta
	 * value is handled only long enough to extract a bounded, redacted error and is
	 * never returned, persisted, logged, or included in an exception.
	 */
	private function discover() {
		global $wpdb;
		$calendar_table = $wpdb->prefix . 'fcal_calendars';
		$meta_table     = $wpdb->prefix . 'fcal_meta';

		$calendar_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $calendar_table ) ) );
		$meta_exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $meta_table ) ) );
		if ( $calendar_table !== $calendar_exists || $meta_table !== $meta_exists ) {
			return new WP_Error( 'kitmage_fbim_unavailable', __( 'FluentBooking data was not found. Confirm that FluentBooking is active and has created its database tables.', 'kitmage-fluentbooking-integration-monitor' ) );
		}

		// Both identifiers are composed solely from WordPress's trusted table prefix and fixed suffixes.
		$safe_calendar = '`' . str_replace( '`', '``', $calendar_table ) . '`';
		$safe_meta     = '`' . str_replace( '`', '``', $meta_table ) . '`';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted identifiers; value is prepared.
		$query = $wpdb->prepare(
			"SELECT c.id AS calendar_id, c.user_id, c.title, m.id AS outlook_meta_id, m.`key` AS outlook_email, m.`value` AS outlook_state, m.updated_at AS outlook_updated_at
			FROM {$safe_calendar} c
			INNER JOIN {$safe_meta} m ON m.object_id = c.user_id AND m.object_type = %s",
			'_outlook_user_token'
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query is prepared immediately above.
		$rows = $wpdb->get_results( $query, ARRAY_A );
		if ( null === $rows ) {
			return new WP_Error( 'kitmage_fbim_query', __( 'FluentBooking Outlook connections could not be read.', 'kitmage-fluentbooking-integration-monitor' ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted identifier, no values.
		$calendar_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$safe_calendar}" );
		$snapshots = array();
		foreach ( $rows as $row ) {
			$error = $this->extract_outlook_error( isset( $row['outlook_state'] ) ? (string) $row['outlook_state'] : '' );
			unset( $row['outlook_state'] );
			$snapshots[] = array(
				'id'                       => (string) $row['calendar_id'],
				'user_id'                  => (string) $row['user_id'],
				'name'                     => sanitize_text_field( (string) $row['title'] ),
				'email'                    => sanitize_email( (string) $row['outlook_email'] ),
				'error_code'               => $error['code'],
				'error_message'            => $error['message'],
				'outlook_state_updated_at' => sanitize_text_field( (string) $row['outlook_updated_at'] ),
			);
		}
		/** Filters safe snapshots. Raw token/state values are intentionally unavailable. */
		$snapshots = apply_filters( 'kitmage_fbim_calendar_snapshots', $snapshots );
		return array( 'calendars_total' => $calendar_count, 'snapshots' => is_array( $snapshots ) ? $snapshots : array() );
	}

	private function extract_outlook_error( $raw ) {
		$candidates = array();
		$decoded    = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() && is_serialized( $raw ) ) {
			$decoded = @unserialize( trim( $raw ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		}
		if ( is_array( $decoded ) ) {
			$this->collect_error_candidates( $decoded, $candidates );
		}
		// The bounded raw fallback supports errors embedded in otherwise opaque state formats.
		$candidates[] = $this->bounded_error_context( $raw );
		foreach ( $candidates as $candidate ) {
			if ( ! $this->is_outlook_error_text( $candidate ) ) { continue; }
			$candidate = $this->redact_sensitive_text( $candidate );
			$code = '';
			if ( preg_match( '/\b(AADSTS\d+)\b/i', $candidate, $match ) ) { $code = strtoupper( $match[1] ); }
			elseif ( preg_match( '/\binvalid_grant\b/i', $candidate ) ) { $code = 'invalid_grant'; }
			return array( 'code' => $code, 'message' => $this->extract_message( $candidate ) );
		}
		return array( 'code' => '', 'message' => '' );
	}

	private function collect_error_candidates( $value, &$candidates, $key = '', $depth = 0 ) {
		if ( $depth > 8 || count( $candidates ) >= 100 ) { return; }
		if ( is_array( $value ) ) {
			foreach ( $value as $child_key => $child ) { $this->collect_error_candidates( $child, $candidates, (string) $child_key, $depth + 1 ); }
		} elseif ( ! preg_match( '/(?:access|refresh|id)?_?token|authorization|client_?secret/i', (string) $key ) && is_scalar( $value ) && $this->is_outlook_error_text( (string) $value ) ) {
			$candidates[] = substr( (string) $value, 0, 2000 );
		}
	}

	private function bounded_error_context( $raw ) {
		if ( ! preg_match( '/Outlook Calendar API Error|AADSTS\d+|invalid_grant|(?:Microsoft|Outlook|Entra).{0,160}(?:expired|revoked|invalid|failed|failure)/is', $raw, $match, PREG_OFFSET_CAPTURE ) ) { return ''; }
		$offset = max( 0, (int) $match[0][1] - 80 );
		return substr( $raw, $offset, 1200 );
	}

	private function redact_sensitive_text( $text ) {
		$text = substr( wp_strip_all_tags( (string) $text ), 0, 2000 );
		$text = preg_replace( '/["\']?(?:access[_ -]?token|refresh[_ -]?token|id[_ -]?token|token|client[_ -]?secret|authorization)["\']?\s*(?::|=|;s:\d+:)\s*["\']?[^"\',}\s;]+/i', '[redacted]', $text );
		$text = preg_replace( '/\bBearer\s+\S+|\beyJ[A-Za-z0-9_\-.]{20,}/i', '[redacted]', $text );
		return sanitize_textarea_field( $text );
	}

	private function detect_error( $calendar ) {
		$text     = trim( (string) $calendar['error_code'] . ' ' . (string) $calendar['error_message'] );
		$is_error = '' !== trim( (string) $calendar['error_message'] );
		$is_error = (bool) apply_filters( 'kitmage_fbim_is_detected_error', $is_error, $text, $calendar );
		if ( ! $is_error ) { return null; }
		return array( 'code' => (string) $calendar['error_code'], 'message' => (string) $calendar['error_message'] );
	}

	private function is_outlook_error_text( $text ) {
		return (bool) preg_match( '/Outlook Calendar API Error|AADSTS\d+|invalid_grant|(?:Microsoft|Outlook|Entra).{0,160}(?:auth|token|grant).{0,100}(?:error|expired|invalid|revoked|fail)/is', (string) $text );
	}

	private function extract_message( $text ) {
		if ( preg_match( '/(?:Outlook Calendar API Error\s*:\s*)?((?:AADSTS\d+|invalid_grant)\s*:\s*[^"}\]\r\n]{1,1000})/i', $text, $m ) ) { return sanitize_textarea_field( $m[1] ); }
		if ( preg_match( '/(Outlook Calendar API Error[^"}\]\r\n]{0,1000})/i', $text, $m ) ) { return sanitize_textarea_field( $m[1] ); }
		return __( 'A Microsoft/Outlook authentication or calendar API failure was reported by FluentBooking.', 'kitmage-fluentbooking-integration-monitor' );
	}

	private function incident_key( $calendar, $error ) {
		$identity = $error['code'] ? strtolower( $error['code'] ) : strtolower( preg_replace( '/\d{4}-\d\d-\d\d|\b[0-9a-f-]{16,}\b/i', '', $error['message'] ) );
		return hash( 'sha256', $calendar['id'] . '|microsoft|' . strtolower( $calendar['email'] ) . '|' . $identity );
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
		return apply_filters( 'kitmage_fbim_template_variables', $variables, $incident );
	}

	public function render_template( $template, $incident, $context = 'text' ) {
		$variables = $this->template_variables( $incident );
		if ( 'html' === $context ) {
			$variables = array_map( 'esc_html', $variables );
		}
		return strtr( (string) $template, $variables );
	}

	private function notify( $incident ) {
		$settings = wp_parse_args( get_option( self::SETTINGS_OPTION, array() ), self::defaults() );
		$raw       = apply_filters( 'kitmage_fbim_notification_recipients', $this->render_template( $settings['recipients'], $incident ), $incident );
		$emails    = array_values( array_unique( array_filter( array_map( 'sanitize_email', preg_split( '/[,;\s]+/', (string) $raw ) ), 'is_email' ) ) );
		if ( ! $emails ) { return false; }
		$subject = apply_filters( 'kitmage_fbim_notification_subject', $this->render_template( $settings['subject'], $incident ), $incident );
		$body    = wp_kses_post( wpautop( $this->render_template( $settings['body'], $incident, 'html' ) ) );
		$body    = wp_kses_post( apply_filters( 'kitmage_fbim_notification_body', $body, $incident ) );
		$send    = apply_filters( 'kitmage_fbim_send_notification', true, $emails, $subject, $body, $incident );
		if ( ! $send ) { return false; }
		return (bool) wp_mail( $emails, wp_strip_all_tags( $subject ), $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
