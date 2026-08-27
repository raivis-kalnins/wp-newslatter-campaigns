# WordPress mail queue setup for WP Newsletter Campaigns

## Delivery ownership

- WP Newsletter Campaigns owns subscribers, campaign recipient snapshots, personalisation, unsubscribe handling, campaign progress, throttling, and recipient-level handoff logs.
- WP Newsletter Campaigns sends one recipient per WordPress `wp_mail()` call.
- By default, GD Mail Queue or another mail plugin selected by the site administrator can own the provider connection, retries, transport logs, and final delivery diagnostics.
- WP Newsletter Campaigns also has an optional newsletter-only Custom SMTP mode. It is disabled by default and only changes PHPMailer while this plugin is actively sending one of its newsletter messages.
- If Custom SMTP is disabled, WP Newsletter Campaigns does not alter PHPMailer transport settings, so the normal WordPress mail stack remains in control.

Use one transport owner for newsletter delivery: either leave Custom SMTP disabled and configure the site mail plugin, or explicitly enable Custom SMTP in WP Newsletter Campaigns Settings. Avoid enabling competing SMTP transport plugins for the same newsletter send.

## GD Mail Queue / existing WordPress mail plugin

1. Leave WP Newsletter Campaigns Custom SMTP disabled.
2. Install and activate GD Mail Queue or the site mail plugin you want to use.
3. Configure its queue processing and delivery method in WordPress admin.
4. Send the mail plugin's own test email and confirm it appears in its queue/log.
5. Keep WordPress cron or the server-side queue runner active.
6. Send a WP proof or demo message and verify that the mail plugin receives it.

`wp_mail()` success means WordPress or the active queue plugin accepted the message. It does not by itself confirm final inbox delivery, so use the mail plugin's queue and logs for that status.


## Optional Custom SMTP

1. Open WP Newsletter Campaigns -> Settings.
2. Expand **Optional custom SMTP settings**.
3. Enter the SMTP host, port, encryption, username/password, and sender identity.
4. Enable **Custom SMTP for WP Newsletter Campaigns mail only** and save.
5. Use **Mail transport test** to send a test message.
6. Review the recipient-level Sending and delivery log directly below the settings.

Custom SMTP is opt-in and affects only WP Newsletter Campaigns messages while they are being sent. It does not take over unrelated WordPress emails.

## Sender authentication

- Use a From address on a domain authenticated by the delivery provider.
- Publish the provider's SPF record.
- Enable DKIM signing and publish its DNS records.
- Publish DMARC after SPF and DKIM pass and align with the visible From domain.
- Keep the From domain stable and send only to valid opted-in recipients.

WP Newsletter Campaigns adds a visible unsubscribe link plus `List-Unsubscribe` and RFC 8058 one-click unsubscribe headers to live campaign messages.

## Cron

WP recipient scheduling and the selected WordPress mail queue both need regular processing. On a low-traffic site, configure a real server cron to call WordPress cron regularly. A common example is:

```cron
* * * * * curl -fsS "https://example.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

Replace the domain and follow the hosting provider's cron instructions. Only set `DISABLE_WP_CRON` after the real cron is working.

## Live-send checklist

1. The WordPress mail plugin's test succeeds.
2. A WP proof/demo message appears in the WordPress mail queue.
3. Queue processing or server cron is active.
4. SPF and DKIM pass, and DMARC is published.
5. The From domain matches the authenticated domain.
6. Footer identity, links, and unsubscribe behavior are correct.
7. Start with conservative WP batch limits and monitor both WP handoff logs and the WordPress mail queue.
