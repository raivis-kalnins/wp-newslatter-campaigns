# WP Newsletter Campaigns

Native WordPress newsletter system, packaged as a single plugin to replace the current **Newsletter** plugin plus the active Newsletter add-ons and the separate campaign-upload workflow.

## Version

### 2.1.0

- Removes the remaining legacy brand references and switches the admin accent to classic WordPress blue.
- Adds a Gutenberg-style block email builder with Media Library images and responsive email-HTML generation.
- Adds optional newsletter-only custom SMTP, disabled by default, with a transport test and delivery-log integration.
- When custom SMTP is disabled, the plugin leaves WordPress `wp_mail()` and any active site mail/SMTP plugin in control.

### 2.0.0

- Moves the full newsletter system into the `wp-newslatter-campaigns` plugin with WP-prefixed hooks, options, tables, admin pages, assets, shortcodes, and internal identifiers.
- Adds a one-time compatibility import that copies existing data and key settings from the previous plugin namespace into the new WP-prefixed storage.
- Adds a native **Lists** screen at `/wp-admin/admin.php?page=newsletter_subscription_lists`, compatible with the Newsletter plugin's 40-list model.
- Imports existing `newsletter_subscription_lists` names, Public/Private state, and Enforced settings when available.
- Adds normalized subscriber/list membership storage, list counts, Add/Unlink Everyone, Confirm/Unconfirm All, public subscription-form list choices, and enforced-list assignment for new subscribers.
- Adds campaign audience targeting so a campaign can send to all active subscribers or one configured list.
- Keeps the existing hCaptcha, delivery queue, campaign upload, reporting, WooCommerce, automation, webhook, bounce, and import/export functionality under the new WP namespace.

### 1.7.24

- Prevents WP's fallback hCaptcha loader from racing an asynchronously rendered WS Form hCaptcha field.
- Shares WP's confirmed API-ready state with WS Form if the fallback loader is required.
- Waits briefly for the shared hCaptcha API on submit instead of immediately showing a captcha error.

### 1.7.23

- Renders the WP invisible hCaptcha widget in its real newsletter-form container so an interactive challenge can be displayed when required.
- Handles the object returned by asynchronous `hcaptcha.execute()` and submits only the verified response token.
- Continues to reuse WS Form's single hCaptcha API instance on shared pages such as Contact.

### 1.7.22

- Enforces one visible recipient for every WP Newsletter Campaigns `wp_mail()` handoff and removes injected To, CC, or BCC headers.
- Repairs stale GD Mail Queue recipient-log relations by matching WP's unique delivery ID to the correct subscriber.
- Cleans existing affected WP log entries during the plugin upgrade; actual queued messages remain unchanged.

### 1.7.21

- Defers the hCaptcha API to WS Form on pages containing a WS Form shortcode or block, preventing two competing `api.js` loads.
- Reuses WS Form's loaded hCaptcha singleton for WP Newsletter Campaigns forms on the same page.
- Waits for WS Form's hCaptcha `onload` signal before rendering a WP Newsletter Campaigns widget.
- Loads the WP hCaptcha API as a fallback when the detected WS Form does not contain an hCaptcha field.

### 1.7.20

- Disables WP Newsletter Campaigns's private SMTP, PHPMailer, and From-identity overrides.
- Sends welcome, proof, demo, live, retry, and background messages exclusively through WordPress `wp_mail()`.
- Supports GD Mail Queue or another administrator-selected WordPress mail plugin as the sole queue and delivery owner.
- Removes direct SMTP credentials and Mailpit transport controls from WP Newsletter Campaigns Settings while preserving WP recipient scheduling, personalisation, unsubscribe handling, and delivery handoff logs.

### 1.7.19

- Sends a one-time branded welcome email after a new visitor successfully subscribes through a WP newsletter form.
- Adds editable welcome-email subject, heading, message, and an enable/disable control in Newsletter Settings.
- Records welcome-email transport results in the existing delivery log and avoids repeat emails for addresses that are already active subscribers.
- Exposes template artwork and CTA filters so the WordPress child theme can supply its current logo, mascot, and prizes URL.

### 1.7.16

- Removes the separate WordPress global `wp_mail` path that was used only by live Mailpit sends.
- Live, unsent-retry, and background live delivery now call the exact same fresh per-recipient PHPMailer/SMTP routine as the working Demo Subscribers action.
- Keeps the same local live HTML preparation, visible `To` recipient, unique Message-ID, one-second pacing, and recipient-level logs.
- Fixes the missing local-capture mode variable in the Demo Subscribers completion notice.
- No Docker, Compose, Mailpit, or environment changes.

### 1.7.14

- Removes the Mailpit HTTP API from all newsletter delivery decisions.
- Proof, demo, live, and Send to unsent subscribers now use the same fresh per-recipient SMTP routine.
- Local Mailpit delivery uses SMTP host `mailpit` and SMTP port `1025`; `http://mailpit.localhost/` is only the browser inbox.
- Existing saved Mailpit API preferences are disabled automatically during the plugin upgrade.
- Keeps unique visible `To` headers, Message-IDs, queue logging, retries, and one-recipient transactions.

### 1.7.12

- Live sends through Mailpit/local capture now use the same proven per-recipient header context as Demo Subscriber sends.
- Local live tests keep the personalized unsubscribe link in the HTML body but omit external-list transport headers that are unnecessary in a capture inbox.
- Real SMTP/Post SMTP live sends continue to include List-Unsubscribe and List-ID headers.
- Small live lists remain synchronous and paced one recipient at a time; Docker and Mailpit configuration are not changed.

1.7.11

### 1.7.11 Mailpit endpoint routing fix

- Separates the browser Mailpit URL from the server-side API endpoint.
- Automatically maps `mailpit.localhost` to the Docker/network API URL `http://mailpit:8025`.
- Retries the internal Mailpit endpoint when a configured browser URL returns the WordPress 404 page or non-JSON content.
- Uses the same Mailpit API base for sending and stored-message verification, preventing cross-instance verification.
- Truncates HTML proxy errors in delivery logs and immediately returns failed attempts to `retry` instead of leaving misleading `processing` rows.


### 1.7.9 Mailpit verification compatibility

- Prevents a successful Mailpit Send API insert from being changed to `retry` when the optional single-message read-back route returns 404.
- Retries the detail route briefly and falls back to the Mailpit message-list endpoint.
- Treats the database ID returned by `/api/v1/send` as the capture confirmation when read-back is unavailable, while preserving a verification note in the delivery log.
- Still fails safely if Mailpit returns a stored message with a different recipient.

### 1.7.8 verified Mailpit API capture

- Uses Mailpit's HTTP send API automatically when a Mailpit/local capture host is configured.
- Requires Mailpit to return a real database message ID, then reads the stored message back and verifies the exact `To` recipient.
- Logs the Mailpit message ID, API endpoint, recipient, and Message-ID for every proof, demo, and live-test message.
- Treats an API or verification failure as a failed recipient so it can be retried instead of falsely reporting a local capture.
- Adds optional Mailpit API URL and Basic Authentication settings; `http://<smtp-host>:8025` is used automatically when left blank.

### 1.7.7 Mailpit/local SMTP test mode

- Allows proof, demo, and live-test campaigns to send through Mailpit, MailHog, smtp4dev, localhost, and port 1025.
- Labels successful local test transactions as **Captured locally** instead of treating them as external mailbox delivery.
- Keeps one recipient per message, unique Message-IDs, recipient-level logs, and full live/demo recipient processing in capture mode.
- Shows an informational local-test notice rather than blocking campaign actions.
- External delivery still requires a real provider SMTP host or Post SMTP, but local capture testing remains fully supported.

### 1.7.6 verified unique MIME identity and adaptive pacing

- Generates a unique Message-ID from the delivery UUID plus the destination-address fingerprint for every recipient.
- Validates the actual prepared MIME `Message-ID` header before opening the SMTP transaction; a missing or reused ID is treated as a send failure rather than falsely queued.
- Stores and displays the real Message-ID correctly instead of losing angle-bracket IDs during WordPress sanitisation.
- Adds a unique hidden delivery fingerprint to each HTML and plain-text body to prevent forwarding relays or final mailboxes from collapsing otherwise identical messages.
- Paces small live and demo lists at one SMTP transaction per second, while 100+ and 1,000+ lists retain the faster configured background-burst throttle.

### 1.7.4 direct recipient header enforcement

- Sends every live, demo, and proof message with the subscriber in the visible `To:` header; no BCC recipient bucket is used.
- Disables PHPMailer `SingleTo` compatibility mode for SMTP delivery.
- Clears To, CC, BCC, and Reply-To state before every individual message.
- Validates the prepared MIME headers before SMTP submission and refuses any message containing `Undisclosed recipients` or a BCC recipient.
- Logs `Header To` and `Recipient mode: direct-to-no-bcc` beside the SMTP queue ID.

### 1.7.3 controlled resend and unsent recovery

- Removes normal Send live again/Restart live send controls after a campaign has live-send history; repeated demo sends remain available.
- Adds a restricted Reset to Draft control only at the bottom of the campaign editor for `wp_raivis`, `wp_alan`, and `wp_soniya`.
- After a restricted reset, the campaign returns to Draft and can be sent live as a fresh campaign.
- Shows recipient-level unsent rows from the latest live run and adds Send to unsent subscribers, which retries only pending, retrying, processing, or failed active recipients.
- Previously successful recipients are never included in an unsent retry.

### 1.7.1 verified local SMTP and Draft reset

- Uses a fresh isolated PHPMailer instance for every recipient when the built-in local SMTP transport is selected.
- Marks a local SMTP message accepted only after capturing the actual 2xx reply following DATA; the later 221 connection-close reply is no longer treated as delivery evidence.
- Records the real SMTP acceptance reply and transaction ID in the sending log without recording authentication commands.
- Adds Reset to Draft on campaign list and edit screens. Reset cancels pending live jobs, clears the recipient snapshot and progress, and keeps the campaign and delivery logs.

### 1.7.0 durable live delivery queue

- Rebuilds every live send as a fresh, validated recipient snapshot instead of reusing stale campaign-recipient rows.
- Verifies that the number of queued rows exactly matches the number of active live subscribers before sending begins.
- Processes small lists completely in the current request and starts the first configured burst immediately for large lists.
- Uses Action Scheduler asynchronous bursts for 100, 1,000, and larger lists, with a one-minute WP-Cron watchdog fallback.
- Gives every recipient a unique Message-ID and WP delivery ID, preventing relay or mailbox de-duplication between recipients.
- Keeps atomic per-recipient claiming, retries, hourly limits, and recipient-level delivery logs.
- New recommended defaults: 20 messages per burst, 5 seconds between bursts, 100 ms between messages, and a 600-message hourly safety limit.

### 1.6.7 smart live queue and confirmed resend

- Processes the first configured live batch immediately when an administrator confirms Send live.
- Uses one controlled burst worker for following batches, with Action Scheduler/WP-Cron fallback and stalled-item recovery.
- Adds Resume now for a stuck active queue.
- Adds confirmed Send live again and Restart live send actions. A restart cancels remaining old queue items and intentionally creates a fresh run for all currently active live subscribers.
- Keeps one recipient per email, per-recipient logs, retry handling, and the hourly safety limit.

### 1.6.6 live-recipient delivery fix

Live campaigns now create one independent scheduled job for every active regular subscriber. This prevents a slow or terminated SMTP/background request from sending only the first address in a batch. Every new live send takes a fresh snapshot of all regular subscribers whose status is `subscribed`, whose sending flag is enabled, and who are not demo accounts. Each recipient is claimed atomically, logged separately, retried independently, and counted in the campaign status.

## What this build includes

This ZIP consolidates the requested main Newsletter functionality and add-on-style workflows into one faster, cleaner plugin admin area:

- **Core newsletter system**: subscriber table, campaign table, WordPress `wp_mail()`/GD Mail Queue-compatible delivery, Action Scheduler/WP-Cron background recipient scheduling, throttled one-recipient messages, automatic retry, shortcode subscription forms, open tracking, click tracking, personalisation tags, and one-click unsubscribe links.
- **Addons Manager replacement**: a native Addons screen showing every built-in replacement module and its status.
- **Automated Newsletters**: daily, weekly, or monthly latest-post newsletter generation using WP-Cron.
- **Bounce Addon**: bounce log table plus manual bounce paste/import screen that marks matching subscribers as bounced.
- **Contact Form 7 Integration**: optional capture of opted-in CF7 submissions when a field named `newsletter`, `wp_newslatter_campaigns`, or `subscribe` is present.
- **Google Analytics**: UTM source, medium, and campaign settings applied to tracked outbound newsletter links.
- **Import/Export**: CSV subscriber import/export and complete JSON campaign import/export.
- **Reports and Retargeting**: campaign open/click rates, campaign events table, and stored engagement events.
- **Webhooks**: subscribe, send, open, and click webhook events.
- **WooCommerce**: optional checkout newsletter opt-in.
- **WP Users Addon**: optional capture of newly registered WordPress users.
- **Campaign Upload**: Mail Designer 360 ZIP import, `content.html` cleanup, image path rewrite, dark-mode background cleanup, enforced `img:hover` reset, versioned file manager, desktop/mobile previews, source copy, and draft campaign creation.
- **Admin UI refresh**: modern card-based settings pages, better grouping, responsive layouts, dashboard badges, and a packaged admin stylesheet.

The public Newsletter plugin page describes the core plugin as a WordPress email marketing system for building lists, creating/sending newsletters, and tracking emails, with extension areas such as Automated, Reports and Retargeting, Google Analytics, import, WooCommerce, form-builder integrations, WP user integrations, delivery controls, and GDPR tools. This WP build implements the requested stack directly in one plugin rather than requiring the separate add-on plugins.

## Installation

1. In WordPress Admin, go to **Plugins → Add New → Upload Plugin**.
2. Upload `wp-newslatter-campaigns.zip`.
3. Activate **WP Newsletter Campaigns**.
4. Open **WP Newsletter Campaigns → Settings** and review sender, compliance, integrations, UTM, and performance settings.
5. Open **WP Newsletter Campaigns → Addons** to confirm the replacement modules are active.

## Recommended migration path

1. Keep a full database and uploads backup before replacing the old stack.
2. Activate this plugin in staging first.
3. Go to **WP Newsletter Campaigns → Dashboard**.
4. Use **Run / Continue Migration** if old Newsletter tables exist.
5. Verify subscriber counts, campaign imports, reports, webhooks, and test-send behaviour.
6. Only then deactivate the old Newsletter add-ons on production.

Migration is designed to be repeatable for common Newsletter tables. It upserts subscribers by email and campaigns by old source ID.

## Admin menu

- **Dashboard**: summary cards and migration status.
- **Subscribers**: manual add, per-subscriber activate/disable, bulk activate/disable, subscriber list and CSV export.
- **Demo Subscribers**: separate multi-account test list with activate/disable controls; demo accounts never receive live campaigns.
- **Lists**: 40 configurable Public/Private audience lists with Enforced membership, list-wide subscriber actions, counts, form choices, and campaign targeting.
- **Campaigns**: campaigns, queue send, duplicate, delete, single proof sends, and send-to-all-active-demo-subscribers.
- **Campaign Upload**: Mail Designer ZIP import, uploaded-file manager, preview, cleaned HTML source, draft creation.
- **Reports**: open/click stats and recent events.
- **Bounces**: paste bounced addresses/messages and mark subscribers as bounced.
- **Addons**: built-in module status list.
- **Automations**: create daily/weekly/monthly latest-post newsletter automations.
- **Webhooks**: create webhook endpoints for key events.
- **Import / Export**: subscriber CSV import/export and campaign JSON import/export.
- **Settings**: sender, delivery, GDPR, integrations, UTM, and admin UI settings.

## Shortcodes

```text
[wp_newslatter_campaigns]
[newsletter]
[newsletter_form]
```

Optional attributes:

```text
[wp_newslatter_campaigns title="Join our list" button="Subscribe" placeholder="Email address" name="yes"]
```

## Campaign upload storage

Mail Designer ZIP uploads are stored under:

```text
/wp-content/uploads/newsletter_emails/{campaign-folder}/{version}/
```

Example:

```text
/wp-content/uploads/newsletter_emails/competition-4-email-2/v1/
/wp-content/uploads/newsletter_emails/competition-4-email-2/v2/
```

The uploader imports only `content.html` and trusted image files, ignores unsafe paths and macOS metadata, rewrites image URLs, checks HTML with `DOMDocument`, beautifies source where possible, and keeps old versions until an admin deletes them.

## Contact Form 7 opt-in

Enable **Settings → Contact Form 7**. Add an opt-in field with one of these names:

```text
newsletter
wp_newslatter_campaigns
subscribe
```

The plugin captures the first valid email address in the submitted CF7 data only when the opt-in field has a value.

## WooCommerce opt-in

Enable **Settings → WooCommerce checkout opt-in**. The plugin adds a checkout checkbox and stores opted-in billing details as subscribers.

## WordPress user opt-in

Enable **Settings → WP user opt-in** to subscribe new WordPress users at registration.

## Subscribe on comment

Enable **Settings → Subscribe on comment** to add a checkbox below comment forms.

## Sending and performance

WP Newsletter Campaigns sends each recipient as a separate `wp_mail()` message so Post SMTP can transport and log it normally. Campaigns are processed in short background batches using WooCommerce Action Scheduler when available, with WP-Cron as a fallback. The default profile sends 10 messages every 60 seconds, limits sending to 300 accepted messages per rolling hour, and retries temporary failures up to three times without keeping one PHP request open.

For production, configure Post SMTP with a provider that permits opted-in bulk/newsletter traffic, authenticate the sender domain with SPF and DKIM, publish DMARC, keep the From domain aligned, and use a real server cron to call `wp-cron.php` on quiet or low-traffic sites.

## Tracking and unsubscribe

Each sent HTML email receives:

- tracked links with configured UTM parameters;
- an open-tracking pixel;
- an unsubscribe link using the subscriber token.

## Notes and limitations

This is a native WP replacement plugin, not a byte-for-byte clone of the third-party Newsletter plugin or its commercial add-ons. It implements the requested operational workflows in a consolidated codebase with a cleaner admin interface. Advanced third-party composer blocks, proprietary delivery-service APIs, and external add-on marketplace licensing are intentionally not bundled.

## File structure

```text
wp-newslatter-campaigns/
├── assets/
│   ├── admin.css
│   ├── frontend.css
│   └── frontend.js
├── readme.md
└── wp-newslatter-campaigns.php
```

## Changelog

### 1.6.8 complete live-recipient pass

- Admin-triggered live sends of up to 25 recipients are processed immediately one-by-one, independent of cron and batch-size settings.
- Every active live subscriber in the new run is atomically claimed and attempted exactly once.
- Resume now uses the same deterministic immediate pass for small queues.
- Any remaining queue is re-kicked after three seconds, while retry timestamps are still respected.
- Live-send notices report accepted, failed, and waiting counts for the exact run.

### 1.6.0
- Added first-class Post SMTP transport detection and admin delivery-health status.
- Prevented the legacy PHPMailer SMTP override from conflicting when Post SMTP is active.
- Replaced blocking in-request pauses with short background batches.
- Uses WooCommerce Action Scheduler when available and WP-Cron as a safe fallback.
- Added configurable batch interval, hourly safety limit, retry count, and retry delay.
- Added durable pending/processing/retry/error queue states and recovery of interrupted jobs.
- Added automatic retry for temporary `wp_mail`/Post SMTP failures.
- Added `List-Unsubscribe`, RFC 8058 one-click unsubscribe, `Precedence: bulk`, and auto-response suppression headers.
- Added queue, sent, and failed counts to the Campaigns screen.

### 1.5.3
- Shortened subscriber and uploaded-campaign destructive action labels to “Delete”.
- Added a consistent red danger-button style with white text for subscriber, campaign, upload-version, and webhook deletion actions.
- Changed the subscriber confirmation action to the same danger-button treatment.
- Limited primary buttons to their intrinsic content width with `max-width: fit-content`.

### 1.5.2
- Removed the fixed admin content width.
- Changed the main subscriber, campaign upload, editor and settings layouts from multi-column grids to full-width stacked panels.
- Preserved responsive spacing and full-width form controls.

### 1.5.1

- Replaced the always-open manual subscriber card with a compact, accessible accordion.
- Reworked the subscriber form into a responsive four-column admin layout when expanded.
- Fixed the Campaign Upload folder, ZIP email subject, and HTML campaign subject controls by adding explicit text input types and dedicated full-width styling.
- Added consistent hover and focus states for the repaired Campaign Upload controls.

### 1.5.0

- Added manual regular subscriber creation.
- Added non-destructive per-account and bulk activate/disable controls.
- Added a separate Demo Subscribers screen with multi-email creation and activate/disable controls.
- Added a Campaigns → Edit / test action to send a campaign to all active demo subscribers only.
- Added complete campaign JSON export and import.
- Excluded disabled and demo subscribers from all live campaign queues.
- Repaired Campaign Upload input sizing, padding, file controls and alignment.
- Added `img:hover {background: none !important;}` near the top of uploaded email source.
- Removed the Mail Designer Outlook hidden white-background block during upload cleanup.

### 1.4.0

- Changed the admin accent/dark green UI colour to `#2271b1`.
- Improved admin input fields with lighter backgrounds, larger touch-friendly sizing, better padding, rounded corners, and clearer focus states.
- Updated campaign upload, edit/test, SMTP, and delivery screens to use the new branded controls consistently.

### 1.3.0

- Improved Campaign Upload admin layout and alignment.
- Added campaign edit, preview, test-send, demo delivery status, SMTP settings, and one-by-one queue controls.

### 1.2.0

- Added packaged modern admin stylesheet.
- Added Addons Manager replacement screen.
- Added Bounce admin screen and processor.
- Added one-click unsubscribe handling.
- Added Contact Form 7 opt-in capture.
- Added subscribe-on-comment option.
- Added spam domain blacklist and privacy checkbox settings.
- Added latest-post automation generation from saved automation rules.
- Expanded settings into grouped card UI.
- Updated README with full functionality and migration notes.

### 1.1.0

- Initial consolidated WP Newsletter Campaigns build with subscribers, campaigns, reports, webhooks, WooCommerce/WP user capture, migration, and campaign upload workflow.


## 1.6.2 recipient delivery fixes

- Every **Send to demo** click creates a fresh run and schedules one independent background job for every enabled Demo Subscriber.
- Repeated demo tests no longer reuse or suppress recipients from an earlier test.
- Every new live send builds a fresh snapshot of all subscribers who are currently `subscribed`, enabled, and not demo accounts.
- Previously sent live recipients are intentionally reset for a deliberate new send, while disabled or ineligible recipients are excluded.
- A campaign cannot be started again while its previous live queue is still processing.


## 1.6.4 delivery log

Settings now contains a recipient-level Sending and delivery log. It records queued, processing, accepted, retry, failed, and skipped events for proof, demo, and live campaign sends. Each accepted send stores the transport reply when available, the PHPMailer Message-ID, and an `X-WP-Delivery-ID` header that can be searched in Mailpit or provider logs.

An accepted status means the configured mail transport accepted the message. It is not an inbox-delivery guarantee. Confirmed delivery requires a provider event webhook or provider-side delivery log.


## 1.6.5
- Removed Mail Designer 30px side gutters from proof and demo email frames so campaign content aligns flush with the test-email border.


## 1.7.3 SMTP relay hardening

- Direct SMTP can force the authenticated SMTP address as both the visible From and envelope sender. A different configured From address becomes Reply-To.
- Every recipient receives a unique Message-ID, X-WP-Delivery-ID, X-Entity-Ref-ID and recipient key.
- Delivery details now record RCPT TO, Header From, Envelope-From, Message-ID and the SMTP queue/transaction ID.
- Admin wording now uses “Relay queued” rather than implying confirmed mailbox delivery. A 2xx SMTP response confirms relay acceptance only; final mailbox delivery requires the relay/provider log, bounce processing or a delivery webhook.


## 1.7.6

- Added detection for Mailpit, MailHog, smtp4dev and common local capture endpoints.
- This release initially blocked campaign sends through capture-only SMTP. Version 1.7.7 supersedes that restriction and allows full local testing with accurate **Captured locally** status.


## 2.1 changes

- Removed remaining legacy-brand branding and frontend compatibility selectors; visible branding now uses WordPress terminology.
- Replaced the cherry-red admin accent with the classic WordPress admin blue palette (`#2271b1`, `#135e96`, and light blue/grey states).
- Added an optional Gutenberg-style block email builder to Campaign Upload with Heading, Text, Image/Media Library, Button, Divider, Spacer, and Custom HTML blocks plus live preview.
- Builder output is responsive, table-based email HTML and includes personalisation plus an unsubscribe token.
- Added optional newsletter-only custom SMTP. It is disabled by default; when disabled the plugin leaves `wp_mail()`/PHPMailer to the existing WordPress mail stack.
- Added a custom SMTP test action and kept recipient-level delivery logs in Settings.
