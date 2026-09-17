# Aspen FluentBooking Integration Monitor

A read-only WordPress monitoring plugin that detects Microsoft/Outlook errors already recorded in FluentBooking data, deduplicates incidents, and sends one plain-text notification per failure episode.

## Installation

1. Copy this directory to `wp-content/plugins/aspen-fluentbooking-integration-monitor`.
2. Activate **Aspen FluentBooking Integration Monitor** in **Plugins**.
3. Open **Tools → Booking Monitor**, review the recipients, subject, and body, then save.
4. Use **Run Scan Now** to verify discovery. WordPress mail must be configured separately.

PHP 7.4 or newer and a normal WordPress installation are required. FluentBooking remains independently installed and configured; this plugin never changes its records or credentials.

## FluentBooking discovery and assumptions

FluentBooking does not expose a documented public monitoring API. In addition, no FluentBooking installation/source tree is bundled with this repository. To avoid binding to invented model classes or a single plugin release, the scanner uses a defensive, read-only compatibility adapter:

* It discovers the live WordPress-prefixed `fluent_booking_*` tables.
* It reads the calendars table and discovers relation columns in FluentBooking metadata tables from their actual schema.
* It selects records containing Outlook/Microsoft/Office 365 provider signals and detects error strings that FluentBooking has persisted for its own Remote Calendars UI.
* It never invokes Microsoft directly, refreshes OAuth, or writes FluentBooking data.

This approach monitors persisted integration state rather than proactively testing Microsoft. If a FluentBooking release neither persists its displayed failure nor exposes it through these records, use `aspen_fbim_calendar_snapshots` to provide version-specific sanitized snapshots. A clear unsupported/unavailable message is shown rather than risking a fatal error.

### Calendar email selection

The scanner first looks for a valid email in the related record that identifies itself as Outlook/Microsoft/Office 365. This is the best representation of the connected remote account. If it is unavailable, it explicitly falls back to the calendar's `email`, `host_email`, `author_email`, or `owner_email` value, in that order. It does not substitute the current user or site administrator address for `[calendar_email]`.

## Detection and credential safety

Detection includes `Outlook Calendar API Error`, any `AADSTS` numeric code, `invalid_grant`, and broader Microsoft/Outlook/Entra authentication, token, grant, expiration, revocation, or failure wording. The AADSTS code is extracted when present. Only a short useful message is stored. Credential-bearing fields, bearer values, and JWT-like values are redacted before diagnostics are inspected; raw integration responses and OAuth records are never stored in monitor history or sent by email.

## Incidents and deduplication

An incident identity hashes the calendar ID, the Microsoft provider, and the extracted error code or normalized error identity. Its first occurrence is stored and triggers one `wp_mail()` attempt. Continued occurrences update `last_detected` without mailing again. When an error is absent on a later scan, the incident becomes resolved. A later recurrence creates a fresh active episode and notifies again. Mail attempts (including failures) are recorded to prevent a broken mail transport from creating a message storm. History is capped at 200 incidents.

**Purge Previous Errors** deletes only this plugin's incident option. It does not touch FluentBooking. Consequently, an error still present at the next scan is new and can notify again.

## Template variables

Recipients, subject, and body use controlled string replacement—not WordPress global shortcodes—and support:

* `[calendar_id]`, `[calendar_name]`, `[calendar_email]`
* `[error_code]`, `[error_message]`, `[detected_at]`
* `[review_url]`
* `[site_name]`, `[site_url]`, `[admin_email]`

The review URL is built with `admin_url()` and targets FluentBooking's Remote Calendars route. Rendered recipients are split on commas, semicolons, or whitespace and every invalid address is discarded.

## Scheduling

Activation schedules a duplicate-safe WP-Cron event. It runs every 15 minutes by default; deactivation unschedules it but retains settings and history. WP-Cron is traffic-driven, so low-traffic production sites should configure a real server scheduler to request `wp-cron.php` regularly for predictable monitoring. The plugin deliberately does not configure the server.

## Hooks

* `aspen_fbim_scan_interval` — recurrence in seconds (minimum 60; reschedule after changing it).
* `aspen_fbim_calendar_snapshots` — sanitized/version-specific discovery adapter.
* `aspen_fbim_is_detected_error` — alter error classification.
* `aspen_fbim_template_variables` — add or alter template replacements.
* `aspen_fbim_notification_recipients`, `aspen_fbim_notification_subject`, `aspen_fbim_notification_body` — alter rendered mail fields.
* `aspen_fbim_send_notification` — allow or suppress sending.
* `aspen_fbim_scan_completed` — observe summary results.

## Data removal

Deactivation preserves all monitor data. WordPress's **Delete** action runs `uninstall.php`, which removes only this plugin's settings, incidents, status, and cron hook. No FluentBooking table or Microsoft credential is changed.
