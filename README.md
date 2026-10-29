# Kitmage FluentBooking Integration Monitor

A WordPress plugin that monitors Microsoft/Outlook errors already recorded in FluentBooking data, deduplicates incidents, and sends one formatted HTML notification per failure episode. An optional, enabled-by-default weekly keep-alive also exercises idle Outlook connections through FluentBooking's own OAuth client.

## Installation

1. Copy this directory to `wp-content/plugins/kitmage-fluentbooking-integration-monitor`.
2. Activate **Kitmage FluentBooking Integration Monitor** in **Plugins**.
3. Open **Tools → Booking Monitor**, review the recipients, subject, and body, then save.
4. Use **Run Scan Now** to verify discovery. WordPress mail must be configured separately.

PHP 7.4 or newer and a normal WordPress installation are required. FluentBooking remains independently installed and configured. Monitoring remains read-only; when keep-alive is enabled, FluentBooking's own classes may rotate and persist OAuth tokens and their `last_error` state.

## FluentBooking discovery and assumptions

The target FluentBooking schema stores calendars in `{prefix}fcal_calendars` and integration metadata in `{prefix}fcal_meta`. The scanner uses the confirmed, read-only relationship rather than guessing model classes or searching unrelated tables:

* It joins `fcal_calendars.user_id` to `fcal_meta.object_id` only where `object_type = '_outlook_user_token'`.
* Every matching token/state row is associated with every calendar belonging to that user, so owners with multiple calendars are handled correctly.
* It inspects only the matching row's `value` for error strings that FluentBooking persisted for its Remote Calendars UI.
* The **scanner** never invokes Microsoft directly, refreshes OAuth, or writes FluentBooking data.

This scanner approach monitors persisted integration state rather than proactively testing Microsoft. If a later FluentBooking release changes the confirmed schema, the scan returns a clear unavailable/query message rather than searching arbitrary data or risking a fatal error. The `kitmage_fbim_calendar_snapshots` filter receives only sanitized snapshots and can adapt them without receiving OAuth state.

### Calendar email selection

For each `_outlook_user_token` row, FluentBooking stores the connected Outlook account email in `fcal_meta.key`; that exact value supplies `[calendar_email]`. A calendar without a matching token row is not treated as an Outlook integration. The scanner does not substitute a WordPress user, calendar-owner, or administrator email.

## Detection and credential safety

The sensitive state is decoded as JSON or safe, object-disabled PHP serialization when possible. Credential-named branches are skipped. Detection includes `Outlook Calendar API Error`, any `AADSTS` numeric code, `invalid_grant`, and broader Microsoft/Outlook/Entra authentication failures. A bounded raw-string fallback locates errors in otherwise opaque formats. The AADSTS code and a short redacted human-readable message are extracted; the raw value is then discarded and is never returned from discovery, stored in monitor history, logged, displayed, or emailed.

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

The body field uses WordPress's built-in TinyMCE/Visual editor with a Text tab for editing HTML. Saved markup is restricted through `wp_kses_post()`, dynamic values are HTML-escaped before insertion, and notifications are sent as `text/html`. Existing plain-text bodies remain usable and their line breaks are converted into paragraphs when sent.

## Outlook token keep-alive

Microsoft refresh tokens can expire after long periods of inactivity (for example, AADSTS700082). When **Token keep-alive** is checked under **Tools → Booking Monitor** (the default), the plugin obtains an Outlook API client once a week for each distinct WordPress user having a `_outlook_user_token` row in `{prefix}fcal_meta`. Unlike the scanner, this lookup does **not** join to `fcal_calendars`, so it includes connected users without any calendar.

The keep-alive calls `OutlookHelper::getApiClientByUserId($userId)` only. FluentBooking Pro's own Outlook classes refresh expired access tokens, rotate and persist OAuth credentials encrypted in FluentBooking's metadata, and record `last_error` on failures. This plugin never reads/decrypts token values, calls Microsoft directly, or writes FluentBooking tables itself. **Monitoring is read-only except for token rotation and error-state updates performed through FluentBooking's own classes when keep-alive is enabled.**

The last run is displayed on the Tools page and saved to `kitmage_fbim_keepalive_status`: timestamp, result state, attempted/valid-or-refreshed/failed/skipped counts, plus only the WordPress user ID, connected Outlook email (`fcal_meta.key`), and AADSTS code for failures. Neither raw error text nor token material is stored. Missing FluentBooking classes are skipped without a fatal. Errors recorded by FluentBooking are left for the existing 15-minute scanner and notification pipeline; keep-alive sends no email of its own. The scanner's calendar join means token rows belonging to users **without** calendars have no calendar incident to notify about, although keep-alive still includes them in its own status.

## Scheduling

Activation and init duplicate-safely schedule both cron events: the `kitmage_fbim_scan_event` scanner every 15 minutes and `kitmage_fbim_keepalive_event` weekly by default. Disabling keep-alive unschedules only its event; re-enabling schedules it again. Deactivation unschedules both and retains settings/history. WP-Cron is traffic-driven, so low-traffic sites should configure a real server scheduler to request `wp-cron.php` regularly for predictable operation. The plugin deliberately does not configure the server.

## Hooks

* `kitmage_fbim_scan_interval` — scanner recurrence in seconds (minimum 60; reschedule after changing it).
* `kitmage_fbim_keepalive_event` — WP-Cron hook for Outlook token keep-alive.
* `kitmage_fbim_keepalive_interval` — keep-alive recurrence in seconds (default 604800, minimum 3600; reschedule after changing it).
* `kitmage_fbim_calendar_snapshots` — sanitized/version-specific discovery adapter.
* `kitmage_fbim_is_detected_error` — alter error classification.
* `kitmage_fbim_template_variables` — add or alter template replacements.
* `kitmage_fbim_notification_recipients`, `kitmage_fbim_notification_subject`, `kitmage_fbim_notification_body` — alter rendered mail fields.
* `kitmage_fbim_send_notification` — allow or suppress sending.
* `kitmage_fbim_scan_completed` — observe summary results.

## Data removal

Deactivation preserves monitor data. WordPress's **Delete** action runs `uninstall.php`, removing only this plugin's settings, incidents, scan and keep-alive status, and both cron hooks. Uninstall never touches FluentBooking tables or Microsoft credentials.
