# WordPress mail queue setup for WP Newsletter Campaigns

## Delivery ownership

- WP Newsletter Campaigns owns subscribers, campaign recipient snapshots, personalisation, unsubscribe handling, campaign progress, throttling, and recipient-level handoff logs.
- WP Newsletter Campaigns sends one recipient per WordPress `wp_mail()` call.
- GD Mail Queue, or another mail plugin selected by the site administrator, owns the actual mail queue, provider connection, retries, transport logs, and final delivery diagnostics.
- WP Newsletter Campaigns does not configure PHPMailer, open SMTP connections, or override the From identity selected by the site mail plugin.

Do not configure SMTP inside WP Newsletter Campaigns. Configure and test delivery in the WordPress mail plugin used by the site.

## GD Mail Queue

1. Install and activate GD Mail Queue.
2. Configure its queue processing and delivery method in WordPress admin.
3. Send the mail plugin's own test email and confirm it appears in its queue/log.
4. Keep WordPress cron or the server-side queue runner active.
5. Send a WP proof or demo message and verify that GD Mail Queue receives it.

`wp_mail()` success means WordPress or the active queue plugin accepted the message. It does not by itself confirm final inbox delivery, so use the mail plugin's queue and logs for that status.

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
