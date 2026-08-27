<?php
/**
 * Plugin Name: WP Newsletter Campaigns
 * Description: Garilla/WP newsletter system replacing The Newsletter plugin stack, premium addons, and the Mail Designer campaign upload workflow.
 * Version: 2.0.0
 * Author: WP Workspace
 * Text Domain: wp-newslatter-campaigns
 * License: GPL-2.0-or-later
 */

defined('ABSPATH') || exit;

final class WP_Newslatter_Campaigns_Plugin {
    const VERSION = '2.0.0';
    const OPTION = 'wp_newslatter_campaigns_settings';
    const MIGRATION_OPTION = 'wp_newslatter_campaigns_migration_state';
    const UPLOAD_LAST_OPTION = 'wp_newslatter_campaigns_last_upload';
    const DB_VERSION_OPTION = 'wp_newslatter_campaigns_db_version';
    const UPLOAD_BASE_FOLDER = 'newsletter_emails';
    const SMTP_DIAGNOSTIC_OPTION = 'wp_newslatter_campaigns_smtp_diagnostic';
    const LISTS_OPTION = 'wp_newslatter_campaigns_lists';
    const LISTS_SYNC_OPTION = 'wp_newslatter_campaigns_lists_sync_version';
    const LEGACY_IMPORT_OPTION = 'wp_newslatter_campaigns_legacy_import_version';
    const LIST_MAX = 40;

    private static $instance = null;
    private $image_extensions = array('jpg','jpeg','png','gif','webp','svg','bmp','ico','avif','tif','tiff');
    private $last_mail_error = '';
    private $last_mail_message_id = '';
    private $last_mail_response = '';
    private $last_mail_delivery_id = '';
    private $current_mail_message_id = '';
    private $current_mail_recipient = '';
    private $gd_mail_queue_tables = null;
    private $local_smtp_original_host = '';
    private $local_smtp_resolved_host = '';
    private $local_smtp_resolved_addresses = array();

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action('init', array($this, 'init'));
        add_action('comment_form_after_fields', array($this, 'comment_optin_field'));
        add_action('comment_form_logged_in_after', array($this, 'comment_optin_field'));
        add_filter('preprocess_comment', array($this, 'capture_comment_optin'));
        add_action('wpcf7_submit', array($this, 'capture_cf7_optin'), 10, 2);
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));

        add_action('admin_post_wp_newslatter_campaigns_save_settings', array($this, 'save_settings'));
        add_action('admin_post_wp_newslatter_campaigns_import_csv', array($this, 'import_csv'));
        add_action('admin_post_wp_newslatter_campaigns_export_csv', array($this, 'export_csv'));
        add_action('admin_post_wp_newslatter_campaigns_add_subscriber', array($this, 'add_subscriber_admin'));
        add_action('admin_post_wp_newslatter_campaigns_add_demo_subscribers', array($this, 'add_demo_subscribers_admin'));
        add_action('admin_post_wp_newslatter_campaigns_toggle_subscriber', array($this, 'toggle_subscriber_admin'));
        add_action('admin_post_wp_newslatter_campaigns_bulk_subscriber_status', array($this, 'bulk_subscriber_status_admin'));
        add_action('admin_post_wp_newslatter_campaigns_import_campaigns', array($this, 'import_campaigns'));
        add_action('admin_post_wp_newslatter_campaigns_export_campaigns', array($this, 'export_campaigns'));
        add_action('admin_post_wp_newslatter_campaigns_delete_subscriber', array($this, 'delete_subscriber_admin'));
        add_action('admin_post_wp_newslatter_campaigns_upload_campaign', array($this, 'upload_html_campaign'));
        add_action('admin_post_wp_newslatter_campaigns_upload_zip', array($this, 'upload_zip_campaign'));
        add_action('admin_post_wp_newslatter_campaigns_delete_upload', array($this, 'delete_uploaded_campaign'));
        add_action('admin_post_wp_newslatter_campaigns_create_from_upload', array($this, 'create_campaign_from_upload'));
        add_action('admin_post_wp_newslatter_campaigns_migrate', array($this, 'run_migration_admin'));
        add_action('admin_post_wp_newslatter_campaigns_send_campaign', array($this, 'send_campaign_now'));
        add_action('admin_post_wp_newslatter_campaigns_resume_campaign', array($this, 'resume_campaign_now'));
        add_action('admin_post_wp_newslatter_campaigns_send_unsent_subscribers', array($this, 'send_unsent_subscribers'));
        add_action('admin_post_wp_newslatter_campaigns_reset_campaign_draft', array($this, 'reset_campaign_to_draft'));
        add_action('admin_post_wp_newslatter_campaigns_duplicate_campaign', array($this, 'duplicate_campaign'));
        add_action('admin_post_wp_newslatter_campaigns_delete_campaign', array($this, 'delete_campaign'));
        add_action('admin_post_wp_newslatter_campaigns_save_campaign', array($this, 'save_campaign'));
        add_action('admin_post_wp_newslatter_campaigns_test_campaign', array($this, 'test_campaign'));
        add_action('admin_post_wp_newslatter_campaigns_demo_campaign', array($this, 'demo_campaign'));
        add_action('admin_post_wp_newslatter_campaigns_send_demo_subscribers', array($this, 'send_demo_subscribers'));
        add_filter('wp_mail_content_type', array($this, 'mail_content_type'));
        add_action('admin_post_wp_newslatter_campaigns_save_webhook', array($this, 'save_webhook'));
        add_action('admin_post_wp_newslatter_campaigns_delete_webhook', array($this, 'delete_webhook'));
        add_action('admin_post_wp_newslatter_campaigns_save_automation', array($this, 'save_automation'));
        add_action('admin_post_wp_newslatter_campaigns_process_bounces', array($this, 'process_bounces'));
        add_action('admin_post_wp_newslatter_campaigns_clear_delivery_logs', array($this, 'clear_delivery_logs'));
        add_action('admin_post_wp_newslatter_campaigns_save_lists', array($this, 'save_lists'));

        add_action('admin_post_nopriv_wp_newslatter_campaigns_subscribe', array($this, 'handle_subscribe'));
        add_action('admin_post_wp_newslatter_campaigns_subscribe', array($this, 'handle_subscribe'));
        add_action('wp_ajax_nopriv_wp_newslatter_campaigns_ajax_subscribe', array($this, 'handle_ajax_subscribe'));
        add_action('wp_ajax_wp_newslatter_campaigns_ajax_subscribe', array($this, 'handle_ajax_subscribe'));
        add_action('wp_enqueue_scripts', array($this, 'frontend_assets'));
        add_shortcode('wp_newslatter_campaigns', array($this, 'shortcode_form'));
        add_shortcode('newsletter', array($this, 'shortcode_form'));
        add_shortcode('newsletter_form', array($this, 'shortcode_form'));

        add_action('woocommerce_review_order_before_submit', array($this, 'woocommerce_checkout_checkbox'));
        add_action('woocommerce_checkout_update_order_meta', array($this, 'woocommerce_checkout_optin'), 10, 2);
        add_action('user_register', array($this, 'wp_user_optin'), 20, 1);
        add_action('wp_newslatter_campaigns_cron_send', array($this, 'cron_send'));
        add_action('wp_newslatter_campaigns_process_campaign_batch', array($this, 'process_campaign_batch'), 10, 1);
        add_action('wp_newslatter_campaigns_send_live_recipient', array($this, 'process_live_recipient'), 10, 3);
        add_action('wp_newslatter_campaigns_process_live_burst', array($this, 'process_live_burst'), 10, 2);
        add_action('wp_newslatter_campaigns_send_demo_recipient', array($this, 'process_demo_recipient'), 10, 3);
        add_action('wp_mail_failed', array($this, 'capture_wp_mail_failure'));
    }

    public function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'wp_newslatter_campaigns_' . $name;
    }

    public function activate() {
        $this->create_tables();
        $this->migrate_previous_plugin_data();
        $this->backfill_subscriber_lists();
        update_option(self::DB_VERSION_OPTION, self::VERSION, false);
        if (!wp_next_scheduled('wp_newslatter_campaigns_cron_send')) {
            wp_schedule_event(time() + 60, 'wp_newslatter_campaigns_one_minute', 'wp_newslatter_campaigns_cron_send');
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook('wp_newslatter_campaigns_cron_send');
        wp_clear_scheduled_hook('wp_newslatter_campaigns_process_campaign_batch');
        wp_clear_scheduled_hook('wp_newslatter_campaigns_send_live_recipient');
        wp_clear_scheduled_hook('wp_newslatter_campaigns_process_live_burst');
        wp_clear_scheduled_hook('wp_newslatter_campaigns_send_demo_recipient');
    }

    public function cron_schedules($schedules) {
        $schedules['wp_newslatter_campaigns_one_minute'] = array('interval' => 60, 'display' => __('Every minute', 'wp-newslatter-campaigns'));
        $schedules['wp_newslatter_campaigns_five_minutes'] = array('interval' => 300, 'display' => __('Every five minutes', 'wp-newslatter-campaigns'));
        return $schedules;
    }

    public function init() {
        if (get_option(self::DB_VERSION_OPTION) !== self::VERSION) {
            $this->create_tables();
            $this->migrate_previous_plugin_data();
            $this->backfill_subscriber_lists();
            $saved = get_option(self::OPTION, array());
            if (is_array($saved)) {
                if (!isset($saved['send_batch_size']) || (int)$saved['send_batch_size'] === 10) $saved['send_batch_size'] = 20;
                if (!isset($saved['send_batch_interval']) || (int)$saved['send_batch_interval'] === 60) $saved['send_batch_interval'] = 5;
                if (!isset($saved['send_hourly_limit']) || (int)$saved['send_hourly_limit'] === 300) $saved['send_hourly_limit'] = 600;
                if (!isset($saved['send_batch_pause_ms']) || (int)$saved['send_batch_pause_ms'] === 0) $saved['send_batch_pause_ms'] = 100;
                // Delivery belongs to the site's WordPress mail stack (for
                // example GD Mail Queue), never to a private WP SMTP client.
                $saved['smtp_enabled'] = 0;
                $saved['capture_api_prefer'] = 0;
                update_option(self::OPTION, $saved, false);
            }
            wp_clear_scheduled_hook('wp_newslatter_campaigns_cron_send');
            wp_schedule_event(time() + 60, 'wp_newslatter_campaigns_one_minute', 'wp_newslatter_campaigns_cron_send');
            $this->repair_existing_gd_mail_queue_log_relations();
            update_option(self::DB_VERSION_OPTION, self::VERSION, false);
        }
        $this->maybe_unsubscribe();
        $this->maybe_track();
        $this->maybe_click();
    }

    public function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$this->table('subscribers')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            email VARCHAR(190) NOT NULL,
            first_name VARCHAR(120) NOT NULL DEFAULT '',
            last_name VARCHAR(120) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'subscribed',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            token VARCHAR(80) NOT NULL DEFAULT '',
            source VARCHAR(120) NOT NULL DEFAULT '',
            language VARCHAR(20) NOT NULL DEFAULT '',
            ip VARCHAR(100) NOT NULL DEFAULT '',
            country VARCHAR(10) NOT NULL DEFAULT '',
            lists LONGTEXT NULL,
            meta LONGTEXT NULL,
            wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created DATETIME NULL,
            updated DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            KEY source_id (source_id),
            KEY status (status),
            KEY enabled (enabled),
            KEY is_demo (is_demo)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->table('campaigns')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            title VARCHAR(255) NOT NULL DEFAULT '',
            subject VARCHAR(255) NOT NULL DEFAULT '',
            html LONGTEXT NULL,
            text LONGTEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            type VARCHAR(60) NOT NULL DEFAULT '',
            list_id VARCHAR(60) NOT NULL DEFAULT '',
            total INT NOT NULL DEFAULT 0,
            sent INT NOT NULL DEFAULT 0,
            open_count INT NOT NULL DEFAULT 0,
            click_count INT NOT NULL DEFAULT 0,
            options LONGTEXT NULL,
            scheduled_at DATETIME NULL,
            created DATETIME NULL,
            updated DATETIME NULL,
            PRIMARY KEY (id),
            KEY source_id (source_id),
            KEY status (status)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->table('sent')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            subscriber_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt DATETIME NULL,
            created DATETIME NULL,
            updated DATETIME NULL,
            sent_at DATETIME NULL,
            run_id VARCHAR(64) NOT NULL DEFAULT '',
            lock_token VARCHAR(64) NOT NULL DEFAULT '',
            error TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY campaign_subscriber (campaign_id,subscriber_id),
            KEY subscriber_id (subscriber_id),
            KEY campaign_id (campaign_id),
            KEY campaign_status (campaign_id,status),
            KEY next_attempt (next_attempt),
            KEY sent_at (sent_at),
            KEY run_id (run_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->table('events')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            subscriber_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            event_type VARCHAR(30) NOT NULL DEFAULT '',
            url TEXT NULL,
            ip VARCHAR(100) NOT NULL DEFAULT '',
            created DATETIME NULL,
            PRIMARY KEY (id),
            KEY campaign_id (campaign_id),
            KEY subscriber_id (subscriber_id),
            KEY event_type (event_type)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->table('automations')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(255) NOT NULL DEFAULT '',
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            frequency VARCHAR(20) NOT NULL DEFAULT 'weekly',
            subject VARCHAR(255) NOT NULL DEFAULT '',
            intro LONGTEXT NULL,
            post_type VARCHAR(60) NOT NULL DEFAULT 'post',
            post_count INT NOT NULL DEFAULT 5,
            last_run DATETIME NULL,
            created DATETIME NULL,
            updated DATETIME NULL,
            PRIMARY KEY (id),
            KEY source_id (source_id),
            KEY enabled (enabled)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->table('webhooks')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(255) NOT NULL DEFAULT '',
            event VARCHAR(60) NOT NULL DEFAULT 'subscribe',
            url TEXT NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created DATETIME NULL,
            PRIMARY KEY (id),
            KEY source_id (source_id),
            KEY event (event)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->table('bounces')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subscriber_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            email VARCHAR(190) NOT NULL DEFAULT '',
            message LONGTEXT NULL,
            created DATETIME NULL,
            PRIMARY KEY (id),
            KEY subscriber_id (subscriber_id),
            KEY email (email)
        ) $charset;");


        dbDelta("CREATE TABLE {$this->table('subscriber_lists')} (
            subscriber_id BIGINT UNSIGNED NOT NULL,
            list_id SMALLINT UNSIGNED NOT NULL,
            created DATETIME NULL,
            PRIMARY KEY (subscriber_id,list_id),
            KEY list_id (list_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->table('delivery_logs')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            subscriber_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            run_id VARCHAR(64) NOT NULL DEFAULT '',
            delivery_id VARCHAR(64) NOT NULL DEFAULT '',
            recipient VARCHAR(190) NOT NULL DEFAULT '',
            delivery_type VARCHAR(30) NOT NULL DEFAULT 'live',
            status VARCHAR(30) NOT NULL DEFAULT 'queued',
            attempt SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            transport VARCHAR(40) NOT NULL DEFAULT '',
            message_id VARCHAR(255) NOT NULL DEFAULT '',
            response TEXT NULL,
            created DATETIME NULL,
            PRIMARY KEY (id),
            KEY campaign_id (campaign_id),
            KEY subscriber_id (subscriber_id),
            KEY run_id (run_id),
            KEY delivery_id (delivery_id),
            KEY status (status),
            KEY created (created)
        ) $charset;");
    }

    public function settings() {
        $defaults = array(
            'from_name' => get_bloginfo('name'),
            'from_email' => get_option('admin_email'),
            'admin_email' => get_option('admin_email'),
            'double_optin' => 0,
            'privacy_checkbox' => 1,
            'welcome_email_enabled' => 1,
            'welcome_email_subject' => 'Thank you for subscribing to Garilla',
            'welcome_email_heading' => "You're officially in!",
            'welcome_email_message' => "Thank you for subscribing. You'll now be among the first to hear about our latest prizes, giveaways and winner news.",
            'subscribe_on_comment' => 0,
            'cf7_optin' => 1,
            'popup_enabled' => 0,
            'domain_blacklist' => '',
            'delete_inactive_days' => 0,
            'admin_theme' => 'wpnc',
            'webhook_urls' => '',
            'woocommerce_checkout_optin' => 1,
            'wp_user_optin' => 1,
            'footer_text' => 'You are receiving this email because you subscribed to Garilla updates.',
            'send_batch_size' => 20,
            'send_batch_interval' => 5,
            'send_hourly_limit' => 600,
            'send_max_retries' => 3,
            'send_retry_delay' => 900,
            'send_batch_pause_ms' => 100,
            'smtp_enabled' => 0,
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_secure' => 'tls',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_force_aligned_from' => 1,
            'capture_api_prefer' => 0,
            'capture_api_url' => '',
            'capture_api_username' => '',
            'capture_api_password' => '',
            'ga_utm_source' => 'newsletter',
            'ga_utm_medium' => 'email',
            'ga_utm_campaign' => 'wp-newslatter-campaigns',
        );
        $opt = get_option(self::OPTION, array());
        return wp_parse_args(is_array($opt) ? $opt : array(), $defaults);
    }

    private function is_post_smtp_active() {
        if (defined('POST_SMTP_VER') || class_exists('PostmanOptions') || class_exists('Postman')) return true;
        $plugin = 'post-smtp/postman-smtp.php';
        if (in_array($plugin, (array)get_option('active_plugins', array()), true)) return true;
        $network = (array)get_site_option('active_sitewide_plugins', array());
        return isset($network[$plugin]);
    }

    private function is_gd_mail_queue_active() {
        $plugins = array_merge(
            (array)get_option('active_plugins', array()),
            array_keys((array)get_site_option('active_sitewide_plugins', array()))
        );
        foreach ($plugins as $plugin) {
            if (stripos((string)$plugin, 'gd-mail-queue') !== false) return true;
        }
        return defined('GDMQ_VERSION') || class_exists('Dev4Press\Plugin\GdMailQueue\Core\Plugin');
    }

    private function is_local_smtp_configured() {
        // Retained for compatibility with old diagnostics only. WP Newsletter Campaigns
        // no longer opens SMTP connections or configures PHPMailer directly.
        return false;
    }

    private function smtp_capture_reason_from_host($host, $port) {
        $host = strtolower(trim((string)$host));
        $host = trim($host, '[]');
        $port = absint($port);
        foreach (array('mailpit','mailhog','smtp4dev','fake-smtp','fakesmtp','papercut') as $token) {
            if ($host !== '' && strpos($host, $token) !== false) {
                return 'The configured SMTP host appears to be ' . $token . ', which is a local email-capture service and does not deliver to external mailboxes.';
            }
        }
        $local_hosts = array('localhost','127.0.0.1','::1','0.0.0.0');
        if ($port === 1025 || (in_array($host, $local_hosts, true) && !in_array($port, array(25,465,587), true)) || substr($host, -10) === '.localhost') {
            return 'The configured SMTP host/port is a local test-capture endpoint. Messages can be viewed in the local inbox tool but are not delivered to external mailboxes.';
        }
        return '';
    }

    private function smtp_capture_reason_from_replies($replies) {
        $text = strtolower(implode("
", array_map('strval', (array)$replies)));
        foreach (array('mailpit'=>'Mailpit','mailhog'=>'MailHog','smtp4dev'=>'smtp4dev','papercut'=>'Papercut SMTP') as $needle=>$label) {
            if (strpos($text, $needle) !== false) {
                return $label . ' identified itself as the SMTP server. It captures mail locally and does not deliver messages to external inboxes.';
            }
        }
        return '';
    }

    private function save_smtp_diagnostic($capture, $reason, $reply = '') {
        $settings = $this->settings();
        update_option(self::SMTP_DIAGNOSTIC_OPTION, array(
            'capture' => $capture ? 1 : 0,
            'reason' => sanitize_text_field((string)$reason),
            'reply' => sanitize_text_field((string)$reply),
            'host' => sanitize_text_field((string)$settings['smtp_host']),
            'port' => absint($settings['smtp_port']),
            'checked' => current_time('mysql'),
        ), false);
    }

    private function local_smtp_capture_reason() {
        if (!$this->is_local_smtp_configured() || $this->is_post_smtp_active()) return '';
        $settings = $this->settings();
        $reason = $this->smtp_capture_reason_from_host($settings['smtp_host'], $settings['smtp_port']);
        if ($reason !== '') return $reason;
        $diagnostic = get_option(self::SMTP_DIAGNOSTIC_OPTION, array());
        if (is_array($diagnostic)
            && !empty($diagnostic['capture'])
            && strcasecmp((string)($diagnostic['host'] ?? ''), (string)$settings['smtp_host']) === 0
            && absint($diagnostic['port'] ?? 0) === absint($settings['smtp_port'])) {
            return sanitize_text_field((string)($diagnostic['reason'] ?? 'The SMTP server is a local capture service and cannot deliver externally.'));
        }
        return '';
    }


    /**
     * Resolve a local capture SMTP hostname once per PHP request and reuse that
     * exact endpoint for every recipient in the run. Docker DNS may return a
     * different container address on each new connection when several services
     * share the same alias. Without this pinning, one newsletter run can be split
     * across several Mailpit inboxes even though each server returns SMTP 250.
     *
     * External TLS/SSL SMTP is never replaced with an IP address because hostname
     * verification must continue to use the provider's configured hostname.
     */
    private function pinned_local_smtp_host() {
        $settings = $this->settings();
        $host = trim((string)($settings['smtp_host'] ?? ''));
        if ($host === '') return '';

        $secure = sanitize_key((string)($settings['smtp_secure'] ?? ''));
        $capture = $this->smtp_capture_reason_from_host($host, (int)($settings['smtp_port'] ?? 0)) !== '';
        if (!$capture || in_array($secure, array('tls','ssl'), true) || filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        if ($this->local_smtp_original_host === $host && $this->local_smtp_resolved_host !== '') {
            return $this->local_smtp_resolved_host;
        }

        $addresses = array();
        if (function_exists('gethostbynamel')) {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) $addresses = $resolved;
        }
        if (!$addresses && function_exists('gethostbyname')) {
            $resolved = @gethostbyname($host);
            if (is_string($resolved) && $resolved !== '' && strcasecmp($resolved, $host) !== 0) $addresses[] = $resolved;
        }

        $addresses = array_values(array_unique(array_filter(array_map('trim', $addresses), static function ($ip) {
            return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        })));
        usort($addresses, static function ($a, $b) {
            $ai = sprintf('%u', ip2long($a));
            $bi = sprintf('%u', ip2long($b));
            return $ai <=> $bi;
        });

        $this->local_smtp_original_host = $host;
        $this->local_smtp_resolved_addresses = $addresses;
        $this->local_smtp_resolved_host = $addresses ? (string)$addresses[0] : $host;
        return $this->local_smtp_resolved_host;
    }

    private function local_smtp_endpoint_log() {
        $original = trim((string)$this->local_smtp_original_host);
        $resolved = trim((string)$this->local_smtp_resolved_host);
        if ($resolved === '') return '';
        $detail = ' | SMTP endpoint: ' . ($original !== '' ? $original : $resolved);
        if ($original !== '' && strcasecmp($original, $resolved) !== 0) $detail .= ' -> ' . $resolved;
        if (count($this->local_smtp_resolved_addresses) > 1) {
            $detail .= ' | DNS candidates pinned for this run: ' . implode(', ', $this->local_smtp_resolved_addresses);
        }
        return $detail;
    }

    private function external_delivery_preflight() {
        // WordPress and its active mail/queue plugin own delivery readiness.
        // WP must not require or inspect separate SMTP credentials.
        return true;
    }

    private function mail_transport_status() {
        if ($this->is_gd_mail_queue_active()) {
            return array(
                'ready' => true,
                'key' => 'gd-mail-queue',
                'label' => 'GD Mail Queue active - delivery managed by WordPress',
            );
        }
        if ($this->is_post_smtp_active()) {
            return array(
                'ready' => true,
                'key' => 'post-smtp',
                'label' => 'Post SMTP active - delivery managed by WordPress',
            );
        }
        return array(
            'ready' => true,
            'key' => 'wordpress-mail',
            'label' => 'WordPress wp_mail - delivery managed by site plugins',
        );
    }

    private function action_scheduler_available() {
        if (!function_exists('as_schedule_single_action')) return false;
        if (class_exists('Action_Scheduler') && method_exists('Action_Scheduler', 'is_initialized')) {
            return Action_Scheduler::is_initialized();
        }
        return did_action('action_scheduler_init') > 0 || did_action('init') > 0;
    }

    private function queue_engine_label() {
        return $this->action_scheduler_available() ? 'Action Scheduler background queue' : 'WP-Cron fallback queue';
    }

    public function mail_from($email) {
        $settings = $this->settings();
        return is_email($settings['from_email']) ? sanitize_email($settings['from_email']) : $email;
    }

    public function mail_from_name($name) {
        $settings = $this->settings();
        return $settings['from_name'] !== '' ? sanitize_text_field($settings['from_name']) : $name;
    }

    public function admin_menu() {
        add_menu_page(__('WP Newsletter Campaigns', 'wp-newslatter-campaigns'), __('WP Newsletter Campaigns', 'wp-newslatter-campaigns'), 'manage_options', 'wp-newslatter-campaigns', array($this, 'page_dashboard'), 'dashicons-email-alt2', 58);
        add_submenu_page('wp-newslatter-campaigns', __('Subscribers', 'wp-newslatter-campaigns'), __('Subscribers', 'wp-newslatter-campaigns'), 'manage_options', 'wp-newslatter-campaigns-subscribers', array($this, 'page_subscribers'));
        add_submenu_page('wp-newslatter-campaigns', __('Demo Subscribers', 'wp-newslatter-campaigns'), __('Demo Subscribers', 'wp-newslatter-campaigns'), 'manage_options', 'wp-newslatter-campaigns-demo-subscribers', array($this, 'page_demo_subscribers'));
        add_submenu_page('wp-newslatter-campaigns', __('Lists', 'wp-newslatter-campaigns'), __('Lists', 'wp-newslatter-campaigns'), 'manage_options', 'newsletter_subscription_lists', array($this, 'page_lists'));
        add_submenu_page('wp-newslatter-campaigns', __('Campaigns', 'wp-newslatter-campaigns'), __('Campaigns', 'wp-newslatter-campaigns'), 'manage_options', 'wp-newslatter-campaigns-campaigns', array($this, 'page_campaigns'));
        add_submenu_page('wp-newslatter-campaigns', __('Campaign Upload', 'wp-newslatter-campaigns'), __('Campaign Upload', 'wp-newslatter-campaigns'), 'manage_options', 'wp-newslatter-campaigns-upload', array($this, 'page_upload'));
        add_submenu_page('wp-newslatter-campaigns', __('Reports', 'wp-newslatter-campaigns'), __('Reports', 'wp-newslatter-campaigns'), 'manage_options', 'wp-newslatter-campaigns-reports', array($this, 'page_reports'));
        add_submenu_page('wp-newslatter-campaigns', __('Bounces', 'wp-newslatter-campaigns'), __('Bounces', 'wp-newslatter-campaigns'), 'manage_options', 'wp-newslatter-campaigns-bounces', array($this, 'page_bounces'));
        add_submenu_page('wp-newslatter-campaigns', __('Addons', 'wp-newslatter-campaigns'), __('Addons', 'wp-newslatter-campaigns'), 'manage_options', 'wp-newslatter-campaigns-addons', array($this, 'page_addons'));
        add_submenu_page('wp-newslatter-campaigns', __('Automations', 'wp-newslatter-campaigns'), __('Automations', 'wp-newslatter-campaigns'), 'manage_options', 'wp-newslatter-campaigns-automations', array($this, 'page_automations'));
        add_submenu_page('wp-newslatter-campaigns', __('Webhooks', 'wp-newslatter-campaigns'), __('Webhooks', 'wp-newslatter-campaigns'), 'manage_options', 'wp-newslatter-campaigns-webhooks', array($this, 'page_webhooks'));
        add_submenu_page('wp-newslatter-campaigns', __('Import / Export', 'wp-newslatter-campaigns'), __('Import / Export', 'wp-newslatter-campaigns'), 'manage_options', 'wp-newslatter-campaigns-import', array($this, 'page_import'));
        add_submenu_page('wp-newslatter-campaigns', __('Settings', 'wp-newslatter-campaigns'), __('Settings', 'wp-newslatter-campaigns'), 'manage_options', 'wp-newslatter-campaigns-settings', array($this, 'page_settings'));
    }

    public function admin_assets($hook) {
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if (strpos((string)$hook, 'wp-newslatter-campaigns') === false && $page !== 'newsletter_subscription_lists') {
            return;
        }
        wp_enqueue_style('wp-newslatter-campaigns-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), self::VERSION);
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery-core', '(function(){document.addEventListener("click",function(e){if(e.target.matches("[data-wpnc-copy-source]")){var t=document.querySelector("#wpnc-upload-source");if(!t)return;t.select();t.setSelectionRange(0,t.value.length);try{document.execCommand("copy");e.target.textContent="Copied";}catch(err){e.target.textContent="Copy manually";}setTimeout(function(){e.target.textContent="Copy source";},1500);}});document.addEventListener("submit",function(e){var f=e.target.closest("form[data-wpnc-confirm]");if(!f)return;if(!confirm(f.getAttribute("data-wpnc-confirm")))e.preventDefault();});document.addEventListener("click",function(e){var link=e.target.closest(".wpnc-subscriber-delete-link");if(!link)return;e.preventDefault();var modal=document.getElementById("wpnc-subscriber-delete-modal");var url=link.getAttribute("data-delete-url");if(!modal){if(url)window.location.href=url;return;}var emailNode=modal.querySelector("[data-wpnc-subscriber-email]");var deleteButton=modal.querySelector(".wpnc-confirm-delete");if(emailNode)emailNode.textContent=link.getAttribute("data-email")||"this subscriber";if(deleteButton)deleteButton.setAttribute("href",url||"#");modal.hidden=false;modal.setAttribute("aria-hidden","false");var cancelButton=modal.querySelector("[data-wpnc-modal-cancel]");if(cancelButton)cancelButton.focus();});document.addEventListener("click",function(e){if(!e.target.closest("[data-wpnc-modal-cancel]"))return;var modal=e.target.closest(".wpnc-confirm-modal")||document.getElementById("wpnc-subscriber-delete-modal");if(!modal)return;modal.hidden=true;modal.setAttribute("aria-hidden","true");});document.addEventListener("keydown",function(e){if(e.key!=="Escape")return;var modal=document.getElementById("wpnc-subscriber-delete-modal");if(!modal||modal.hidden)return;modal.hidden=true;modal.setAttribute("aria-hidden","true");});})();');
    }

    private function admin_flash_key() {
        return 'wp_newslatter_campaigns_admin_flash_' . absint(get_current_user_id());
    }

    private function admin_wrap_start($title) {
        echo '<div class="wrap wp-newslatter-campaigns-admin"><h1>' . esc_html($title) . '</h1>';

        // Keep admin feedback reliable after admin-post redirects. Query strings
        // can be stripped by security/cache plugins, so every action also stores
        // one short per-user flash message as a fallback.
        $flash = get_transient($this->admin_flash_key());
        delete_transient($this->admin_flash_key());
        $flash = is_array($flash) ? $flash : array();
        $notice = isset($_GET['wpnc_notice'])
            ? sanitize_text_field(wp_unslash($_GET['wpnc_notice']))
            : sanitize_text_field((string)($flash['notice'] ?? ''));
        $error = isset($_GET['wpnc_error'])
            ? sanitize_text_field(wp_unslash($_GET['wpnc_error']))
            : sanitize_text_field((string)($flash['error'] ?? ''));

        if ($notice !== '') {
            echo '<div class="notice notice-success is-dismissible wpnc-action-notice"><p><strong>Newsletter:</strong> ' . esc_html($notice) . '</p></div>';
        }
        if ($error !== '') {
            echo '<div class="notice notice-error is-dismissible wpnc-action-notice"><p><strong>Newsletter error:</strong> ' . esc_html($error) . '</p></div>';
        }
    }

    private function admin_wrap_end() { echo '</div>'; }

    private function redirect_admin($page, $notice = '', $error = '') {
        $parts = explode('&', (string)$page);
        $args = array('page' => array_shift($parts));
        foreach ($parts as $part) {
            if (strpos($part, '=') === false) continue;
            list($k, $v) = array_map('sanitize_text_field', explode('=', $part, 2));
            if ($k !== '') $args[$k] = $v;
        }

        $notice = sanitize_text_field((string)$notice);
        $error = sanitize_text_field((string)$error);
        set_transient($this->admin_flash_key(), array('notice'=>$notice, 'error'=>$error), 5 * MINUTE_IN_SECONDS);
        if ($notice !== '') $args['wpnc_notice'] = $notice;
        if ($error !== '') $args['wpnc_error'] = $error;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function page_dashboard() {
        global $wpdb;
        $this->create_tables();
        $subs = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table('subscribers')}");
        $campaigns = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table('campaigns')}");
        $sent = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table('sent')}");
        $events = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table('events')}");
        $old_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'newsletter'));
        $state = get_option(self::MIGRATION_OPTION, array());
        $this->admin_wrap_start(__('WP Newsletter Campaigns', 'wp-newslatter-campaigns'));
        echo '<p>' . esc_html__('One native WP plugin replacing Newsletter, Addons Manager, Automated, Bounce, Google Analytics, Import/Export, Reports, Webhooks, WooCommerce, WP Users, and Campaign Upload workflows.', 'wp-newslatter-campaigns') . '</p>';
        echo $this->addon_badges();
        echo '<div class="wpnc-cards"><div><strong>' . esc_html($subs) . '</strong><span>Subscribers</span></div><div><strong>' . esc_html($campaigns) . '</strong><span>Campaigns</span></div><div><strong>' . esc_html($sent) . '</strong><span>Sent records</span></div><div><strong>' . esc_html($events) . '</strong><span>Events</span></div></div>';
        echo '<h2>Migration from The Newsletter plugin</h2><p>Migration is safe to run again. It upserts subscribers by email and campaigns by old source ID so you can continue from existing demo data.</p>';
        if ($old_exists) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('wp_newslatter_campaigns_migrate');
            echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_migrate"><button class="button button-primary">Run / Continue Migration</button></form>';
        } else {
            echo '<p><em>Old Newsletter tables were not found on this database.</em></p>';
        }
        if ($state) echo '<pre class="wpnc-pre">' . esc_html(print_r($state, true)) . '</pre>';
        $this->admin_wrap_end();
    }

    private function previous_plugin_namespace() {
        return implode('', array('t', 'f', 'a')) . '_newsletter_';
    }

    private function migrate_previous_plugin_data() {
        if (get_option(self::LEGACY_IMPORT_OPTION) === self::VERSION) return;
        global $wpdb;
        $namespace = $this->previous_plugin_namespace();
        $found = false;
        $table_names = array('subscribers','campaigns','sent','events','automations','webhooks','bounces','delivery_logs');
        foreach ($table_names as $name) {
            $old_table = $wpdb->prefix . $namespace . $name;
            $new_table = $this->table($name);
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($old_table)));
            if ($exists !== $old_table) continue;
            $found = true;
            $old_columns = $this->db_columns($old_table);
            $new_columns = $this->db_columns($new_table);
            $columns = array_values(array_intersect($old_columns, $new_columns));
            if (!$columns) continue;
            $quoted = array_map(function($column) { return '`' . str_replace('`', '', $column) . '`'; }, $columns);
            $column_sql = implode(',', $quoted);
            $wpdb->query("INSERT IGNORE INTO {$new_table} ({$column_sql}) SELECT {$column_sql} FROM {$old_table}");
        }

        $option_map = array(
            $namespace . 'settings' => self::OPTION,
            $namespace . 'migration_state' => self::MIGRATION_OPTION,
            $namespace . 'last_upload' => self::UPLOAD_LAST_OPTION,
            $namespace . 'smtp_diagnostic' => self::SMTP_DIAGNOSTIC_OPTION,
            $namespace . 'test_logs' => 'wp_newslatter_campaigns_test_logs',
        );
        $missing = '__wpnc_previous_option_missing__';
        foreach ($option_map as $old_key => $new_key) {
            $value = get_option($old_key, $missing);
            if ($value === $missing) continue;
            $found = true;
            if (get_option($new_key, $missing) === $missing) update_option($new_key, $value, false);
        }
        if ($found) update_option(self::LEGACY_IMPORT_OPTION, self::VERSION, false);
    }

    private function normalize_list_ids($value) {
        if (is_string($value)) {
            $unserialized = maybe_unserialize($value);
            if ($unserialized !== $value || is_array($unserialized)) {
                $value = $unserialized;
            } elseif ($value !== '') {
                $value = preg_split('/[\s,;|]+/', $value);
            }
        }
        if (!is_array($value)) $value = array();
        $ids = array();
        foreach ($value as $id) {
            $id = absint($id);
            if ($id >= 1 && $id <= self::LIST_MAX) $ids[$id] = $id;
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    private function lists_settings() {
        $saved = get_option(self::LISTS_OPTION, null);
        if (!is_array($saved)) {
            $saved = array();
            $legacy = get_option('newsletter_subscription_lists', array());
            if (is_array($legacy)) {
                for ($i = 1; $i <= self::LIST_MAX; $i++) {
                    $name = sanitize_text_field((string)($legacy['list_' . $i] ?? ''));
                    if ($name === '') continue;
                    $saved[$i] = array(
                        'name' => $name,
                        'public' => !empty($legacy['list_' . $i . '_status']) ? 1 : 0,
                        'forced' => !empty($legacy['list_' . $i . '_forced']) ? 1 : 0,
                    );
                }
                if ($saved) update_option(self::LISTS_OPTION, $saved, false);
            }
        }
        $out = array();
        for ($i = 1; $i <= self::LIST_MAX; $i++) {
            $row = isset($saved[$i]) && is_array($saved[$i]) ? $saved[$i] : array();
            $out[$i] = array(
                'name' => sanitize_text_field((string)($row['name'] ?? '')),
                'public' => !empty($row['public']) ? 1 : 0,
                'forced' => !empty($row['forced']) ? 1 : 0,
            );
        }
        return $out;
    }

    private function configured_lists($public_only = false) {
        $out = array();
        foreach ($this->lists_settings() as $id => $row) {
            if ($row['name'] === '') continue;
            if ($public_only && empty($row['public'])) continue;
            $out[(int)$id] = $row;
        }
        return $out;
    }

    private function forced_list_ids() {
        $ids = array();
        foreach ($this->configured_lists(false) as $id => $row) if (!empty($row['forced'])) $ids[] = (int)$id;
        return $ids;
    }

    private function subscriber_list_ids($subscriber_id) {
        global $wpdb;
        $subscriber_id = absint($subscriber_id);
        if (!$subscriber_id) return array();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT list_id FROM {$this->table('subscriber_lists')} WHERE subscriber_id=%d ORDER BY list_id ASC", $subscriber_id));
        return $this->normalize_list_ids($ids);
    }

    private function sync_subscriber_lists($subscriber_id, $list_ids) {
        global $wpdb;
        $subscriber_id = absint($subscriber_id);
        if (!$subscriber_id) return;
        $list_ids = $this->normalize_list_ids($list_ids);
        $table = $this->table('subscriber_lists');
        $wpdb->delete($table, array('subscriber_id' => $subscriber_id), array('%d'));
        if (!$list_ids) return;
        $created = current_time('mysql');
        foreach ($list_ids as $list_id) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (subscriber_id,list_id,created) VALUES (%d,%d,%s)",
                $subscriber_id,
                $list_id,
                $created
            ));
        }
    }

    private function rebuild_serialized_list_cache($regular_only = false) {
        global $wpdb;
        $where = $regular_only ? ' WHERE is_demo=0' : '';
        $ids = $wpdb->get_col("SELECT id FROM {$this->table('subscribers')}{$where} ORDER BY id ASC");
        foreach ((array)$ids as $subscriber_id) {
            $list_ids = $this->subscriber_list_ids($subscriber_id);
            $wpdb->update($this->table('subscribers'), array('lists' => maybe_serialize($list_ids)), array('id' => absint($subscriber_id)));
        }
    }

    private function backfill_subscriber_lists() {
        if (get_option(self::LISTS_SYNC_OPTION) === self::VERSION) return;
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id,lists FROM {$this->table('subscribers')} ORDER BY id ASC");
        foreach ((array)$rows as $row) {
            $this->sync_subscriber_lists($row->id, $this->normalize_list_ids($row->lists));
        }
        update_option(self::LISTS_SYNC_OPTION, self::VERSION, false);
    }

    private function list_member_counts() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT sl.list_id,COUNT(*) qty FROM {$this->table('subscriber_lists')} sl INNER JOIN {$this->table('subscribers')} s ON s.id=sl.subscriber_id WHERE s.is_demo=0 GROUP BY sl.list_id"
        );
        $counts = array_fill(1, self::LIST_MAX, 0);
        foreach ((array)$rows as $row) {
            $id = absint($row->list_id);
            if ($id >= 1 && $id <= self::LIST_MAX) $counts[$id] = (int)$row->qty;
        }
        return $counts;
    }

    private function list_label($list_id) {
        $list_id = absint($list_id);
        if (!$list_id) return __('All active subscribers', 'wp-newslatter-campaigns');
        $lists = $this->lists_settings();
        return !empty($lists[$list_id]['name']) ? $lists[$list_id]['name'] : sprintf(__('List %d', 'wp-newslatter-campaigns'), $list_id);
    }

    private function campaign_recipient_count($campaign) {
        global $wpdb;
        if (!is_object($campaign)) return 0;
        $list_id = absint($campaign->list_id ?? 0);
        $join = $list_id ? " INNER JOIN {$this->table('subscriber_lists')} sl ON sl.subscriber_id=s.id AND sl.list_id=" . $list_id : '';
        return (int)$wpdb->get_var("SELECT COUNT(DISTINCT s.id) FROM {$this->table('subscribers')} s{$join} WHERE s.status='subscribed' AND s.enabled=1 AND s.is_demo=0");
    }

    public function save_lists() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('wp_newslatter_campaigns_save_lists');
        $saved = array();
        for ($i = 1; $i <= self::LIST_MAX; $i++) {
            $name = sanitize_text_field(wp_unslash($_POST['list_' . $i] ?? ''));
            $saved[$i] = array(
                'name' => $name,
                'public' => !empty($_POST['list_' . $i . '_status']) ? 1 : 0,
                'forced' => !empty($_POST['list_' . $i . '_forced']) ? 1 : 0,
            );
        }
        update_option(self::LISTS_OPTION, $saved, false);

        $bulk = sanitize_text_field(wp_unslash($_POST['list_bulk_action'] ?? ''));
        $message = __('Lists saved.', 'wp-newslatter-campaigns');
        if ($bulk !== '' && preg_match('/^(add|unlink|confirm|unconfirm):(\d+)$/', $bulk, $m)) {
            $operation = $m[1];
            $list_id = absint($m[2]);
            if ($list_id >= 1 && $list_id <= self::LIST_MAX) {
                global $wpdb;
                $members = $this->table('subscriber_lists');
                $subscribers = $this->table('subscribers');
                $now = current_time('mysql');
                if ($operation === 'add') {
                    $wpdb->query($wpdb->prepare(
                        "INSERT IGNORE INTO {$members} (subscriber_id,list_id,created) SELECT id,%d,%s FROM {$subscribers} WHERE is_demo=0",
                        $list_id,
                        $now
                    ));
                    $this->rebuild_serialized_list_cache(true);
                    $message = sprintf(__('Added all regular subscribers to List %d.', 'wp-newslatter-campaigns'), $list_id);
                } elseif ($operation === 'unlink') {
                    $wpdb->query($wpdb->prepare(
                        "DELETE sl FROM {$members} sl INNER JOIN {$subscribers} s ON s.id=sl.subscriber_id WHERE sl.list_id=%d AND s.is_demo=0",
                        $list_id
                    ));
                    $this->rebuild_serialized_list_cache(true);
                    $message = sprintf(__('Removed all regular subscribers from List %d.', 'wp-newslatter-campaigns'), $list_id);
                } elseif ($operation === 'confirm') {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$subscribers} s INNER JOIN {$members} sl ON sl.subscriber_id=s.id SET s.status='subscribed',s.updated=%s WHERE sl.list_id=%d AND s.is_demo=0",
                        $now,
                        $list_id
                    ));
                    $message = sprintf(__('Confirmed all subscribers in List %d.', 'wp-newslatter-campaigns'), $list_id);
                } elseif ($operation === 'unconfirm') {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$subscribers} s INNER JOIN {$members} sl ON sl.subscriber_id=s.id SET s.status='unconfirmed',s.updated=%s WHERE sl.list_id=%d AND s.is_demo=0",
                        $now,
                        $list_id
                    ));
                    $message = sprintf(__('Unconfirmed all subscribers in List %d.', 'wp-newslatter-campaigns'), $list_id);
                }
            }
        }
        $this->redirect_admin('newsletter_subscription_lists', $message);
    }

    public function page_lists() {
        $lists = $this->lists_settings();
        $counts = $this->list_member_counts();
        $this->admin_wrap_start(__('Lists', 'wp-newslatter-campaigns'));
        echo '<div class="wpnc-card"><h2>Newsletter lists</h2><p>Configure up to ' . absint(self::LIST_MAX) . ' audience lists. Public lists can appear on the subscription form, and enforced lists are automatically assigned to new regular subscribers.</p><p><strong>Campaign targeting:</strong> choose one of these lists when editing a campaign, or leave the audience as all active subscribers.</p></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wp_newslatter_campaigns_save_lists');
        echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_save_lists">';
        for ($panel = 0; $panel < 4; $panel++) {
            $start = ($panel * 10) + 1;
            $end = min(self::LIST_MAX, $start + 9);
            echo '<div class="wpnc-card"><h2>Lists ' . absint($start) . '&ndash;' . absint($end) . '</h2><div class="wpnc-subscriber-table-wrap"><table class="widefat striped"><thead><tr><th>#</th><th>Name</th><th>Type</th><th>Enforced</th><th>Subscribers</th><th>Actions</th></tr></thead><tbody>';
            for ($i = $start; $i <= $end; $i++) {
                $row = $lists[$i];
                echo '<tr><td>' . absint($i) . '</td><td><input type="text" class="regular-text" name="list_' . absint($i) . '" value="' . esc_attr($row['name']) . '" placeholder="List ' . absint($i) . '"></td><td><select name="list_' . absint($i) . '_status"><option value="0" ' . selected($row['public'], 0, false) . '>Private</option><option value="1" ' . selected($row['public'], 1, false) . '>Public</option></select></td><td><label><input type="checkbox" name="list_' . absint($i) . '_forced" value="1" ' . checked($row['forced'], 1, false) . '> Add new subscribers</label></td><td><strong>' . absint($counts[$i] ?? 0) . '</strong></td><td class="wpnc-table-actions"><button class="button" name="list_bulk_action" value="unlink:' . absint($i) . '" onclick="return confirm(\'Remove every regular subscriber from this list?\')">Unlink everyone</button><button class="button" name="list_bulk_action" value="add:' . absint($i) . '" onclick="return confirm(\'Add every regular subscriber to this list?\')">Add everyone</button><button class="button" name="list_bulk_action" value="unconfirm:' . absint($i) . '" onclick="return confirm(\'Mark every subscriber in this list as unconfirmed?\')">Unconfirm all</button><button class="button" name="list_bulk_action" value="confirm:' . absint($i) . '" onclick="return confirm(\'Mark every subscriber in this list as subscribed?\')">Confirm all</button></td></tr>';
            }
            echo '</tbody></table></div></div>';
        }
        echo '<p><button class="button button-primary button-hero" type="submit">Save lists</button></p></form>';
        $this->admin_wrap_end();
    }

    private function subscriber_sort_state() {
        $orderby = sanitize_key(wp_unslash($_GET['subscriber_orderby'] ?? 'created'));
        if (!in_array($orderby, array('sending', 'created'), true)) $orderby = 'created';

        $order = strtolower(sanitize_key(wp_unslash($_GET['subscriber_order'] ?? 'desc')));
        if (!in_array($order, array('asc', 'desc'), true)) $order = 'desc';

        return array('orderby' => $orderby, 'order' => $order);
    }

    private function subscriber_list_state() {
        $state = $this->subscriber_sort_state();
        $state['search'] = sanitize_text_field(wp_unslash($_GET['subscriber_search'] ?? ''));
        $state['filter'] = sanitize_key(wp_unslash($_GET['subscriber_filter'] ?? 'all'));
        if (!in_array($state['filter'], array('all','active','disabled','subscribed','unconfirmed','unsubscribed','bounced'), true)) {
            $state['filter'] = 'all';
        }
        $state['per_page'] = absint($_GET['subscriber_per_page'] ?? 70);
        if ($state['per_page'] < 1) $state['per_page'] = 70;
        $state['per_page'] = min(500, $state['per_page']);
        $state['paged'] = max(1, absint($_GET['subscriber_paged'] ?? 1));
        return $state;
    }

    private function subscriber_order_clause($sort) {
        $order = (($sort['order'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
        if (($sort['orderby'] ?? 'created') === 'sending') {
            return 'enabled ' . $order . ', created DESC, id DESC';
        }
        return 'created ' . $order . ', id ' . $order;
    }

    private function subscriber_query_args($state, $page, $overrides = array()) {
        $args = array(
            'page' => $page,
            'subscriber_orderby' => $state['orderby'] ?? 'created',
            'subscriber_order' => $state['order'] ?? 'desc',
            'subscriber_per_page' => max(1, absint($state['per_page'] ?? 70)),
            'subscriber_paged' => max(1, absint($state['paged'] ?? 1)),
        );
        if (!empty($state['search'])) $args['subscriber_search'] = $state['search'];
        if (!empty($state['filter']) && $state['filter'] !== 'all') $args['subscriber_filter'] = $state['filter'];
        return array_merge($args, $overrides);
    }

    private function subscriber_list_data($demo, &$state) {
        global $wpdb;
        $table = $this->table('subscribers');
        $where = array('is_demo=%d');
        $params = array($demo ? 1 : 0);

        if ($state['search'] !== '') {
            $like = '%' . $wpdb->esc_like($state['search']) . '%';
            $where[] = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR source LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }

        if ($state['filter'] === 'active') {
            $where[] = 'enabled=1';
        } elseif ($state['filter'] === 'disabled') {
            $where[] = 'enabled=0';
        } elseif (in_array($state['filter'], array('subscribed','unconfirmed','unsubscribed','bounced'), true)) {
            $where[] = 'status=%s';
            $params[] = $state['filter'];
        }

        $where_sql = ' WHERE ' . implode(' AND ', $where);
        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table}{$where_sql}", $params));
        $total_pages = max(1, (int)ceil($total / $state['per_page']));
        $state['paged'] = min($state['paged'], $total_pages);
        $offset = ($state['paged'] - 1) * $state['per_page'];
        $order_clause = $this->subscriber_order_clause($state);
        $query_params = array_merge($params, array($state['per_page'], $offset));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}{$where_sql} ORDER BY {$order_clause} LIMIT %d OFFSET %d",
            $query_params
        ));

        return array(
            'rows' => is_array($rows) ? $rows : array(),
            'total' => $total,
            'total_pages' => $total_pages,
        );
    }

    private function subscriber_sort_header($label, $column, $state, $page) {
        $active = (($state['orderby'] ?? 'created') === $column);
        $current_order = (($state['order'] ?? 'desc') === 'asc') ? 'asc' : 'desc';
        $next_order = ($active && $current_order === 'desc') ? 'asc' : 'desc';
        $url = add_query_arg($this->subscriber_query_args($state, $page, array(
            'subscriber_orderby' => $column,
            'subscriber_order' => $next_order,
            'subscriber_paged' => 1,
        )), admin_url('admin.php'));
        $indicator = $active ? ($current_order === 'asc' ? '&#8593;' : '&#8595;') : '&#8597;';
        $aria_sort = $active ? ($current_order === 'asc' ? ' aria-sort="ascending"' : ' aria-sort="descending"') : '';
        $title = 'Sort by ' . $label . ' ' . ($next_order === 'asc' ? 'ascending' : 'descending');

        return '<th scope="col" class="wpnc-sortable-column"' . $aria_sort . '><a class="wpnc-sort-link" href="' . esc_url($url) . '" title="' . esc_attr($title) . '"><span>' . esc_html($label) . '</span><span class="wpnc-sort-indicator" aria-hidden="true">' . $indicator . '</span></a></th>';
    }

    private function render_subscriber_filters($state, $page, $total) {
        $clear_url = admin_url('admin.php?page=' . rawurlencode($page));
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="wpnc-subscriber-filters">';
        echo '<input type="hidden" name="page" value="' . esc_attr($page) . '">';
        echo '<input type="hidden" name="subscriber_orderby" value="' . esc_attr($state['orderby']) . '">';
        echo '<input type="hidden" name="subscriber_order" value="' . esc_attr($state['order']) . '">';
        echo '<label class="wpnc-subscriber-search"><span>Search subscribers</span><input type="search" name="subscriber_search" value="' . esc_attr($state['search']) . '" placeholder="Email, name or source"></label>';
        echo '<label><span>Filter</span><select name="subscriber_filter">';
        foreach (array(
            'all' => 'All records',
            'active' => 'Active sending',
            'disabled' => 'Disabled sending',
            'subscribed' => 'Subscribed',
            'unconfirmed' => 'Unconfirmed',
            'unsubscribed' => 'Unsubscribed',
            'bounced' => 'Bounced',
        ) as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($state['filter'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label class="wpnc-subscriber-per-page"><span>Rows per page</span><input type="number" name="subscriber_per_page" min="1" max="500" step="1" value="' . absint($state['per_page']) . '"></label>';
        echo '<div class="wpnc-subscriber-filter-actions"><button class="button button-primary" type="submit">Apply</button><a class="button" href="' . esc_url($clear_url) . '">Clear</a></div>';
        echo '<strong class="wpnc-subscriber-result-count">' . esc_html(number_format_i18n($total)) . ' result' . ($total === 1 ? '' : 's') . '</strong>';
        echo '</form>';
    }

    private function render_subscriber_pagination($state, $page, $total, $total_pages) {
        if ($total < 1) return;
        $start = (($state['paged'] - 1) * $state['per_page']) + 1;
        $end = min($total, $state['paged'] * $state['per_page']);
        echo '<div class="wpnc-subscriber-pagination"><span>Showing ' . esc_html(number_format_i18n($start)) . '&ndash;' . esc_html(number_format_i18n($end)) . ' of ' . esc_html(number_format_i18n($total)) . '</span>';
        if ($total_pages > 1) {
            $base = add_query_arg($this->subscriber_query_args($state, $page, array('subscriber_paged' => '%#%')), admin_url('admin.php'));
            $base = str_replace('%25%23%25', '%#%', $base);
            echo wp_kses_post(paginate_links(array(
                'base' => $base,
                'format' => '',
                'current' => $state['paged'],
                'total' => $total_pages,
                'type' => 'list',
                'prev_text' => '&larr; Previous',
                'next_text' => 'Next &rarr;',
            )));
        }
        echo '</div>';
    }

    public function page_subscribers() {
        global $wpdb;
        $this->admin_wrap_start(__('Subscribers', 'wp-newslatter-campaigns'));
        $active = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table('subscribers')} WHERE is_demo=0 AND enabled=1");
        $disabled = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table('subscribers')} WHERE is_demo=0 AND enabled=0");
        echo '<div class="wpnc-cards wpnc-cards-compact"><div><strong>' . esc_html($active) . '</strong><span>Active subscribers</span></div><div><strong>' . esc_html($disabled) . '</strong><span>Disabled subscribers</span></div></div>';
        echo '<details class="wpnc-card wpnc-admin-accordion wpnc-add-subscriber-accordion"><summary><span class="wpnc-admin-accordion__icon" aria-hidden="true"></span><span class="wpnc-admin-accordion__heading"><strong>Add subscriber manually</strong><small>Create one regular subscriber without leaving this screen.</small></span><span class="wpnc-admin-accordion__action wpnc-admin-accordion__action-open">Open form</span><span class="wpnc-admin-accordion__action wpnc-admin-accordion__action-close">Close form</span></summary><div class="wpnc-admin-accordion__body"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpnc-form-stack wpnc-compact-subscriber-form">';
        wp_nonce_field('wp_newslatter_campaigns_add_subscriber');
        echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_add_subscriber"><div class="wpnc-form-grid wpnc-form-grid--subscriber"><label>Email<input type="email" name="email" autocomplete="email" required></label><label>First name<input type="text" name="first_name" autocomplete="given-name"></label><label>Last name<input type="text" name="last_name" autocomplete="family-name"></label><label>Status<select name="status"><option value="subscribed">Subscribed</option><option value="unconfirmed">Unconfirmed</option><option value="unsubscribed">Unsubscribed</option><option value="bounced">Bounced</option></select></label><label class="wpnc-check wpnc-form-grid__full"><input type="checkbox" name="enabled" value="1" checked> Active and eligible for live campaigns</label><div class="wpnc-form-actions wpnc-form-grid__full"><button class="button button-primary">Add subscriber</button></div></div></form></div></details>';
        echo '<div class="wpnc-card wpnc-subscriber-controls"><div class="wpnc-card-heading"><div><h2>Subscriber controls</h2><p>Disabling subscribers keeps every record but excludes it from all live campaign sends. No newsletter is sent while disabled.</p></div></div><div class="wpnc-toolbar"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-wpnc-confirm="Disable every regular subscriber?"><input type="hidden" name="action" value="wp_newslatter_campaigns_bulk_subscriber_status"><input type="hidden" name="scope" value="regular"><input type="hidden" name="enabled" value="0">';
        wp_nonce_field('wp_newslatter_campaigns_bulk_subscriber_status_regular');
        echo '<button class="button">Disable all subscribers</button></form><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wp_newslatter_campaigns_bulk_subscriber_status"><input type="hidden" name="scope" value="regular"><input type="hidden" name="enabled" value="1">';
        wp_nonce_field('wp_newslatter_campaigns_bulk_subscriber_status_regular');
        echo '<button class="button">Activate all subscribers</button></form><a class="button" href="' . esc_url(admin_url('admin-post.php?action=wp_newslatter_campaigns_export_csv')) . '">Export subscribers CSV</a><a class="button" href="' . esc_url(admin_url('admin.php?page=wp-newslatter-campaigns-demo-subscribers')) . '">Demo subscribers</a><a class="button" href="' . esc_url(admin_url('admin.php?page=newsletter_subscription_lists')) . '">Lists</a></div></div>';
        $state = $this->subscriber_list_state();
        $data = $this->subscriber_list_data(false, $state);
        $this->render_subscriber_table($data['rows'], false, $state, $data['total'], $data['total_pages']);
        $this->subscriber_delete_modal();
        $this->admin_wrap_end();
    }

    public function page_demo_subscribers() {
        global $wpdb;
        $this->admin_wrap_start(__('Demo Subscribers', 'wp-newslatter-campaigns'));
        $active = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table('subscribers')} WHERE is_demo=1 AND enabled=1");
        echo '<div class="wpnc-card"><h2>Add multiple demo accounts</h2><p>Enter one or more email addresses separated by new lines, commas, or spaces. Demo accounts never receive normal campaign sends.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpnc-form-stack">';
        wp_nonce_field('wp_newslatter_campaigns_add_demo_subscribers');
        echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_add_demo_subscribers"><label>Demo email addresses<textarea name="demo_emails" rows="7" placeholder="test1@example.com&#10;test2@example.com" required></textarea></label><label class="wpnc-check"><input type="checkbox" name="enabled" value="1" checked> Activate imported demo accounts</label><button class="button button-primary">Add demo subscribers</button></form></div>';
        echo '<div class="wpnc-card"><h2>Demo sending status</h2><p><strong>' . esc_html($active) . '</strong> active demo subscriber(s). Campaigns are sent to these accounts only when you press <em>Send to demo subscribers</em> in Campaigns → Edit / test.</p><div class="wpnc-toolbar"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-wpnc-confirm="Disable every demo subscriber?"><input type="hidden" name="action" value="wp_newslatter_campaigns_bulk_subscriber_status"><input type="hidden" name="scope" value="demo"><input type="hidden" name="enabled" value="0">';
        wp_nonce_field('wp_newslatter_campaigns_bulk_subscriber_status_demo');
        echo '<button class="button">Disable all demo subscribers</button></form><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wp_newslatter_campaigns_bulk_subscriber_status"><input type="hidden" name="scope" value="demo"><input type="hidden" name="enabled" value="1">';
        wp_nonce_field('wp_newslatter_campaigns_bulk_subscriber_status_demo');
        echo '<button class="button">Activate all demo subscribers</button></form></div></div>';
        $state = $this->subscriber_list_state();
        $data = $this->subscriber_list_data(true, $state);
        $this->render_subscriber_table($data['rows'], true, $state, $data['total'], $data['total_pages']);
        $this->subscriber_delete_modal();
        $this->admin_wrap_end();
    }

    private function render_subscriber_table($rows, $demo = false, $state = array(), $total = 0, $total_pages = 1) {
        $can_remove_subscribers = $this->current_user_can_remove_subscribers();
        $page = $demo ? 'wp-newslatter-campaigns-demo-subscribers' : 'wp-newslatter-campaigns-subscribers';
        $sending_header = $this->subscriber_sort_header('Sending', 'sending', $state, $page);
        $created_header = $this->subscriber_sort_header('Created', 'created', $state, $page);
        echo '<div class="wpnc-card wpnc-subscriber-list-card"><div class="wpnc-card-heading"><div><h2>' . ($demo ? 'Demo accounts' : 'Subscriber list') . '</h2><p>Search, filter, choose up to 500 rows per page, or click Sending and Created to sort.</p></div></div>';
        $this->render_subscriber_filters($state, $page, $total);
        echo '<div class="wpnc-subscriber-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">Email</th><th scope="col">Name</th><th scope="col">Subscription</th>' . $sending_header . '<th scope="col">Lists</th><th scope="col">Source</th>' . $created_header . '<th scope="col">Actions</th></tr></thead><tbody>';
        if (empty($rows)) echo '<tr><td colspan="8">No records found.</td></tr>';
        foreach ((array)$rows as $r) {
            $return_page = $demo ? 'wp-newslatter-campaigns-demo-subscribers' : 'wp-newslatter-campaigns-subscribers';
            $next = empty($r->enabled) ? 1 : 0;
            $toggle_url = wp_nonce_url(admin_url('admin-post.php?action=wp_newslatter_campaigns_toggle_subscriber&id=' . absint($r->id) . '&enabled=' . $next . '&return_page=' . rawurlencode($return_page)), 'wp_newslatter_campaigns_toggle_subscriber_' . absint($r->id));
            $list_names = array();
            foreach ($this->normalize_list_ids($r->lists ?? array()) as $list_id) $list_names[] = $this->list_label($list_id);
            echo '<tr><td><strong>' . esc_html($r->email) . '</strong></td><td>' . esc_html(trim($r->first_name . ' ' . $r->last_name)) . '</td><td>' . esc_html($r->status) . '</td><td><span class="wpnc-pill ' . (!empty($r->enabled) ? 'wpnc-pill-sent' : 'wpnc-pill-disabled') . '">' . (!empty($r->enabled) ? 'Active' : 'Disabled') . '</span></td><td>' . esc_html($list_names ? implode(', ', $list_names) : '—') . '</td><td>' . esc_html($r->source) . '</td><td>' . esc_html($r->created) . '</td><td class="wpnc-table-actions"><a class="button" href="' . esc_url($toggle_url) . '">' . (!empty($r->enabled) ? 'Disable' : 'Activate') . '</a>';
            if ($can_remove_subscribers) {
                $delete_url = wp_nonce_url(admin_url('admin-post.php?action=wp_newslatter_campaigns_delete_subscriber&id=' . absint($r->id) . '&return_page=' . rawurlencode($return_page)), 'wp_newslatter_campaigns_delete_subscriber_' . absint($r->id));
                echo '<a href="#" class="button button-small button-link-delete wpnc-subscriber-delete-link" data-delete-url="' . esc_url($delete_url) . '" data-email="' . esc_attr($r->email) . '">Delete</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        $this->render_subscriber_pagination($state, $page, $total, $total_pages);
        echo '</div>';
    }

    private function subscriber_delete_modal() {
        if (!$this->current_user_can_remove_subscribers()) return;
        echo '<div id="wpnc-subscriber-delete-modal" class="wpnc-confirm-modal" hidden aria-hidden="true"><div class="wpnc-confirm-modal__backdrop" data-wpnc-modal-cancel></div><div class="wpnc-confirm-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wpnc-subscriber-confirm-title" aria-describedby="wpnc-subscriber-confirm-description"><h2 id="wpnc-subscriber-confirm-title">Really delete this subscriber?</h2><p id="wpnc-subscriber-confirm-description">This permanently removes <strong data-wpnc-subscriber-email></strong>. Use Disable instead when the record should be retained without receiving emails.</p><div class="wpnc-confirm-modal__actions"><button type="button" class="button" data-wpnc-modal-cancel>Cancel</button><a class="button button-link-delete wpnc-confirm-delete" href="#">Delete</a></div></div></div>';
    }

    public function page_campaigns() {
        global $wpdb;
        $this->admin_wrap_start(__('Campaigns', 'wp-newslatter-campaigns'));
        $edit_id = absint($_GET['edit'] ?? 0);
        if ($edit_id) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('campaigns')} WHERE id=%d", $edit_id));
            if ($row) {
                echo '<div class="wpnc-toolbar"><a class="button" href="' . esc_url(admin_url('admin.php?page=wp-newslatter-campaigns-campaigns')) . '">&larr; Back to campaigns</a><a class="button" href="' . esc_url(admin_url('admin.php?page=wp-newslatter-campaigns-upload')) . '">Upload new design</a></div>';
                echo '<div class="wpnc-editor-grid"><div class="wpnc-card"><h2>Edit newsletter</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('wp_newslatter_campaigns_save_campaign_' . $edit_id);
                echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_save_campaign"><input type="hidden" name="id" value="' . absint($edit_id) . '">';
                echo '<label class="wpnc-label">Subject</label><input class="widefat wpnc-input" name="subject" value="' . esc_attr($row->subject) . '" required>';
                echo '<label class="wpnc-label">Preheader / title</label><input class="widefat wpnc-input" name="title" value="' . esc_attr($row->title) . '">';
                echo '<label class="wpnc-label">Audience</label><select class="widefat wpnc-input" name="list_id"><option value="0" ' . selected(absint($row->list_id), 0, false) . '>All active subscribers</option>';
                foreach ($this->configured_lists(false) as $list_id => $list) echo '<option value="' . absint($list_id) . '" ' . selected(absint($row->list_id), absint($list_id), false) . '>' . esc_html($list['name']) . '</option>';
                echo '</select><p class="description">List membership is managed from <a href="' . esc_url(admin_url('admin.php?page=newsletter_subscription_lists')) . '">Lists</a>.</p>';
                echo '<label class="wpnc-label">HTML source</label><textarea name="html" rows="24" class="large-text code wpnc-source">' . esc_textarea($row->html) . '</textarea>';
                echo '<p><button class="button button-primary button-hero">Save campaign</button></p></form></div>';
                echo '<div class="wpnc-card"><h2>Preview and testing</h2><p class="description">Preview with test placeholders. Send a single proof email before sending to subscribers.</p>';
                echo '<div class="wpnc-preview wpnc-preview-editor"><iframe title="Campaign preview" width="100%" srcdoc="' . esc_attr($this->personalize($row->html, (object)array('first_name'=>'Client','last_name'=>'Tester','email'=>'client@example.com'), $row)) . '"></iframe></div>';
                echo '<form class="wpnc-test-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('wp_newslatter_campaigns_test_campaign_' . $edit_id);
                echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_test_campaign"><input type="hidden" name="id" value="' . absint($edit_id) . '"><label class="wpnc-label">Send proof to</label><div class="wpnc-inline"><input type="email" name="test_email" class="regular-text" placeholder="client@example.com" value="' . esc_attr(get_option('admin_email')) . '" required><button class="button button-primary">Send test</button></div></form>';
                echo '<form class="wpnc-test-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('wp_newslatter_campaigns_demo_campaign_' . $edit_id);
                echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_demo_campaign"><input type="hidden" name="id" value="' . absint($edit_id) . '"><label class="wpnc-label">Demo delivery check</label><div class="wpnc-inline"><input type="email" name="demo_email" class="regular-text" placeholder="your@email.com" value="' . esc_attr(get_option('admin_email')) . '" required><button class="button">Run demo</button></div><p class="description">Creates/sends one business-style proof, records the status, and confirms that WordPress mail accepted the message.</p></form>';
                echo '<form class="wpnc-test-form wpnc-demo-send-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('wp_newslatter_campaigns_send_demo_subscribers_' . $edit_id);
                echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_send_demo_subscribers"><input type="hidden" name="id" value="' . absint($edit_id) . '"><input type="hidden" name="return_to" value="edit"><label class="wpnc-label">Send to demo subscribers</label><div class="wpnc-inline"><button class="button button-primary">Send campaign to all active demo subscribers</button><a class="button" href="' . esc_url(admin_url('admin.php?page=wp-newslatter-campaigns-demo-subscribers')) . '">Manage demo subscribers</a></div><p class="description">Sends this campaign only to enabled accounts in the separate Demo Subscribers section. Regular subscribers are not included and may remain disabled.</p></form>';
                $logs = get_option('wp_newslatter_campaigns_test_logs', array());
                if (!empty($logs)) {
                    echo '<h3>Latest test status</h3><div class="wpnc-status-list">';
                    foreach (array_slice(array_reverse((array)$logs), 0, 8) as $log) {
                        echo '<div class="wpnc-status-item"><strong>' . esc_html($log['status'] ?? '') . '</strong><span>' . esc_html($log['email'] ?? '') . '</span><small>' . esc_html($log['time'] ?? '') . ' - ' . esc_html($log['message'] ?? '') . '</small></div>';
                    }
                    echo '</div>';
                }
                echo '</div></div>';

                $run_id = $this->latest_live_run_id($edit_id);
                $unsent_total = 0;
                $unsent_rows = array();
                if ($run_id !== '') {
                    $unsent_total = (int)$wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$this->table('sent')} WHERE campaign_id=%d AND run_id=%s AND status IN ('pending','retry','processing','error')",
                        $edit_id,
                        $run_id
                    ));
                    if ($unsent_total) {
                        $unsent_rows = $wpdb->get_results($wpdb->prepare(
                            "SELECT q.status,q.attempts,q.error,q.updated,s.email,s.first_name,s.last_name,s.status AS subscriber_status,s.enabled,s.is_demo
                             FROM {$this->table('sent')} q
                             LEFT JOIN {$this->table('subscribers')} s ON s.id=q.subscriber_id
                             WHERE q.campaign_id=%d AND q.run_id=%s AND q.status IN ('pending','retry','processing','error')
                             ORDER BY q.id ASC LIMIT 1000",
                            $edit_id,
                            $run_id
                        ));
                    }
                }
                if ($unsent_total) {
                    echo '<div id="wpnc-unsent-recipients" class="wpnc-card wpnc-unsent-card"><div class="wpnc-unsent-heading"><div><h2>Unsent live subscribers</h2><p><strong>' . absint($unsent_total) . '</strong> recipient(s) from the latest live run were not marked sent. Review the list, then retry only these recipients without resending to successful subscribers.</p></div><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Retry this campaign only for the listed unsent active subscribers? Successfully sent subscribers will not receive another copy.\')">';
                    wp_nonce_field('wp_newslatter_campaigns_send_unsent_subscribers_' . $edit_id);
                    echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_send_unsent_subscribers"><input type="hidden" name="id" value="' . absint($edit_id) . '"><button class="button button-primary">Send to unsent subscribers</button></form></div>';
                    echo '<div class="wpnc-unsent-table-wrap"><table class="widefat striped wpnc-unsent-table"><thead><tr><th>Subscriber</th><th>Queue status</th><th>Attempts</th><th>Last update</th><th>Reason</th></tr></thead><tbody>';
                    foreach ((array)$unsent_rows as $unsent) {
                        $eligible = !empty($unsent->email) && is_email($unsent->email) && $unsent->subscriber_status === 'subscribed' && (int)$unsent->enabled === 1 && (int)$unsent->is_demo === 0;
                        echo '<tr><td><strong>' . esc_html($unsent->email ?: 'Subscriber unavailable') . '</strong>';
                        $name = trim((string)$unsent->first_name . ' ' . (string)$unsent->last_name);
                        if ($name !== '') echo '<br><small>' . esc_html($name) . '</small>';
                        if (!$eligible) echo '<br><span class="wpnc-unsent-ineligible">No longer active - will not be retried</span>';
                        echo '</td><td><span class="wpnc-log-status ' . (in_array($unsent->status, array('error'), true) ? 'is-failed' : ($unsent->status === 'retry' ? 'is-retry' : 'is-queued')) . '">' . esc_html($unsent->status) . '</span></td><td>' . absint($unsent->attempts) . '</td><td>' . esc_html($unsent->updated) . '</td><td><span class="wpnc-unsent-error">' . esc_html($unsent->error ?: 'Waiting for delivery worker.') . '</span></td></tr>';
                    }
                    echo '</tbody></table></div>';
                    if ($unsent_total > count($unsent_rows)) echo '<p class="description">Showing the first 1,000 unsent recipients. The retry action includes every eligible unsent recipient in the latest run.</p>';
                    echo '</div>';
                }

                $has_send_history = $run_id !== '' || (int)$row->total > 0 || (int)$row->sent > 0 || in_array($row->status, array('sending','sent','error'), true);
                if ($row->status === 'draft' && !$has_send_history) {
                    $active_live_edit = $this->campaign_recipient_count($row);
                    $send_live = wp_nonce_url(admin_url('admin-post.php?action=wp_newslatter_campaigns_send_campaign&id=' . absint($edit_id)), 'wp_newslatter_campaigns_send_' . absint($edit_id));
                    $send_live_class = 'button button-primary' . (!$active_live_edit ? ' disabled' : '');
                    $send_live_attrs = !$active_live_edit
                        ? ' aria-disabled="true" onclick="return false;"'
                        : ' onclick="return confirm(\'Send this campaign now to ' . absint($active_live_edit) . ' eligible recipient(s) in ' . esc_js($this->list_label(absint($row->list_id))) . '?\')"';
                    echo '<div class="wpnc-card wpnc-live-ready-card"><h2>Campaign is Draft</h2><p>This campaign is targeted to <strong>' . esc_html($this->list_label(absint($row->list_id))) . '</strong> and currently has <strong>' . absint($active_live_edit) . '</strong> eligible recipient(s). Repeated testing remains available through Demo Subscribers.</p><a class="' . esc_attr($send_live_class) . '" href="' . esc_url($send_live) . '"' . $send_live_attrs . '>Send live</a></div>';
                }

                if ($this->current_user_can_reset_campaigns() && $has_send_history) {
                    $reset_draft = wp_nonce_url(admin_url('admin-post.php?action=wp_newslatter_campaigns_reset_campaign_draft&id=' . absint($edit_id) . '&return_to=edit'), 'wp_newslatter_campaigns_reset_campaign_draft_' . absint($edit_id));
                    echo '<div class="wpnc-card wpnc-reset-card"><h2>Restricted campaign reset</h2><p>Resetting returns this campaign to Draft, cancels pending live jobs, and clears its recipient progress. Delivery logs are kept. After reset, a normal <strong>Send live</strong> button becomes available again.</p><a class="button wpnc-danger-button" href="' . esc_url($reset_draft) . '" onclick="return confirm(\'Reset this campaign to Draft? This is restricted to authorised WP users. Pending delivery will be cancelled and send progress cleared.\')">Reset to Draft</a></div>';
                }

                $this->admin_wrap_end();
                return;
            }
        }
        $transport = $this->mail_transport_status();
        $active_live = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table('subscribers')} WHERE is_demo=0 AND enabled=1 AND status='subscribed'");
        $active_demo = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table('subscribers')} WHERE is_demo=1 AND enabled=1");
        echo '<div class="wpnc-delivery-status"><div><strong>Mail transport</strong><span class="wpnc-health ' . (!empty($transport['ready']) ? 'is-good' : 'is-warning') . '">' . esc_html($transport['label']) . '</span></div><div><strong>Queue engine</strong><span>' . esc_html($this->queue_engine_label()) . '</span></div><div><strong>Live recipients</strong><span>' . absint($active_live) . ' active subscriber(s)</span></div><div><strong>Demo recipients</strong><span>' . absint($active_demo) . ' active demo account(s)</span></div><div><strong>Default throttle</strong><span>' . absint($this->settings()['send_batch_size']) . ' emails every ' . absint($this->settings()['send_batch_interval']) . ' seconds</span></div></div>';
        if (!$active_live && $active_demo) {
            echo '<div class="notice notice-info inline wpnc-demo-only-notice"><p><strong>The live subscriber list is disabled.</strong> Use <em>Send to demo</em> for testing. The live queue and demo delivery are separate.</p></div>';
        }
        echo '<div class="wpnc-toolbar"><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=wp-newslatter-campaigns-upload')) . '">Upload design campaign</a><a class="button" href="' . esc_url(admin_url('admin.php?page=wp-newslatter-campaigns-demo-subscribers')) . '">Manage demo subscribers</a><a class="button" href="' . esc_url(admin_url('admin.php?page=wp-newslatter-campaigns-settings')) . '">Delivery settings</a></div>';
        $rows = $wpdb->get_results("SELECT * FROM {$this->table('campaigns')} ORDER BY id DESC LIMIT 200");
        echo '<div class="wpnc-card"><h2>Newsletters</h2><table class="widefat striped wpnc-campaign-table"><thead><tr><th>Subject</th><th>Status</th><th>Audience</th><th>Type</th><th>Progress</th><th>Engagement</th><th>Updated</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $dup = wp_nonce_url(admin_url('admin-post.php?action=wp_newslatter_campaigns_duplicate_campaign&id=' . absint($r->id)), 'wp_newslatter_campaigns_duplicate_' . absint($r->id));
            $del = wp_nonce_url(admin_url('admin-post.php?action=wp_newslatter_campaigns_delete_campaign&id=' . absint($r->id)), 'wp_newslatter_campaigns_delete_campaign_' . absint($r->id));
            $edit = admin_url('admin.php?page=wp-newslatter-campaigns-campaigns&edit=' . absint($r->id));
            $progress = max(0, min(100, $r->total ? round(($r->sent / max(1, $r->total)) * 100) : ($r->status === 'sent' ? 100 : 0)));
            $failed = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table('sent')} WHERE campaign_id=%d AND status='error'", $r->id));
            $pending = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table('sent')} WHERE campaign_id=%d AND status IN ('pending','retry','processing')", $r->id));
            $has_history = ((int)$r->sent > 0 || (int)$r->total > 0 || in_array($r->status, array('sending','sent','error'), true));
            $send = wp_nonce_url(admin_url('admin-post.php?action=wp_newslatter_campaigns_send_campaign&id=' . absint($r->id)), 'wp_newslatter_campaigns_send_' . absint($r->id));
            $campaign_audience_count = $this->campaign_recipient_count($r);
            $confirm_send = 'Send this campaign now to ' . absint($campaign_audience_count) . ' eligible recipient(s) in ' . $this->list_label(absint($r->list_id)) . '?';
            $resume = wp_nonce_url(admin_url('admin-post.php?action=wp_newslatter_campaigns_resume_campaign&id=' . absint($r->id)), 'wp_newslatter_campaigns_resume_' . absint($r->id));
            echo '<tr><td><strong>' . esc_html($r->subject ?: $r->title) . '</strong><br><small>ID ' . absint($r->id) . '</small></td><td><span class="wpnc-pill wpnc-pill-' . esc_attr($r->status) . '">' . esc_html($r->status) . '</span></td><td>' . esc_html($this->list_label(absint($r->list_id))) . '<br><small>' . absint($campaign_audience_count) . ' eligible now</small></td><td>' . esc_html($r->type) . '</td><td><div class="wpnc-progress"><span style="width:' . esc_attr($progress) . '%"></span></div><small>' . esc_html($r->sent) . ' sent' . ($pending ? ' / ' . esc_html($pending) . ' queued' : '') . ($failed ? ' / ' . esc_html($failed) . ' failed' : '') . '</small></td><td>' . esc_html($r->open_count) . ' opens / ' . esc_html($r->click_count) . ' clicks</td><td>' . esc_html($r->updated) . '</td><td class="wpnc-table-actions"><a class="button button-primary" href="' . esc_url($edit) . '">Edit / test</a><form class="wpnc-inline-action-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('wp_newslatter_campaigns_send_demo_subscribers_' . absint($r->id));
            echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_send_demo_subscribers"><input type="hidden" name="id" value="' . absint($r->id) . '"><input type="hidden" name="return_to" value="list"><button type="submit" class="button wpnc-button-demo"' . (!$active_demo ? ' disabled aria-disabled="true" title="Add or activate a demo subscriber first"' : '') . '>Send to demo</button></form>';
            if ($pending) echo '<a class="button" href="' . esc_url($resume) . '">Resume now</a>';
            if (!$has_history && !$pending && $r->status === 'draft') echo '<a class="button" href="' . esc_url($send) . '" onclick="return confirm(\'' . esc_js($confirm_send) . '\')">Send live</a>';
            if ($pending || $failed) echo '<a class="button" href="' . esc_url($edit . '#wpnc-unsent-recipients') . '">Review unsent (' . absint($pending + $failed) . ')</a>';
            echo '<a class="button" href="' . esc_url($dup) . '">Duplicate</a><a class="button button-link-delete" href="' . esc_url($del) . '" onclick="return confirm(\'Delete campaign?\')">Delete</a></td></tr>';
        }
        echo '</tbody></table></div>';
        $this->admin_wrap_end();
    }

    public function page_upload() {
        $last = get_option(self::UPLOAD_LAST_OPTION, array());
        $campaigns = $this->get_upload_campaign_tree();
        $locations = $this->get_upload_locations();
        $this->admin_wrap_start(__('Campaign Upload', 'wp-newslatter-campaigns'));
        echo '<div class="wpnc-hero"><div><p class="wpnc-kicker">Mail Designer 360 upload</p><p>Upload the ZIP, clean the HTML, rewrite images, create a draft newsletter, preview desktop/mobile and send a proof email from one polished admin screen.</p></div><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=wp-newslatter-campaigns-campaigns')) . '">Newsletters</a></div>';

        echo '<div class="wpnc-upload-grid"><div class="wpnc-card wpnc-upload-card"><h2>Upload Mail Designer 360 ZIP</h2><form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wp_newslatter_campaigns_upload_zip');
        echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_upload_zip"><div class="wpnc-form-stack"><label for="competition_name">Competition / campaign folder<input type="text" class="widefat wpnc-upload-text-input" id="competition_name" name="competition_name" placeholder="competition-4-email-2"></label><p class="description">Leave blank to use ZIP filename. Saved in /wp-content/uploads/newsletter_emails/{folder}/v1/.</p><label>ZIP file<span class="wpnc-file-input"><input id="campaign_zip" type="file" name="campaign_zip" accept=".zip" required></span></label><label class="wpnc-check"><input type="checkbox" name="create_campaign" value="1" checked> Create a draft newsletter after processing</label><label for="wpnc_zip_email_subject">Email subject<input type="text" class="widefat wpnc-upload-text-input" id="wpnc_zip_email_subject" name="subject" placeholder="Clean business offer subject"></label><button class="button button-primary button-hero">Upload and process</button></div></form></div>';

        echo '<div class="wpnc-card wpnc-upload-card"><h2>Create campaign from HTML</h2><p>Use this for simple HTML files or manual source edits.</p><form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wp_newslatter_campaigns_upload_campaign');
        echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_upload_campaign"><div class="wpnc-form-stack"><label for="wpnc_html_email_subject">Subject<input type="text" class="widefat wpnc-upload-text-input" id="wpnc_html_email_subject" name="subject" placeholder="Email subject" required></label><label>HTML file<span class="wpnc-file-input"><input type="file" name="campaign_file" accept=".html,.htm,text/html"></span></label><label>Or paste HTML<textarea name="html" rows="10" class="large-text code"></textarea></label><button class="button button-secondary">Create Campaign</button></div></form></div></div>';

        echo '<div class="wpnc-card"><h2>Uploaded campaigns / file manager</h2><p class="description">Base directory: <code>' . esc_html($locations['base_dir']) . '</code></p><div class="wpnc-file-manager">';
        if (empty($campaigns)) {
            echo '<p>No uploaded newsletter campaign folders found.</p>';
        } else {
            foreach ($campaigns as $campaign) {
                echo '<div class="wpnc-upload-campaign"><div class="wpnc-version-row"><div><strong>' . esc_html($campaign['name']) . '</strong><br><code>' . esc_html($campaign['url']) . '</code></div>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-wpnc-confirm="Delete whole campaign directory and all versions?"><input type="hidden" name="action" value="wp_newslatter_campaigns_delete_upload"><input type="hidden" name="delete_type" value="campaign"><input type="hidden" name="competition" value="' . esc_attr($campaign['name']) . '">';
                wp_nonce_field('wp_newslatter_campaigns_delete_upload');
                echo '<button class="button button-link-delete">Delete</button></form></div>';
                foreach ($campaign['versions'] as $version) {
                    echo '<div class="wpnc-version-row"><div><strong>' . esc_html($version['version']) . '</strong> <span>- ' . esc_html(size_format($version['size'])) . ' - ' . esc_html($version['modified']) . '</span><br><code>' . esc_html($version['url']) . '</code></div><div class="wpnc-actions">';
                    if ($version['html_url']) echo '<a class="button" target="_blank" href="' . esc_url($version['html_url']) . '">Open HTML</a> ';
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-wpnc-confirm="Delete this version directory?"><input type="hidden" name="action" value="wp_newslatter_campaigns_delete_upload"><input type="hidden" name="delete_type" value="version"><input type="hidden" name="competition" value="' . esc_attr($campaign['name']) . '"><input type="hidden" name="version" value="' . esc_attr($version['version']) . '">';
                    wp_nonce_field('wp_newslatter_campaigns_delete_upload');
                    echo '<button class="button button-link-delete">Delete</button></form></div></div>';
                }
                echo '</div>';
            }
        }
        echo '</div></div>';

        if (is_array($last) && !empty($last['html'])) {
            echo '<div class="wpnc-card"><h2>Latest processed campaign</h2><p><strong>Folder:</strong> ' . esc_html($last['competition'] ?? '') . ' <strong>Version:</strong> ' . esc_html($last['version'] ?? '') . '</p><p><strong>Upload URL:</strong> <code>' . esc_html($last['base_url'] ?? '') . '</code></p><p><strong>Saved HTML:</strong> <a target="_blank" href="' . esc_url($last['html_url'] ?? '#') . '">' . esc_html($last['html_url'] ?? '') . '</a></p><p><strong>Images:</strong> ' . intval($last['images_count'] ?? 0) . ' <strong>Ignored files:</strong> ' . intval($last['ignored_count'] ?? 0) . ' <strong>Cleanup:</strong> ' . esc_html($last['cleanup_duration'] ?? '') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('wp_newslatter_campaigns_create_from_upload');
            echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_create_from_upload"><input type="hidden" name="upload_key" value="last"><input class="regular-text" name="subject" placeholder="Email subject" value="' . esc_attr($last['subject'] ?? ($last['competition'] ?? '')) . '"> <button class="button button-primary">Create / update draft campaign from this upload</button></form></div>';

            if (!empty($last['report']) && is_array($last['report'])) {
                echo '<div class="wpnc-card"><h2>HTML check / fixes report</h2><ul class="wpnc-report">';
                foreach ($last['report'] as $item) echo '<li>' . esc_html($item) . '</li>';
                echo '</ul></div>';
            }

            echo '<div class="wpnc-card"><h2>Clean source</h2><p><button type="button" class="button" data-wpnc-copy-source>Copy source</button></p><textarea id="wpnc-upload-source" class="large-text code wpnc-source" rows="20">' . esc_textarea($last['html']) . '</textarea></div>';
            echo '<div class="wpnc-card"><h2>Test preview</h2><div class="wpnc-preview-grid"><div><h3>Desktop</h3><div class="wpnc-preview wpnc-preview-desktop"><iframe title="Desktop preview" width="900" srcdoc="' . esc_attr($last['html']) . '"></iframe></div></div><div><h3>Mobile</h3><div class="wpnc-preview wpnc-preview-mobile"><iframe title="Mobile preview" width="393" srcdoc="' . esc_attr($last['html']) . '"></iframe></div></div></div></div>';
        }
        $this->admin_wrap_end();
    }

    public function page_reports() {
        global $wpdb;
        $this->admin_wrap_start(__('Reports', 'wp-newslatter-campaigns'));
        $rows = $wpdb->get_results("SELECT * FROM {$this->table('campaigns')} ORDER BY id DESC LIMIT 100");
        echo '<table class="widefat striped"><thead><tr><th>Campaign</th><th>Status</th><th>Sent</th><th>Opens</th><th>Clicks</th><th>Open rate</th><th>Click rate</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $open_rate = $r->sent ? round(($r->open_count / max(1, $r->sent)) * 100, 1) . '%' : '0%';
            $click_rate = $r->sent ? round(($r->click_count / max(1, $r->sent)) * 100, 1) . '%' : '0%';
            echo '<tr><td>' . esc_html($r->subject ?: $r->title) . '</td><td>' . esc_html($r->status) . '</td><td>' . esc_html($r->sent) . '</td><td>' . esc_html($r->open_count) . '</td><td>' . esc_html($r->click_count) . '</td><td>' . esc_html($open_rate) . '</td><td>' . esc_html($click_rate) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Recent events</h2>';
        $events = $wpdb->get_results("SELECT * FROM {$this->table('events')} ORDER BY id DESC LIMIT 200");
        echo '<table class="widefat striped"><thead><tr><th>Type</th><th>Campaign</th><th>Subscriber</th><th>URL</th><th>Created</th></tr></thead><tbody>';
        foreach ($events as $e) echo '<tr><td>' . esc_html($e->event_type) . '</td><td>' . absint($e->campaign_id) . '</td><td>' . absint($e->subscriber_id) . '</td><td>' . esc_html($e->url) . '</td><td>' . esc_html($e->created) . '</td></tr>';
        echo '</tbody></table>';
        $this->admin_wrap_end();
    }

    public function page_automations() {
        global $wpdb;
        $this->admin_wrap_start(__('Automations', 'wp-newslatter-campaigns'));
        echo '<p>Basic automated newsletters replacement: create recurring latest-post campaigns manually from this configuration. Cron scheduling can be extended safely later without changing data structure.</p>';
        echo '<div class="wpnc-card"><h2>Add automation</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wp_newslatter_campaigns_save_automation');
        echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_save_automation"><table class="form-table"><tr><th>Name</th><td><input class="regular-text" name="name" required></td></tr><tr><th>Subject</th><td><input class="regular-text" name="subject" required></td></tr><tr><th>Frequency</th><td><select name="frequency"><option value="daily">Daily</option><option value="weekly" selected>Weekly</option><option value="monthly">Monthly</option></select></td></tr><tr><th>Post count</th><td><input type="number" min="1" max="20" name="post_count" value="5"></td></tr><tr><th>Intro HTML</th><td><textarea class="large-text code" rows="6" name="intro"></textarea></td></tr><tr><th>Enabled</th><td><label><input type="checkbox" name="enabled" value="1" checked> Enabled</label></td></tr></table><p><button class="button button-primary">Save automation</button></p></form></div>';
        $rows = $wpdb->get_results("SELECT * FROM {$this->table('automations')} ORDER BY id DESC");
        echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Subject</th><th>Frequency</th><th>Post count</th><th>Enabled</th><th>Last run</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td>' . esc_html($r->name) . '</td><td>' . esc_html($r->subject) . '</td><td>' . esc_html($r->frequency) . '</td><td>' . absint($r->post_count) . '</td><td>' . ($r->enabled ? 'Yes' : 'No') . '</td><td>' . esc_html($r->last_run) . '</td></tr>';
        echo '</tbody></table>';
        $this->admin_wrap_end();
    }

    public function page_webhooks() {
        global $wpdb;
        $this->admin_wrap_start(__('Webhooks', 'wp-newslatter-campaigns'));
        echo '<div class="wpnc-card"><h2>Add webhook</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wp_newslatter_campaigns_save_webhook');
        echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_save_webhook"><table class="form-table"><tr><th>Name</th><td><input class="regular-text" name="name" required></td></tr><tr><th>Event</th><td><select name="event"><option value="subscribe">Subscribe</option><option value="send">Send</option><option value="open">Open</option><option value="click">Click</option></select></td></tr><tr><th>URL</th><td><input class="large-text" type="url" name="url" required></td></tr><tr><th>Enabled</th><td><label><input type="checkbox" name="enabled" value="1" checked> Enabled</label></td></tr></table><p><button class="button button-primary">Save webhook</button></p></form></div>';
        $rows = $wpdb->get_results("SELECT * FROM {$this->table('webhooks')} ORDER BY id DESC");
        echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Event</th><th>URL</th><th>Enabled</th><th>Action</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $del = wp_nonce_url(admin_url('admin-post.php?action=wp_newslatter_campaigns_delete_webhook&id=' . absint($r->id)), 'wp_newslatter_campaigns_delete_webhook_' . absint($r->id));
            echo '<tr><td>' . esc_html($r->name) . '</td><td>' . esc_html($r->event) . '</td><td><code>' . esc_html($r->url) . '</code></td><td>' . ($r->enabled ? 'Yes' : 'No') . '</td><td><a class="button button-link-delete" href="' . esc_url($del) . '" onclick="return confirm(\'Delete webhook?\')">Delete</a></td></tr>';
        }
        echo '</tbody></table>';
        $this->admin_wrap_end();
    }

    public function page_import() {
        $this->admin_wrap_start(__('Import / Export', 'wp-newslatter-campaigns'));
        echo '<div class="wpnc-upload-grid"><div class="wpnc-card"><h2>Subscriber CSV import</h2><p>Columns supported: email, first_name/name, last_name/surname, status.</p><form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpnc-form-stack">';
        wp_nonce_field('wp_newslatter_campaigns_import_csv');
        echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_import_csv"><label>CSV file<input type="file" name="csv" accept=".csv,text/csv" required></label><button class="button button-primary">Import subscribers</button></form></div>';
        echo '<div class="wpnc-card"><h2>Campaign JSON import</h2><p>Imports complete campaign subjects, HTML, text, status, statistics and options from a WP campaign export.</p><form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpnc-form-stack">';
        wp_nonce_field('wp_newslatter_campaigns_import_campaigns');
        echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_import_campaigns"><label>Campaign export JSON<input type="file" name="campaigns_json" accept=".json,application/json" required></label><button class="button button-primary">Import campaigns</button></form></div></div>';
        echo '<div class="wpnc-card"><h2>Exports</h2><div class="wpnc-toolbar"><a class="button" href="' . esc_url(admin_url('admin-post.php?action=wp_newslatter_campaigns_export_csv')) . '">Export subscribers CSV</a><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_newslatter_campaigns_export_campaigns'), 'wp_newslatter_campaigns_export_campaigns')) . '">Export campaigns JSON</a></div></div>';
        $this->admin_wrap_end();
    }

    public function page_settings() {
        $s = $this->settings();
        $transport = $this->mail_transport_status();
        $this->admin_wrap_start(__('Settings', 'wp-newslatter-campaigns'));
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpnc-settings-grid">';
        wp_nonce_field('wp_newslatter_campaigns_save_settings');
        echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_save_settings">';
        echo '<div class="wpnc-card"><h2>Sender and delivery</h2>';
        echo '<div class="wpnc-delivery-status"><div><strong>Mail transport</strong><span class="wpnc-health ' . (!empty($transport['ready']) ? 'is-good' : 'is-warning') . '">' . esc_html($transport['label']) . '</span></div><div><strong>Background queue</strong><span>' . esc_html($this->queue_engine_label()) . '</span></div><div><strong>Sending method</strong><span>One recipient per message, throttled live batches, immediate paced demo delivery</span></div></div>';
        echo '<div class="notice notice-info inline"><p><strong>SMTP is disabled inside WP Newsletter Campaigns.</strong> Every message is handed to WordPress through <code>wp_mail()</code>. Configure the From identity, queue, and delivery transport in the site mail plugin, such as GD Mail Queue; WP does not apply separate SMTP or sender settings.</p></div>';
        if ($this->is_gd_mail_queue_active()) echo '<p><a class="button" href="' . esc_url(admin_url('plugins.php')) . '">View WordPress mail plugins</a></p>';
        echo '<table class="form-table">';
        foreach (array('admin_email'=>'Admin notification email','ga_utm_source'=>'GA UTM source','ga_utm_medium'=>'GA UTM medium','ga_utm_campaign'=>'GA UTM campaign') as $k=>$label) echo '<tr><th>' . esc_html($label) . '</th><td><input class="regular-text" name="' . esc_attr($k) . '" value="' . esc_attr($s[$k]) . '"></td></tr>';
        echo '<tr><th>Batch size</th><td><input type="number" name="send_batch_size" min="1" max="100" value="' . absint($s['send_batch_size']) . '"><p class="description">Recommended: 20. Each worker handles a controlled group, with one separately tracked message per recipient.</p></td></tr>';
        echo '<tr><th>Time between batches</th><td><input type="number" name="send_batch_interval" min="2" max="900" value="' . absint($s['send_batch_interval']) . '"> seconds<p class="description">Recommended: 5 seconds. Action Scheduler starts the next burst promptly without one long PHP request.</p></td></tr>';
        echo '<tr><th>Hourly safety limit</th><td><input type="number" name="send_hourly_limit" min="0" max="10000" value="' . absint($s['send_hourly_limit']) . '"><p class="description">Recommended starting point: 600. Increase only within the SMTP provider limit and after the sender domain is warmed.</p></td></tr>';
        echo '<tr><th>Automatic retries</th><td><input type="number" name="send_max_retries" min="0" max="10" value="' . absint($s['send_max_retries']) . '"><p class="description">Retries temporary wp_mail failures; permanently failed recipients remain visible in campaign progress.</p></td></tr>';
        echo '<tr><th>Retry delay</th><td><input type="number" name="send_retry_delay" min="60" max="86400" value="' . absint($s['send_retry_delay']) . '"> seconds<p class="description">Recommended: 900 seconds (15 minutes), with a longer delay after each attempt.</p></td></tr>';
        echo '<tr><th>Pause between messages</th><td><input type="number" name="send_batch_pause_ms" min="0" max="2000" value="' . absint($s['send_batch_pause_ms']) . '"> milliseconds<p class="description">Recommended: 100 ms. A small pause protects the site mail queue while keeping large sends moving.</p></td></tr>';
        echo '<tr><th>Email footer text</th><td><textarea class="large-text" rows="3" name="footer_text">' . esc_textarea($s['footer_text']) . '</textarea><p class="description">Keep your organisation identity and postal/contact information here. Every live email also receives a visible unsubscribe link and List-Unsubscribe headers.</p></td></tr>';
        echo '</table>';
        echo '</div>';
        echo '<div class="wpnc-card"><h2>Subscriber growth and compliance</h2><table class="form-table">';
        echo '<tr><th>Privacy checkbox</th><td><label><input type="checkbox" name="privacy_checkbox" value="1" ' . checked($s['privacy_checkbox'], 1, false) . '> Show GDPR/privacy consent checkbox on WP forms</label></td></tr>';
        echo '<tr><th>Double opt-in</th><td><label><input type="checkbox" name="double_optin" value="1" ' . checked($s['double_optin'], 1, false) . '> Store new subscribers as unconfirmed until a confirmation flow is connected</label></td></tr>';
        echo '<tr><th>Welcome email</th><td><label><input type="checkbox" name="welcome_email_enabled" value="1" ' . checked($s['welcome_email_enabled'], 1, false) . '> Send a branded thank-you email to new form subscribers</label><p class="description">Sent once when a new address subscribes through a WP newsletter form. Existing active subscribers are not emailed again.</p></td></tr>';
        echo '<tr><th>Welcome subject</th><td><input class="regular-text" name="welcome_email_subject" value="' . esc_attr($s['welcome_email_subject']) . '"></td></tr>';
        echo '<tr><th>Welcome heading</th><td><input class="regular-text" name="welcome_email_heading" value="' . esc_attr($s['welcome_email_heading']) . '"></td></tr>';
        echo '<tr><th>Welcome message</th><td><textarea class="large-text" rows="4" name="welcome_email_message">' . esc_textarea($s['welcome_email_message']) . '</textarea></td></tr>';
        echo '<tr><th>Spam domain blacklist</th><td><textarea class="large-text" rows="4" name="domain_blacklist">' . esc_textarea($s['domain_blacklist']) . '</textarea><p class="description">One blocked email domain per line, for example disposable.test.</p></td></tr>';
        echo '</table></div>';
        echo '<div class="wpnc-card"><h2>Integrations</h2><table class="form-table">';
        echo '<tr><th>WooCommerce checkout opt-in</th><td><label><input type="checkbox" name="woocommerce_checkout_optin" value="1" ' . checked($s['woocommerce_checkout_optin'], 1, false) . '> Add checkout subscription checkbox</label></td></tr>';
        echo '<tr><th>WP user opt-in</th><td><label><input type="checkbox" name="wp_user_optin" value="1" ' . checked($s['wp_user_optin'], 1, false) . '> Subscribe new WP users</label></td></tr>';
        echo '<tr><th>Contact Form 7</th><td><label><input type="checkbox" name="cf7_optin" value="1" ' . checked($s['cf7_optin'], 1, false) . '> Capture CF7 submissions when a newsletter opt-in field is present</label><p class="description">Use a checkbox named newsletter, wp_newslatter_campaigns, or subscribe.</p></td></tr>';
        echo '<tr><th>Subscribe on comment</th><td><label><input type="checkbox" name="subscribe_on_comment" value="1" ' . checked($s['subscribe_on_comment'], 1, false) . '> Add opt-in checkbox below comment forms</label></td></tr>';
        echo '<tr><th>Legacy webhook URLs</th><td><textarea class="large-text" rows="4" name="webhook_urls">' . esc_textarea($s['webhook_urls']) . '</textarea><p class="description">One URL per line. Also use WP Newsletter Campaigns -> Webhooks for structured webhooks.</p></td></tr>';
        echo '</table></div>';
        echo '<div class="wpnc-card"><h2>Admin experience</h2><table class="form-table">';
        echo '<tr><th>Interface style</th><td><select name="admin_theme"><option value="wpnc" ' . selected($s['admin_theme'], 'wpnc', false) . '>WP modern cards</option><option value="compact" ' . selected($s['admin_theme'], 'compact', false) . '>Compact</option></select></td></tr>';
        echo '<tr><th>Inactive cleanup</th><td><input type="number" name="delete_inactive_days" min="0" max="3650" value="' . absint($s['delete_inactive_days']) . '"><p class="description">0 disables automatic cleanup. Use export before deleting inactive records.</p></td></tr>';
        echo '</table><p><button class="button button-primary button-hero">Save Settings</button></p></div>';
        echo '</form>';
        $this->render_delivery_log_panel();
        $this->admin_wrap_end();
    }

    private function render_delivery_log_panel() {
        global $wpdb;
        $table = $this->table('delivery_logs');
        $campaigns = $this->table('campaigns');
        $sent = $this->table('sent');
        $counts = array('queued'=>0,'processing'=>0,'retry'=>0,'accepted'=>0,'failed'=>0,'skipped'=>0);
        $since = wp_date('Y-m-d H:i:s', time() - DAY_IN_SECONDS, wp_timezone());
        $summary_rows = $wpdb->get_results($wpdb->prepare("SELECT status,COUNT(*) qty FROM {$table} WHERE created>=%s GROUP BY status", $since), OBJECT_K);
        foreach ((array)$summary_rows as $status => $row) $counts[$status] = (int)$row->qty;
        $live_queue = $wpdb->get_results("SELECT status,COUNT(*) qty FROM {$sent} WHERE status IN ('pending','processing','retry') GROUP BY status", OBJECT_K);
        $queue_waiting = 0;
        foreach ((array)$live_queue as $row) $queue_waiting += (int)$row->qty;
        $rows = $wpdb->get_results("SELECT l.*,c.subject campaign_subject FROM {$table} l LEFT JOIN {$campaigns} c ON c.id=l.campaign_id ORDER BY l.id DESC LIMIT 100");
        echo '<div class="wpnc-card wpnc-delivery-log-card"><div class="wpnc-log-heading"><div><h2>Sending and delivery log</h2><p>Shows the recipient-by-recipient handoff to WordPress. <strong>WordPress accepted</strong> means <code>wp_mail()</code> accepted the message; check GD Mail Queue or the active site mail plugin for its queued and final delivery status.</p></div><div class="wpnc-toolbar"><a class="button" href="' . esc_url(add_query_arg('wpnc_log_refresh', time(), admin_url('admin.php?page=wp-newslatter-campaigns-settings')) . '#wpnc-delivery-log') . '">Refresh log</a><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Clear the visible WP delivery history? Campaign queue data is not removed.\')">';
        wp_nonce_field('wp_newslatter_campaigns_clear_delivery_logs');
        echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_clear_delivery_logs"><button class="button button-link-delete" type="submit">Clear log</button></form></div></div>';
        echo '<div id="wpnc-delivery-log" class="wpnc-log-summary"><div><span>Live queue</span><strong>' . absint($queue_waiting) . '</strong><small>waiting / processing / retry</small></div><div><span>WordPress accepted, 24h</span><strong>' . absint($counts['accepted']) . '</strong><small>handed to the active mail queue/plugin</small></div><div><span>Failed, 24h</span><strong>' . absint($counts['failed']) . '</strong><small>wp_mail reported failure</small></div><div><span>Retries, 24h</span><strong>' . absint($counts['retry']) . '</strong><small>scheduled for another attempt</small></div></div>';
        echo '<div class="wpnc-log-legend"><span><i class="is-accepted"></i>Relay queued or captured and verified locally</span><span><i class="is-queued"></i>Queued / processing</span><span><i class="is-retry"></i>Retry scheduled</span><span><i class="is-failed"></i>Failed</span></div>';
        if (!$rows) {
            echo '<div class="wpnc-empty">No delivery activity has been recorded yet. Send a proof, demo, or live campaign and refresh this page.</div></div>';
            return;
        }
        echo '<div class="wpnc-table-wrap wpnc-log-table-wrap"><table class="widefat striped wpnc-log-table"><thead><tr><th>Time</th><th>Type</th><th>Campaign</th><th>Recipient</th><th>Status</th><th>Attempt</th><th>Transport detail</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $status = sanitize_key($row->status);
            $status_class = in_array($status, array('accepted','delivered'), true) ? 'is-accepted' : (in_array($status, array('failed','error'), true) ? 'is-failed' : ($status === 'retry' ? 'is-retry' : 'is-queued'));
            $campaign_label = $row->campaign_id ? ('#' . absint($row->campaign_id) . ' ' . (string)$row->campaign_subject) : 'Standalone test';
            $detail = trim((string)$row->response);
            if ($row->transport !== '') $detail .= ($detail !== '' ? ' | ' : '') . 'Transport: ' . $row->transport;
            if ($row->run_id !== '') $detail .= ($detail !== '' ? ' | ' : '') . 'Run: ' . $row->run_id;
            if ($row->message_id !== '') $detail .= ($detail !== '' ? ' | ' : '') . 'Message-ID: ' . $row->message_id;
            if ($row->delivery_id !== '') $detail .= ($detail !== '' ? ' | ' : '') . 'WP ID: ' . $row->delivery_id;
            $accepted_label = (in_array($row->transport, array('capture-smtp','capture-api'), true) || stripos($detail, 'Captured locally') !== false) ? 'Captured locally' : 'WordPress accepted';
            echo '<tr><td><span class="wpnc-log-time">' . esc_html($row->created) . '</span></td><td>' . esc_html(ucfirst($row->delivery_type)) . '</td><td>' . esc_html($campaign_label) . '</td><td><strong>' . esc_html($row->recipient) . '</strong></td><td><span class="wpnc-log-status ' . esc_attr($status_class) . '">' . esc_html($status === 'accepted' ? $accepted_label : ucfirst($status)) . '</span></td><td>' . absint($row->attempt) . '</td><td><span class="wpnc-log-detail">' . esc_html($detail !== '' ? $detail : 'No additional response supplied by the transport.') . '</span></td></tr>';
        }
        echo '</tbody></table></div><p class="description">The latest 100 WP handoff events are shown. For queue, retry, transport, and final delivery details, use GD Mail Queue or the active WordPress mail plugin.</p></div>';
    }

    public function clear_delivery_logs() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('wp_newslatter_campaigns_clear_delivery_logs');
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table('delivery_logs')}");
        delete_option('wp_newslatter_campaigns_test_logs');
        $this->redirect_admin('wp-newslatter-campaigns-settings', 'Delivery log cleared. Campaign queue records were kept.');
    }

    public function save_settings() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('wp_newslatter_campaigns_save_settings');
        $s = $this->settings();
        foreach (array('admin_email','webhook_urls','footer_text','welcome_email_message','ga_utm_source','ga_utm_medium','ga_utm_campaign','domain_blacklist','admin_theme') as $k) $s[$k] = sanitize_textarea_field(wp_unslash($_POST[$k] ?? ''));
        foreach (array('welcome_email_subject','welcome_email_heading') as $k) $s[$k] = sanitize_text_field(wp_unslash($_POST[$k] ?? ''));
        foreach (array('woocommerce_checkout_optin','wp_user_optin','privacy_checkbox','double_optin','welcome_email_enabled','cf7_optin','subscribe_on_comment','popup_enabled') as $k) $s[$k] = !empty($_POST[$k]) ? 1 : 0;
        $s['smtp_enabled'] = 0;
        $s['capture_api_prefer'] = 0;
        $s['send_batch_size'] = max(1, min(100, absint($_POST['send_batch_size'] ?? 20)));
        $s['send_batch_interval'] = max(2, min(900, absint($_POST['send_batch_interval'] ?? 5)));
        $s['send_hourly_limit'] = max(0, min(10000, absint($_POST['send_hourly_limit'] ?? 600)));
        $s['send_max_retries'] = max(0, min(10, absint($_POST['send_max_retries'] ?? 3)));
        $s['send_retry_delay'] = max(60, min(86400, absint($_POST['send_retry_delay'] ?? 900)));
        $s['send_batch_pause_ms'] = max(0, min(2000, absint($_POST['send_batch_pause_ms'] ?? 100)));
        $s['delete_inactive_days'] = max(0, min(3650, absint($_POST['delete_inactive_days'] ?? 0)));
        update_option(self::OPTION, $s, false);
        delete_option(self::SMTP_DIAGNOSTIC_OPTION);
        $this->redirect_admin('wp-newslatter-campaigns-settings', 'Settings saved. Email delivery remains managed by the WordPress mail stack.');
    }


    private function wpbb_captcha_config() {
        if (!function_exists('wpbb_get_option')) return array('provider'=>'', 'site_key'=>'', 'secret_key'=>'');
        $h_enabled = (bool) wpbb_get_option('hcaptcha_enabled', 0);
        $h_site = $h_enabled ? trim((string) wpbb_get_option('hcaptcha_site_key', '')) : '';
        $h_secret = $h_enabled ? trim((string) wpbb_get_option('hcaptcha_secret_key', '')) : '';
        if ($h_site && $h_secret) return array('provider'=>'hcaptcha', 'site_key'=>$h_site, 'secret_key'=>$h_secret);
        $r_enabled = (bool) wpbb_get_option('recaptcha_enabled', 0);
        $r_site = $r_enabled ? trim((string) wpbb_get_option('recaptcha_site_key', '')) : '';
        $r_secret = $r_enabled ? trim((string) wpbb_get_option('recaptcha_secret_key', '')) : '';
        if ($r_site && $r_secret) return array('provider'=>'recaptcha', 'site_key'=>$r_site, 'secret_key'=>$r_secret);
        return array('provider'=>'', 'site_key'=>'', 'secret_key'=>'');
    }

    private function page_has_ws_form() {
        $has_ws_form = false;
        $post = get_queried_object();

        if ($post instanceof WP_Post) {
            $content = (string) $post->post_content;
            $has_ws_form = has_shortcode($content, 'ws_form')
                || (function_exists('has_block') && has_block('wsf-block/form-add', $content));
        }

        /**
         * Allows page builders and template integrations to report a WS Form
         * that is rendered outside the queried post content.
         */
        return (bool) apply_filters('wp_newslatter_campaigns_page_has_ws_form', $has_ws_form);
    }

    public function frontend_assets() {
        if (is_admin()) return;
        $captcha = $this->wpbb_captcha_config();
        $frontend_deps = array();
        $defer_hcaptcha_to_ws_form = false;
        if ($captcha['provider'] === 'hcaptcha') {
            // WS Form injects hCaptcha at runtime and therefore cannot share a
            // WordPress script handle. Let it own the singleton API on its pages;
            // frontend.js reuses that instance for any newsletter form.
            $defer_hcaptcha_to_ws_form = $this->page_has_ws_form();
            if (!$defer_hcaptcha_to_ws_form) {
                if (!wp_script_is('hcaptcha-api', 'registered') && !wp_script_is('hcaptcha-api', 'enqueued')) {
                    wp_enqueue_script('hcaptcha-api', 'https://js.hcaptcha.com/1/api.js?render=explicit&onload=wpncNewsletterCaptchaReady', array(), null, true);
                } else {
                    wp_enqueue_script('hcaptcha-api');
                }
                wp_add_inline_script('hcaptcha-api', 'window.wpncNewsletterCaptchaReady=function(){window.wpncNewsletterHCaptchaLoaded=true;if(window.wpncNewsletterRenderHCaptchas){window.wpncNewsletterRenderHCaptchas();}};', 'before');
                $frontend_deps[] = 'hcaptcha-api';
            }
        } elseif ($captcha['provider'] === 'recaptcha') {
            wp_enqueue_script('recaptcha-api', 'https://www.google.com/recaptcha/api.js', array(), null, true);
            $frontend_deps[] = 'recaptcha-api';
        }

        wp_enqueue_style(
            'wp-newslatter-campaigns-frontend',
            plugins_url('assets/frontend.css', __FILE__),
            array(),
            self::VERSION
        );

        wp_enqueue_script(
            'wp-newslatter-campaigns-frontend',
            plugins_url('assets/frontend.js', __FILE__),
            $frontend_deps,
            self::VERSION,
            true
        );

        wp_localize_script('wp-newslatter-campaigns-frontend', 'wpncNewsletterFrontend', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'deferHCaptchaToWsForm' => $defer_hcaptcha_to_ws_form,
            'messages' => array(
                'success' => __("Thank you for subscribing! You're officially on the Garilla list.", 'wp-newslatter-campaigns'),
                'error' => __('Please try again.', 'wp-newslatter-campaigns'),
                'invalid' => __('Please enter a valid email address.', 'wp-newslatter-campaigns'),
                'notConnected' => __('Newsletter form is not connected. Please refresh the page and try again.', 'wp-newslatter-campaigns'),
                'captcha' => __('Please complete the captcha check.', 'wp-newslatter-campaigns'),
                'privacy' => __('Please accept the privacy consent.', 'wp-newslatter-campaigns'),
            ),
        ));
    }

    private function render_wpbb_captcha_fields() {
        $captcha = $this->wpbb_captcha_config();
        if (empty($captcha['provider']) || empty($captcha['site_key'])) return '';
        if ($captcha['provider'] === 'hcaptcha') {
            return '<div class="wp-newslatter-campaigns-captcha"><div data-wpnc-hcaptcha data-sitekey="' . esc_attr($captcha['site_key']) . '" data-size="invisible"></div></div><input type="hidden" name="wpbb_captcha_provider" value="hcaptcha"><input type="hidden" name="wpnc_hcaptcha_response" value="">';
        }
        return '<div class="wp-newslatter-campaigns-captcha"><div class="g-recaptcha" data-sitekey="' . esc_attr($captcha['site_key']) . '"></div></div><input type="hidden" name="wpbb_captcha_provider" value="recaptcha">';
    }

    private function verify_wpbb_captcha_request() {
        $provider = isset($_POST['wpbb_captcha_provider']) ? sanitize_key(wp_unslash($_POST['wpbb_captcha_provider'])) : '';
        if (!$provider) return true;
        $captcha = $this->wpbb_captcha_config();
        if ($provider !== ($captcha['provider'] ?? '') || empty($captcha['secret_key'])) return new WP_Error('wp_newslatter_campaigns_captcha_not_configured', __('Captcha is not configured. Please check WP BBuilder captcha settings.', 'wp-newslatter-campaigns'));
        if ($provider === 'hcaptcha') {
            $token = isset($_POST['wpnc_hcaptcha_response']) ? sanitize_text_field(wp_unslash($_POST['wpnc_hcaptcha_response'])) : '';
            if (!$token && isset($_POST['h-captcha-response'])) $token = sanitize_text_field(wp_unslash($_POST['h-captcha-response']));
            if (!$token) return new WP_Error('wp_newslatter_campaigns_hcaptcha_missing', __('Please complete the hCaptcha challenge.', 'wp-newslatter-campaigns'));
            $response = wp_remote_post('https://hcaptcha.com/siteverify', array('timeout'=>12, 'body'=>array('secret'=>$captcha['secret_key'], 'response'=>$token, 'remoteip'=>$_SERVER['REMOTE_ADDR'] ?? '')));
            if (is_wp_error($response)) return $response;
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            if (empty($body['success'])) return new WP_Error('wp_newslatter_campaigns_hcaptcha_failed', __('hCaptcha verification failed. Please try again.', 'wp-newslatter-campaigns'));
        }
        if ($provider === 'recaptcha') {
            $token = isset($_POST['g-recaptcha-response']) ? sanitize_text_field(wp_unslash($_POST['g-recaptcha-response'])) : '';
            if (!$token) return new WP_Error('wp_newslatter_campaigns_recaptcha_missing', __('Please complete the reCAPTCHA challenge.', 'wp-newslatter-campaigns'));
            $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', array('timeout'=>12, 'body'=>array('secret'=>$captcha['secret_key'], 'response'=>$token, 'remoteip'=>$_SERVER['REMOTE_ADDR'] ?? '')));
            if (is_wp_error($response)) return $response;
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            if (empty($body['success'])) return new WP_Error('wp_newslatter_campaigns_recaptcha_failed', __('reCAPTCHA verification failed. Please try again.', 'wp-newslatter-campaigns'));
        }
        return true;
    }

    public function shortcode_form($atts = array()) {
        $atts = shortcode_atts(array('title'=>'', 'button'=>'Subscribe', 'placeholder'=>'Enter your email', 'name'=>'yes', 'class'=>''), $atts);
        $form_id = wp_unique_id('wp-newslatter-campaigns-email-');
        $feedback = '';
        $feedback_state = '';
        if (isset($_GET['newsletter']) && sanitize_key(wp_unslash($_GET['newsletter'])) === 'subscribed') {
            $feedback = __("Thank you for subscribing! You're officially on the Garilla list.", 'wp-newslatter-campaigns');
            $feedback_state = 'success';
        } elseif (isset($_GET['newsletter'], $_GET['newsletter_message']) && sanitize_key(wp_unslash($_GET['newsletter'])) === 'error') {
            $feedback = sanitize_text_field(wp_unslash($_GET['newsletter_message']));
            $feedback_state = 'error';
        }
        ob_start();
        echo '<form class="wp-newslatter-campaigns-form ' . esc_attr($atts['class']) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-wp-newslatter-campaigns-ajax="1" novalidate>';
        echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_subscribe">';
        wp_nonce_field('wp_newslatter_campaigns_subscribe', '_wp_newslatter_campaigns_nonce');
        if ($atts['title']) echo '<h3>' . esc_html($atts['title']) . '</h3>';
        echo '<label class="screen-reader-text" for="' . esc_attr($form_id) . '">Email</label><input id="' . esc_attr($form_id) . '" class="wp-newslatter-campaigns-email" type="email" name="email" placeholder="' . esc_attr($atts['placeholder']) . '" required> ';
        if ($atts['name'] !== 'no') echo '<input type="text" name="name" placeholder="Name" autocomplete="name"> ';
        $s = $this->settings();
        if (!empty($s['privacy_checkbox'])) echo '<label class="wp-newslatter-campaigns-consent"><input type="checkbox" name="privacy_consent" value="1" required aria-required="true"><span>I agree to receive email updates and can unsubscribe at any time.</span></label> ';
        $public_lists = $this->configured_lists(true);
        if ($public_lists) {
            echo '<fieldset class="wp-newslatter-campaigns-lists"><legend>Choose your updates</legend>';
            foreach ($public_lists as $list_id => $list) {
                echo '<label><input type="checkbox" name="newsletter_lists[]" value="' . absint($list_id) . '"> <span>' . esc_html($list['name']) . '</span></label>';
            }
            echo '</fieldset>';
        }
        echo $this->render_wpbb_captcha_fields();
        echo '<button type="submit">' . esc_html($atts['button']) . '</button><div class="wp-newslatter-campaigns-message" aria-live="polite" role="status"' . ($feedback === '' ? ' hidden' : ' data-state="' . esc_attr($feedback_state) . '"') . '>' . esc_html($feedback) . '</div></form>';
        return ob_get_clean();
    }

    public function handle_subscribe() {
        $result = $this->process_subscribe_request();
        if (is_wp_error($result)) {
            $referer = wp_get_referer();
            if ($referer) {
                wp_safe_redirect(add_query_arg(array(
                    'newsletter' => 'error',
                    'newsletter_message' => $result->get_error_message(),
                ), $referer));
                exit;
            }
            wp_die(esc_html($result->get_error_message()));
        }
        wp_safe_redirect(wp_get_referer() ? add_query_arg('newsletter', 'subscribed', wp_get_referer()) : home_url('/'));
        exit;
    }

    public function handle_ajax_subscribe() {
        $result = $this->process_subscribe_request();
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 422);
        }
        $message = !empty($result['already_subscribed'])
            ? __("You're already subscribed. Thanks for being part of Garilla!", 'wp-newslatter-campaigns')
            : __("Thank you for subscribing! You're officially on the Garilla list.", 'wp-newslatter-campaigns');
        wp_send_json_success(array(
            'message' => $message,
            'welcome_email' => !empty($result['welcome_email_sent']) ? 'sent' : (!empty($result['welcome_email_attempted']) ? 'failed' : 'not-required'),
        ));
    }

    private function process_subscribe_request() {
        if (!isset($_POST['_wp_newslatter_campaigns_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wp_newslatter_campaigns_nonce'])), 'wp_newslatter_campaigns_subscribe')) return new WP_Error('wp_newslatter_campaigns_invalid_request', __('Invalid request.', 'wp-newslatter-campaigns'));
        $captcha = $this->verify_wpbb_captcha_request();
        if (is_wp_error($captcha)) return $captcha;
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        if (!$email || !is_email($email)) return new WP_Error('wp_newslatter_campaigns_invalid_email', __('Invalid email address.', 'wp-newslatter-campaigns'));
        if ($this->is_domain_blocked($email)) return new WP_Error('wp_newslatter_campaigns_blocked_domain', __('Email domain is not allowed.', 'wp-newslatter-campaigns'));
        $settings = $this->settings();
        if (!empty($settings['privacy_checkbox']) && empty($_POST['privacy_consent'])) return new WP_Error('wp_newslatter_campaigns_privacy_required', __('Please accept the privacy consent.', 'wp-newslatter-campaigns'));
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id,first_name,status,enabled,lists FROM {$this->table('subscribers')} WHERE email=%s",
            $email
        ));
        $already_subscribed = $existing && $existing->status === 'subscribed' && (int)$existing->enabled === 1;
        $status = $already_subscribed || empty($settings['double_optin']) ? 'subscribed' : 'unconfirmed';
        $subscriber_name = $name !== '' ? $name : ($existing ? (string)$existing->first_name : '');
        $requested_lists = $this->normalize_list_ids(wp_unslash($_POST['newsletter_lists'] ?? array()));
        $public_list_ids = array_map('intval', array_keys($this->configured_lists(true)));
        $requested_lists = array_values(array_intersect($requested_lists, $public_list_ids));
        $existing_lists = $existing ? $this->normalize_list_ids($existing->lists) : array();
        $subscriber_lists = array_values(array_unique(array_merge($existing_lists, $requested_lists, $this->forced_list_ids())));
        sort($subscriber_lists, SORT_NUMERIC);
        $subscriber_data = array('email'=>$email, 'first_name'=>$subscriber_name, 'status'=>$status, 'enabled'=>1, 'is_demo'=>0, 'source'=>'form', 'ip'=>$this->ip(), 'lists'=>$subscriber_lists, 'updated'=>current_time('mysql'));
        if (!$existing) $subscriber_data['created'] = current_time('mysql');
        $id = $this->upsert_subscriber($subscriber_data);
        if (!$id) return new WP_Error('wp_newslatter_campaigns_save_failed', __('Your subscription could not be saved. Please try again.', 'wp-newslatter-campaigns'));
        $this->fire_webhooks('subscribe', array('subscriber_id'=>$id, 'email'=>$email, 'name'=>$name));
        $welcome_email_attempted = false;
        $welcome_email_sent = false;
        if ($status === 'subscribed' && !$already_subscribed && !empty($settings['welcome_email_enabled'])) {
            $subscriber = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table('subscribers')} WHERE id=%d",
                $id
            ));
            if ($subscriber) {
                $welcome_email_attempted = true;
                $welcome_email_sent = $this->send_welcome_email($subscriber);
            }
        }
        return array(
            'subscriber_id' => $id,
            'already_subscribed' => (bool)$already_subscribed,
            'welcome_email_attempted' => $welcome_email_attempted,
            'welcome_email_sent' => $welcome_email_sent,
        );
    }

    /**
     * Send the one-time welcome message for a new frontend form subscription.
     * Campaign delivery and account/checkout opt-ins remain separate workflows.
     */
    private function send_welcome_email($subscriber) {
        if (!is_object($subscriber) || empty($subscriber->email) || !is_email($subscriber->email)) return false;
        $settings = $this->settings();
        $subject = trim((string)$settings['welcome_email_subject']);
        if ($subject === '') $subject = __('Thank you for subscribing to Garilla', 'wp-newslatter-campaigns');
        $subject = sanitize_text_field((string)apply_filters('wp_newslatter_campaigns_welcome_email_subject', $subject, $subscriber));
        $html = $this->welcome_email_html($subscriber);
        $ok = $this->send_html_mail($subscriber->email, $subject, $html, $subscriber);
        $this->record_delivery_log(array(
            'subscriber_id' => $subscriber->id,
            'recipient' => $subscriber->email,
            'delivery_type' => 'welcome',
            'status' => $ok ? 'accepted' : 'failed',
            'attempt' => 1,
            'response' => $ok ? $this->last_mail_response : ($this->last_mail_error ?: $this->last_mail_response),
        ));
        return (bool)$ok;
    }

    /**
     * Build a responsive, email-client-safe welcome template. Theme filters can
     * supply brand artwork without coupling the newsletter plugin to one theme.
     */
    private function welcome_email_html($subscriber) {
        $settings = $this->settings();
        $logo_url = '';
        $custom_logo_id = absint(get_theme_mod('custom_logo'));
        if ($custom_logo_id) {
            $logo = wp_get_attachment_image_src($custom_logo_id, 'full');
            if (is_array($logo) && !empty($logo[0])) $logo_url = (string)$logo[0];
        }
        $logo_url = (string)apply_filters('wp_newslatter_campaigns_welcome_logo_url', $logo_url, $subscriber);
        $hero_image_url = (string)apply_filters('wp_newslatter_campaigns_welcome_hero_image_url', '', $subscriber);
        $cta_url = (string)apply_filters('wp_newslatter_campaigns_welcome_cta_url', home_url('/prizes/'), $subscriber);
        $heading = trim((string)$settings['welcome_email_heading']);
        $message = trim((string)$settings['welcome_email_message']);
        $first_name = trim((string)($subscriber->first_name ?? ''));
        $greeting = $first_name !== ''
            ? sprintf(__('Hi %s,', 'wp-newslatter-campaigns'), $first_name)
            : __('Hi there,', 'wp-newslatter-campaigns');
        $site_name = trim((string)get_bloginfo('name')) ?: 'Garilla';
        $preheader = __('Thank you for joining Garilla. The latest prizes and giveaway news are coming your way.', 'wp-newslatter-campaigns');
        $footer_text = trim((string)$settings['footer_text']);
        $unsubscribe_url = $this->unsubscribe_url($subscriber);

        $logo_html = $logo_url !== ''
            ? '<img src="' . esc_url($logo_url) . '" width="245" alt="' . esc_attr($site_name) . '" style="display:block;width:100%;max-width:245px;height:auto;border:0;margin:0 auto;">'
            : '<div style="font-family:Arial,sans-serif;font-size:34px;line-height:1;font-weight:900;letter-spacing:-1px;color:#e5007e;text-align:center;">' . esc_html($site_name) . '</div>';
        $hero_image_html = $hero_image_url !== ''
            ? '<tr><td align="center" style="padding:0 28px 22px;"><img src="' . esc_url($hero_image_url) . '" width="190" alt="" style="display:block;width:100%;max-width:190px;height:auto;border:0;margin:0 auto;"></td></tr>'
            : '';

        $html = '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html($settings['welcome_email_subject']) . '</title>'
            . '<style>@media only screen and (max-width:620px){.wpnc-email-shell{width:100%!important}.wpnc-email-pad{padding-left:24px!important;padding-right:24px!important}.wpnc-email-title{font-size:34px!important;line-height:1.05!important}.wpnc-email-benefit{display:block!important;width:100%!important;padding:0 0 12px!important}}</style></head>'
            . '<body style="margin:0;padding:0;background:#f7eaf1;-webkit-text-size-adjust:100%;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . esc_html($preheader) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background:#f7eaf1;"><tr><td align="center" style="padding:30px 12px;">'
            . '<table role="presentation" class="wpnc-email-shell" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:28px;overflow:hidden;box-shadow:0 12px 34px rgba(91,15,60,.12);">'
            . '<tr><td align="center" style="padding:30px 28px 22px;background:#ffffff;">' . $logo_html . '</td></tr>'
            . '<tr><td class="wpnc-email-pad" align="center" style="padding:44px 46px 38px;background:#e5007e;color:#ffffff;">'
            . '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.5;font-weight:700;">' . esc_html($greeting) . '</div>'
            . '<h1 class="wpnc-email-title" style="margin:10px 0 14px;font-family:Arial,sans-serif;font-size:44px;line-height:1.05;letter-spacing:-1.2px;color:#ffffff;">' . esc_html($heading) . '</h1>'
            . '<p style="margin:0;font-family:Arial,sans-serif;font-size:18px;line-height:1.6;color:#ffffff;">' . nl2br(esc_html($message)) . '</p></td></tr>'
            . $hero_image_html
            . '<tr><td class="wpnc-email-pad" style="padding:34px 38px 18px;background:#ffffff;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td class="wpnc-email-benefit" width="33.33%" align="center" valign="top" style="padding:0 7px;"><div style="font-size:25px;line-height:1;">&#10024;</div><div style="margin-top:9px;font-family:Arial,sans-serif;font-size:14px;line-height:1.4;font-weight:700;color:#71104b;">Fresh prize launches</div></td>'
            . '<td class="wpnc-email-benefit" width="33.33%" align="center" valign="top" style="padding:0 7px;"><div style="font-size:25px;line-height:1;">&#127873;</div><div style="margin-top:9px;font-family:Arial,sans-serif;font-size:14px;line-height:1.4;font-weight:700;color:#71104b;">Giveaway updates</div></td>'
            . '<td class="wpnc-email-benefit" width="33.33%" align="center" valign="top" style="padding:0 7px;"><div style="font-size:25px;line-height:1;">&#127942;</div><div style="margin-top:9px;font-family:Arial,sans-serif;font-size:14px;line-height:1.4;font-weight:700;color:#71104b;">Winner news</div></td>'
            . '</tr></table></td></tr>'
            . '<tr><td align="center" style="padding:20px 28px 42px;background:#ffffff;"><a href="' . esc_url($cta_url) . '" style="display:inline-block;padding:16px 30px;border-radius:999px;background:#71104b;color:#ffffff;font-family:Arial,sans-serif;font-size:16px;line-height:1;font-weight:800;text-decoration:none;">See the latest prizes</a></td></tr>'
            . '<tr><td align="center" class="wpnc-email-pad" style="padding:24px 36px;background:#71104b;font-family:Arial,sans-serif;font-size:12px;line-height:1.6;color:#f9ddec;">'
            . ($footer_text !== '' ? esc_html($footer_text) . '<br>' : '')
            . '<a href="' . esc_url($unsubscribe_url) . '" style="color:#ffffff;text-decoration:underline;">Unsubscribe</a></td></tr>'
            . '</table></td></tr></table></body></html>';

        return (string)apply_filters('wp_newslatter_campaigns_welcome_email_html', $html, $subscriber, $settings);
    }

    public function upsert_subscriber($data) {
        global $wpdb;
        $table = $this->table('subscribers');
        $email = sanitize_email($data['email'] ?? '');
        if (!$email) return 0;
        $existing_row = $wpdb->get_row($wpdb->prepare("SELECT id,token,created,lists,is_demo FROM $table WHERE email=%s", $email));
        $existing = $existing_row ? (int)$existing_row->id : 0;
        $is_demo = array_key_exists('is_demo', $data) ? (!empty($data['is_demo']) ? 1 : 0) : ($existing_row ? (int)$existing_row->is_demo : 0);
        if (array_key_exists('lists', $data)) {
            $list_ids = $this->normalize_list_ids($data['lists']);
        } elseif ($existing_row) {
            $list_ids = $this->normalize_list_ids($existing_row->lists);
        } else {
            $list_ids = $is_demo ? array() : $this->forced_list_ids();
        }
        if (!$is_demo && !$existing && !array_key_exists('lists', $data)) $list_ids = array_values(array_unique(array_merge($list_ids, $this->forced_list_ids())));
        sort($list_ids, SORT_NUMERIC);
        $row = array(
            'source_id' => absint($data['source_id'] ?? 0),
            'email' => $email,
            'first_name' => sanitize_text_field($data['first_name'] ?? ''),
            'last_name' => sanitize_text_field($data['last_name'] ?? ''),
            'status' => $this->normalize_subscriber_status($data['status'] ?? 'subscribed'),
            'token' => sanitize_text_field($data['token'] ?? ($existing_row && $existing_row->token !== '' ? $existing_row->token : wp_generate_password(20, false))),
            'source' => sanitize_text_field($data['source'] ?? ''),
            'language' => sanitize_text_field($data['language'] ?? ''),
            'ip' => sanitize_text_field($data['ip'] ?? ''),
            'country' => sanitize_text_field($data['country'] ?? ''),
            'lists' => maybe_serialize($list_ids),
            'meta' => maybe_serialize($data['meta'] ?? array()),
            'wp_user_id' => absint($data['wp_user_id'] ?? 0),
            'created' => $data['created'] ?? ($existing_row && $existing_row->created !== '' ? $existing_row->created : current_time('mysql')),
            'updated' => $data['updated'] ?? current_time('mysql'),
        );
        if (array_key_exists('enabled', $data)) $row['enabled'] = !empty($data['enabled']) ? 1 : 0;
        if (array_key_exists('is_demo', $data)) $row['is_demo'] = $is_demo;
        if ($existing) {
            $wpdb->update($table, $row, array('id'=>$existing));
            $this->sync_subscriber_lists($existing, $list_ids);
            return $existing;
        }
        $wpdb->insert($table, $row);
        $subscriber_id = (int)$wpdb->insert_id;
        if ($subscriber_id) $this->sync_subscriber_lists($subscriber_id, $list_ids);
        return $subscriber_id;
    }

    private function normalize_subscriber_status($status) {
        $status = strtolower(trim((string)$status));
        $map = array('c'=>'subscribed','confirmed'=>'subscribed','active'=>'subscribed','s'=>'unconfirmed','pending'=>'unconfirmed','not-confirmed'=>'unconfirmed','u'=>'unsubscribed','b'=>'bounced','e'=>'bounced');
        if (isset($map[$status])) return $map[$status];
        return in_array($status, array('subscribed','unconfirmed','unsubscribed','bounced'), true) ? $status : 'subscribed';
    }

    private function db_columns($table) {
        global $wpdb;
        $cols = $wpdb->get_results("SHOW COLUMNS FROM $table");
        $out = array();
        foreach ((array)$cols as $c) $out[] = $c->Field;
        return $out;
    }

    private function prop($row, $keys, $default = '') {
        foreach ((array)$keys as $key) if (isset($row->$key)) return $row->$key;
        return $default;
    }

    public function run_migration_admin() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('wp_newslatter_campaigns_migrate');
        $msg = $this->run_migration();
        $this->redirect_admin('wp-newslatter-campaigns', 'Migration complete: ' . $msg);
    }

    public function run_migration() {
        global $wpdb;
        $this->create_tables();
        $state = array('started' => current_time('mysql'));

        $old_users = $wpdb->prefix . 'newsletter';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $old_users)) === $old_users) {
            $rows = $wpdb->get_results("SELECT * FROM $old_users ORDER BY id ASC");
            $count = 0;
            foreach ($rows as $r) {
                $this->upsert_subscriber(array(
                    'source_id' => $this->prop($r, 'id', 0),
                    'email' => $this->prop($r, 'email'),
                    'first_name' => $this->prop($r, array('name','first_name')),
                    'last_name' => $this->prop($r, array('surname','last_name')),
                    'status' => $this->prop($r, 'status', 'subscribed'),
                    'token' => $this->prop($r, 'token'),
                    'source' => 'newsletter-plugin',
                    'language' => $this->prop($r, 'language'),
                    'ip' => $this->prop($r, 'ip'),
                    'country' => $this->prop($r, 'country'),
                    'lists' => $this->prop($r, 'list', ''),
                    'meta' => maybe_unserialize($this->prop($r, 'profile', '')),
                    'wp_user_id' => $this->prop($r, 'wp_user_id', 0),
                    'created' => $this->prop($r, 'created', current_time('mysql')),
                    'updated' => current_time('mysql'),
                ));
                $count++;
            }
            $state['subscribers'] = $count;
        }

        $old_emails = $wpdb->prefix . 'newsletter_emails';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $old_emails)) === $old_emails) {
            $rows = $wpdb->get_results("SELECT * FROM $old_emails ORDER BY id ASC");
            $count = 0;
            foreach ($rows as $r) {
                $source_id = (int)$this->prop($r, 'id', 0);
                $existing = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table('campaigns')} WHERE source_id=%d", $source_id));
                $row = array(
                    'source_id'=>$source_id,
                    'title'=>$this->prop($r, array('title','subject')),
                    'subject'=>$this->prop($r, 'subject'),
                    'html'=>$this->prop($r, array('message','html')),
                    'text'=>$this->prop($r, array('message_text','text')),
                    'status'=>$this->prop($r, 'status', 'draft'),
                    'type'=>$this->prop($r, 'type', 'imported'),
                    'list_id'=>(string)$this->prop($r, 'list', ''),
                    'total'=>(int)$this->prop($r, 'total', 0),
                    'sent'=>(int)$this->prop($r, 'sent', 0),
                    'open_count'=>(int)$this->prop($r, 'open_count', 0),
                    'click_count'=>(int)$this->prop($r, 'click_count', 0),
                    'options'=>$this->prop($r, 'options', ''),
                    'created'=>$this->prop($r, 'created', current_time('mysql')),
                    'updated'=>current_time('mysql'),
                );
                if ($existing) $wpdb->update($this->table('campaigns'), $row, array('id'=>$existing)); else $wpdb->insert($this->table('campaigns'), $row);
                $count++;
            }
            $state['campaigns'] = $count;
        }

        $old_auto = $wpdb->prefix . 'newsletter_automated';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $old_auto)) === $old_auto) {
            $rows = $wpdb->get_results("SELECT * FROM $old_auto ORDER BY id ASC");
            $count = 0;
            foreach ($rows as $r) {
                $data = json_decode($this->prop($r, array('data','options'), ''), true);
                $name = is_array($data) && !empty($data['name']) ? $data['name'] : 'Imported automation ' . $this->prop($r, 'id', '');
                $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table('automations')} WHERE source_id=%d", (int)$this->prop($r, 'id', 0)));
                $row = array('source_id'=>(int)$this->prop($r,'id',0),'name'=>$name,'enabled'=>!empty($data['enabled'])?1:0,'frequency'=>$data['frequency'] ?? 'weekly','subject'=>$data['subject'] ?? $name,'intro'=>$data['intro'] ?? '','post_count'=>absint($data['post_count'] ?? 5),'last_run'=>is_numeric($this->prop($r,'last_run')) ? gmdate('Y-m-d H:i:s',(int)$this->prop($r,'last_run')) : null,'created'=>current_time('mysql'),'updated'=>current_time('mysql'));
                if ($exists) $wpdb->update($this->table('automations'), $row, array('id'=>$exists)); else $wpdb->insert($this->table('automations'), $row);
                $count++;
            }
            $state['automations'] = $count;
        }

        $old_stats = $wpdb->prefix . 'newsletter_stats';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $old_stats)) === $old_stats) {
            $rows = $wpdb->get_results("SELECT * FROM $old_stats ORDER BY id ASC LIMIT 50000");
            $count = 0;
            foreach ($rows as $r) {
                $wpdb->insert($this->table('events'), array('campaign_id'=>(int)$this->prop($r,array('email_id','campaign_id'),0),'subscriber_id'=>(int)$this->prop($r,array('user_id','subscriber_id'),0),'event_type'=>$this->prop($r,'url') ? 'click' : 'open','url'=>$this->prop($r,'url'),'ip'=>$this->prop($r,'ip'),'created'=>$this->prop($r,'created',current_time('mysql'))));
                $count++;
            }
            $state['events'] = $count;
        }

        foreach (array('newsletter_webhooks','newsletter_bounces') as $old) {
            $old_table = $wpdb->prefix . $old;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $old_table)) !== $old_table) continue;
            $rows = $wpdb->get_results("SELECT * FROM $old_table ORDER BY id ASC LIMIT 10000");
            if ($old === 'newsletter_webhooks') {
                foreach ($rows as $r) $wpdb->insert($this->table('webhooks'), array('source_id'=>(int)$this->prop($r,'id',0),'name'=>$this->prop($r,'name','Imported webhook'),'event'=>$this->prop($r,'event','subscribe'),'url'=>$this->prop($r,'url'),'enabled'=>(int)$this->prop($r,'enabled',1),'created'=>current_time('mysql')));
                $state['webhooks'] = count($rows);
            } else {
                foreach ($rows as $r) $wpdb->insert($this->table('bounces'), array('subscriber_id'=>(int)$this->prop($r,'user_id',0),'email'=>$this->prop($r,'email'),'message'=>$this->prop($r,'message'),'created'=>$this->prop($r,'created',current_time('mysql'))));
                $state['bounces'] = count($rows);
            }
        }

        $state['finished'] = current_time('mysql');
        update_option(self::MIGRATION_OPTION, $state, false);
        return implode(', ', array_map(function($k,$v){ return $k . '=' . (is_scalar($v) ? $v : wp_json_encode($v)); }, array_keys($state), $state));
    }

    public function upload_html_campaign() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('wp_newslatter_campaigns_upload_campaign');
        global $wpdb;
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $html = wp_unslash($_POST['html'] ?? '');
        if (empty($html) && !empty($_FILES['campaign_file']['tmp_name'])) $html = file_get_contents($_FILES['campaign_file']['tmp_name']);
        if (!$subject || trim((string)$html) === '') $this->redirect_admin('wp-newslatter-campaigns-upload', '', 'Subject and HTML are required.');
        $wpdb->insert($this->table('campaigns'), array('title'=>$subject,'subject'=>$subject,'html'=>$html,'status'=>'draft','type'=>'html-upload','created'=>current_time('mysql'),'updated'=>current_time('mysql')));
        $this->redirect_admin('wp-newslatter-campaigns-campaigns', 'Campaign created');
    }

    public function upload_zip_campaign() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('wp_newslatter_campaigns_upload_zip');
        global $wpdb;
        if (empty($_FILES['campaign_zip']['tmp_name']) || !is_uploaded_file($_FILES['campaign_zip']['tmp_name'])) $this->redirect_admin('wp-newslatter-campaigns-upload', '', 'Please upload a ZIP file.');
        if (!class_exists('ZipArchive')) $this->redirect_admin('wp-newslatter-campaigns-upload', '', 'PHP ZipArchive is not available on this server.');
        $file = $_FILES['campaign_zip'];
        $filename = isset($file['name']) ? sanitize_file_name(wp_unslash($file['name'])) : 'campaign.zip';
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') $this->redirect_admin('wp-newslatter-campaigns-upload', '', 'Only ZIP files are allowed.');
        $competition_input = isset($_POST['competition_name']) ? sanitize_text_field(wp_unslash($_POST['competition_name'])) : '';
        $competition = sanitize_title($competition_input ?: preg_replace('/\.zip$/i', '', $filename));
        if (!$competition) $competition = 'campaign';
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? '')) ?: ucwords(str_replace('-', ' ', $competition));

        $locations = $this->get_upload_locations();
        if (!empty($locations['error'])) $this->redirect_admin('wp-newslatter-campaigns-upload', '', $locations['error']);
        $campaign_dir = trailingslashit($locations['base_dir']) . $competition;
        $campaign_url = trailingslashit($locations['base_url']) . rawurlencode($competition);
        wp_mkdir_p($campaign_dir);
        $version = 'v' . (string)$this->next_version_number($campaign_dir);
        $version_dir = trailingslashit($campaign_dir) . $version;
        $version_url = trailingslashit($campaign_url) . $version;
        wp_mkdir_p($version_dir);

        $zip = new ZipArchive();
        $opened = $zip->open($file['tmp_name']);
        if ($opened !== true) { $this->delete_directory($version_dir); $this->redirect_admin('wp-newslatter-campaigns-upload', '', 'Could not open ZIP file.'); }
        $content_html = null; $images_count = 0; $ignored_count = 0; $copied_images = array();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (!$entry || $this->should_ignore_zip_entry($entry)) { $ignored_count++; continue; }
            $basename = sanitize_file_name(basename($entry));
            if (!$basename) { $ignored_count++; continue; }
            $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
            if (strtolower($basename) === 'content.html') {
                $data = $zip->getFromIndex($i);
                if ($data !== false) $content_html = $data;
                continue;
            }
            if (in_array($ext, $this->image_extensions, true)) {
                $data = $zip->getFromIndex($i);
                if ($data === false) { $ignored_count++; continue; }
                $target = trailingslashit($version_dir) . $basename;
                if (file_put_contents($target, $data) !== false) { $images_count++; $copied_images[strtolower($basename)] = true; }
                continue;
            }
            $ignored_count++;
        }
        $zip->close();
        if ($content_html === null) { $this->delete_directory($version_dir); $this->redirect_admin('wp-newslatter-campaigns-upload', '', 'No content.html file was found in the ZIP.'); }

        $started = microtime(true);
        $processed = $this->process_html($content_html, trailingslashit($version_url), $copied_images);
        $cleanup_duration = number_format(microtime(true) - $started, 3) . 's';
        $html_path = trailingslashit($version_dir) . 'content.html';
        file_put_contents($html_path, $processed['html']);
        $html_url = trailingslashit($version_url) . 'content.html';
        $last = array('competition'=>$competition,'version'=>$version,'base_url'=>trailingslashit($version_url),'html_url'=>$html_url,'html'=>$processed['html'],'images_count'=>$images_count,'ignored_count'=>$ignored_count,'imported_at'=>current_time('mysql'),'cleanup_duration'=>$cleanup_duration,'report'=>$processed['report'],'subject'=>$subject);
        update_option(self::UPLOAD_LAST_OPTION, $last, false);
        if (!empty($_POST['create_campaign'])) {
            $wpdb->insert($this->table('campaigns'), array('title'=>$subject,'subject'=>$subject,'html'=>$processed['html'],'status'=>'draft','type'=>'design-upload','options'=>maybe_serialize(array('upload_url'=>$html_url,'upload_folder'=>$competition,'upload_version'=>$version)),'created'=>current_time('mysql'),'updated'=>current_time('mysql')));
        }
        $this->redirect_admin('wp-newslatter-campaigns-upload', 'Campaign imported as ' . $version . '. ' . $images_count . ' image files copied. HTML cleaned in ' . $cleanup_duration . '.');
    }

    public function create_campaign_from_upload() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('wp_newslatter_campaigns_create_from_upload');
        global $wpdb;
        $last = get_option(self::UPLOAD_LAST_OPTION, array());
        if (!is_array($last) || empty($last['html'])) $this->redirect_admin('wp-newslatter-campaigns-upload', '', 'No processed upload is available.');
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? '')) ?: ($last['subject'] ?? $last['competition'] ?? 'Newsletter campaign');
        $wpdb->insert($this->table('campaigns'), array('title'=>$subject,'subject'=>$subject,'html'=>$last['html'],'status'=>'draft','type'=>'design-upload','options'=>maybe_serialize(array('upload_url'=>$last['html_url'] ?? '', 'upload_folder'=>$last['competition'] ?? '', 'upload_version'=>$last['version'] ?? '')),'created'=>current_time('mysql'),'updated'=>current_time('mysql')));
        $this->redirect_admin('wp-newslatter-campaigns-campaigns', 'Draft campaign created from latest upload.');
    }

    public function delete_uploaded_campaign() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('wp_newslatter_campaigns_delete_upload');
        $type = isset($_POST['delete_type']) ? sanitize_key(wp_unslash($_POST['delete_type'])) : '';
        $competition = isset($_POST['competition']) ? sanitize_title(wp_unslash($_POST['competition'])) : '';
        $version = isset($_POST['version']) ? sanitize_key(wp_unslash($_POST['version'])) : '';
        if (!$competition) $this->redirect_admin('wp-newslatter-campaigns-upload', '', 'No campaign selected for deletion.');
        $locations = $this->get_upload_locations();
        $campaign_dir = trailingslashit($locations['base_dir']) . $competition;
        if ($type === 'campaign') { $this->delete_directory($campaign_dir); $this->clear_last_if_deleted($competition, null); $this->redirect_admin('wp-newslatter-campaigns-upload', 'Campaign directory deleted.'); }
        if ($type === 'version' && preg_match('/^v\d+$/', $version)) { $this->delete_directory(trailingslashit($campaign_dir) . $version); $this->clear_last_if_deleted($competition, $version); $this->redirect_admin('wp-newslatter-campaigns-upload', 'Version directory deleted.'); }
        $this->redirect_admin('wp-newslatter-campaigns-upload', '', 'Invalid delete request.');
    }

    private function process_html($html, $base_url, $copied_images) {
        $report = array();
        $original = $html;
        $html = str_replace(array("\r\n", "\r"), "\n", $html);
        if ($html !== $original) $report[] = 'Normalised line endings.';
        $html = preg_replace('/^\xEF\xBB\xBF/', '', $html, 1, $bom_count);
        if ($bom_count) $report[] = 'Removed UTF-8 byte-order mark.';
        $before_duplicate = $html;
        $html = $this->remove_duplicate_meta_content_type($html);
        if ($html !== $before_duplicate) $report[] = 'Removed duplicate Content-Type meta tag, keeping the first one.';
        $before_outlook = $html;
        $html = $this->remove_outlook_hidden_white_background($html);
        if ($html !== $before_outlook) $report[] = 'Removed the Outlook hidden white page background override for dark mode.';
        $before_source_fix = $html;
        $html = $this->ensure_email_source_styles($html);
        if ($html !== $before_source_fix) $report[] = 'Added img:hover background reset at the top of the email source.';
        $rewrite = $this->rewrite_campaign_urls($html, $base_url, $copied_images);
        $html = $rewrite['html'];
        $report[] = sprintf('Rewrote %d image URL reference(s) to %s.', $rewrite['count'], $base_url);
        foreach ($rewrite['missing'] as $missing) $report[] = sprintf('Warning: %s was referenced in HTML but was not found as an imported image.', $missing);
        foreach ($this->validate_html($html) as $item) $report[] = $item;
        $beautified = $this->beautify_html($html, $report);
        return array('html' => $beautified, 'report' => $report);
    }

    private function rewrite_campaign_urls($html, $base_url, $copied_images) {
        $base_url = trailingslashit($base_url); $count = 0; $missing = array();
        $html = preg_replace_callback('/\b(src|background)\s*=\s*(["\'])(.*?)\2/i', function($matches) use ($base_url, $copied_images, &$count, &$missing) {
            $attr = $matches[1]; $quote = $matches[2]; $value = html_entity_decode($matches[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $new_value = $this->rewrite_asset_value($value, $base_url, $copied_images, $missing);
            if ($new_value !== $value) $count++;
            return $attr . '=' . $quote . esc_url_raw($new_value) . $quote;
        }, $html);
        $html = preg_replace_callback('/url\(\s*(["\']?)([^)"\']+)(["\']?)\s*\)/i', function($matches) use ($base_url, $copied_images, &$count, &$missing) {
            $quote = $matches[1] ?: $matches[3]; $value = trim($matches[2]);
            $new_value = $this->rewrite_asset_value($value, $base_url, $copied_images, $missing);
            if ($new_value !== $value) $count++;
            return $quote ? 'url(' . $quote . esc_url_raw($new_value) . $quote . ')' : 'url(' . esc_url_raw($new_value) . ')';
        }, $html);
        return array('html' => $html, 'count' => $count, 'missing' => array_values(array_unique($missing)));
    }

    private function rewrite_asset_value($value, $base_url, $copied_images, &$missing) {
        $trimmed = trim($value);
        if ($trimmed === '' || preg_match('/^(data:|mailto:|tel:|#)/i', $trimmed)) return $value;
        $path = parse_url($trimmed, PHP_URL_PATH);
        $basename = sanitize_file_name(basename((string)($path ?: $trimmed)));
        $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
        if (!$basename || !in_array($ext, $this->image_extensions, true)) return $value;
        if (!isset($copied_images[strtolower($basename)])) $missing[] = $basename;
        return trailingslashit($base_url) . rawurlencode($basename);
    }

    private function remove_duplicate_meta_content_type($html) {
        $seen = false;
        return preg_replace_callback('/<meta\b[^>]*http-equiv\s*=\s*["\']?Content-Type["\']?[^>]*>/i', function($matches) use (&$seen) { if (!$seen) { $seen = true; return $matches[0]; } return ''; }, $html);
    }

    private function remove_outlook_hidden_white_background($html) {
        return preg_replace('/<!--\[if !mso 15\]><!-->\s*<style\b[^>]*\bid\s*=\s*(["\'])Outlook hidden\1[^>]*>.*?<\/style>\s*<!--<!\[endif\]-->/is', '', $html);
    }

    private function ensure_email_source_styles($html) {
        if (preg_match('/img\s*:\s*hover\s*\{[^}]*background\s*:\s*none\s*!important/isu', $html)) return $html;
        $style = '<style type="text/css" id="wp-newslatter-campaigns-source-fixes">img:hover {background: none !important;}</style>' . "\n";
        if (preg_match('/<head\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            $position = $matches[0][1] + strlen($matches[0][0]);
            return substr($html, 0, $position) . "\n" . $style . substr($html, $position);
        }
        return $style . $html;
    }

    private function validate_html($html) {
        $report = array();
        if (!class_exists('DOMDocument')) { $report[] = 'HTML validation skipped because DOMDocument is not available on this server.'; return $report; }
        $previous = libxml_use_internal_errors(true); libxml_clear_errors();
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        $errors = libxml_get_errors(); libxml_clear_errors(); libxml_use_internal_errors($previous);
        if ($loaded && empty($errors)) { $report[] = 'HTML parser check passed with no major syntax warnings.'; return $report; }
        if (!$loaded) $report[] = 'Warning: HTML parser could not fully load the document for validation.';
        $max = 8; $shown = 0;
        foreach ($errors as $error) {
            if ($shown >= $max) { $report[] = sprintf('Additional parser warnings hidden: %d.', max(0, count($errors) - $max)); break; }
            $message = trim($error->message);
            if ($message !== '') { $report[] = sprintf('Parser warning near line %d: %s', (int)$error->line, $message); $shown++; }
        }
        return $report;
    }

    private function beautify_html($html, &$report) {
        if (function_exists('tidy_repair_string')) {
            $config = array('indent'=>true,'indent-spaces'=>4,'wrap'=>0,'output-html'=>true,'show-body-only'=>false,'clean'=>false,'drop-empty-elements'=>false,'hide-comments'=>false,'join-styles'=>false,'merge-divs'=>false,'merge-spans'=>false,'preserve-entities'=>true);
            $tidy = tidy_repair_string($html, $config, 'utf8');
            if (is_string($tidy) && trim($tidy) !== '') { $report[] = 'Beautified source using PHP Tidy.'; return $tidy; }
        }
        $report[] = 'PHP Tidy is not available, so a conservative email-safe formatter was used.';
        return $this->safe_pretty_print_html($html);
    }

    private function safe_pretty_print_html($html) {
        $html = preg_replace('/>\s+</', ">\n<", trim($html));
        $lines = explode("\n", (string)$html); $indent = 0; $out = array();
        $void = array('area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr');
        foreach ($lines as $line) {
            $line = trim($line); if ($line === '') continue;
            if (preg_match('/^<\/(?!html|body)/', $line)) $indent = max(0, $indent - 1);
            $out[] = str_repeat("\t", $indent) . $line;
            if (preg_match('/^<([a-z0-9:-]+)\b/i', $line, $m)) {
                $tag = strtolower($m[1]);
                $is_closing_same_line = preg_match('/<\/\s*' . preg_quote($tag, '/') . '\s*>\s*$/i', $line);
                $is_self_closing = preg_match('/\/\s*>$/', $line) || in_array($tag, $void, true) || strpos($line, '<!') === 0 || strpos($line, '<?') === 0;
                if (!$is_closing_same_line && !$is_self_closing && !preg_match('/^<(html|body)\b/i', $line)) $indent++;
            }
        }
        return implode("\n", $out) . "\n";
    }

    private function get_upload_locations() {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) return array('error'=>$uploads['error'], 'base_dir'=>'', 'base_url'=>'');
        return array('error'=>'', 'base_dir'=>trailingslashit($uploads['basedir']) . self::UPLOAD_BASE_FOLDER, 'base_url'=>trailingslashit($uploads['baseurl']) . self::UPLOAD_BASE_FOLDER);
    }

    private function get_upload_campaign_tree() {
        $locations = $this->get_upload_locations(); $base_dir = $locations['base_dir']; $base_url = $locations['base_url'];
        if (!$base_dir || !is_dir($base_dir)) return array();
        $campaigns = array(); $entries = scandir($base_dir);
        if (!is_array($entries)) return array();
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $campaign_dir = trailingslashit($base_dir) . $entry;
            if (!is_dir($campaign_dir)) continue;
            $versions = array(); $version_entries = scandir($campaign_dir);
            if (is_array($version_entries)) {
                foreach ($version_entries as $version) {
                    if (!preg_match('/^v\d+$/', $version)) continue;
                    $version_dir = trailingslashit($campaign_dir) . $version; if (!is_dir($version_dir)) continue;
                    $version_url = trailingslashit(trailingslashit($base_url) . rawurlencode($entry)) . $version;
                    $html_path = trailingslashit($version_dir) . 'content.html';
                    $versions[] = array('version'=>$version,'url'=>trailingslashit($version_url),'html_url'=>file_exists($html_path) ? trailingslashit($version_url) . 'content.html' : '','size'=>$this->directory_size($version_dir),'modified'=>date_i18n(get_option('date_format') . ' ' . get_option('time_format'), filemtime($version_dir) ?: time()));
                }
            }
            usort($versions, function($a,$b){ return (int)substr($b['version'],1) <=> (int)substr($a['version'],1); });
            $campaigns[] = array('name'=>$entry, 'url'=>trailingslashit(trailingslashit($base_url) . rawurlencode($entry)), 'versions'=>$versions);
        }
        usort($campaigns, function($a,$b){ return strnatcasecmp($a['name'], $b['name']); });
        return $campaigns;
    }

    private function should_ignore_zip_entry($entry) {
        $entry = str_replace('\\', '/', $entry);
        if (substr($entry, -1) === '/') return true;
        if (strpos($entry, '../') !== false || strpos($entry, '/..') !== false) return true;
        foreach (explode('/', $entry) as $part) if ($part === '' || $part === '__MACOSX' || $part === '.DS_Store' || strpos($part, '._') === 0) return true;
        return false;
    }

    private function next_version_number($campaign_dir) {
        if (!is_dir($campaign_dir)) return 1;
        $max = 0; $entries = scandir($campaign_dir); if (!is_array($entries)) return 1;
        foreach ($entries as $entry) if (preg_match('/^v(\d+)$/', $entry, $m)) $max = max($max, (int)$m[1]);
        return $max + 1;
    }

    private function directory_size($dir) {
        if (!is_dir($dir)) return 0;
        $size = 0; $items = scandir($dir); if (!is_array($items)) return 0;
        foreach ($items as $item) { if ($item === '.' || $item === '..') continue; $path = trailingslashit($dir) . $item; if (is_dir($path)) $size += $this->directory_size($path); elseif (is_file($path)) $size += filesize($path) ?: 0; }
        return $size;
    }

    private function delete_directory($dir) {
        $locations = $this->get_upload_locations(); $base = realpath($locations['base_dir']); $target = realpath($dir);
        if (!$base || !$target || strpos($target, $base) !== 0 || !is_dir($target)) return;
        $items = scandir($target); if (!is_array($items)) return;
        foreach ($items as $item) { if ($item === '.' || $item === '..') continue; $path = trailingslashit($target) . $item; if (is_dir($path)) $this->delete_directory($path); else @unlink($path); }
        @rmdir($target);
    }

    private function clear_last_if_deleted($competition, $version = null) {
        $last = get_option(self::UPLOAD_LAST_OPTION, array());
        if (!is_array($last) || empty($last['competition'])) return;
        if ($last['competition'] === $competition && ($version === null || ($last['version'] ?? '') === $version)) delete_option(self::UPLOAD_LAST_OPTION);
    }

    public function add_subscriber_admin() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('wp_newslatter_campaigns_add_subscriber');
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!$email || !is_email($email)) $this->redirect_admin('wp-newslatter-campaigns-subscribers', '', 'Enter a valid email address.');
        $id = $this->upsert_subscriber(array('email'=>$email,'first_name'=>sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),'last_name'=>sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')),'status'=>sanitize_key(wp_unslash($_POST['status'] ?? 'subscribed')),'enabled'=>!empty($_POST['enabled'])?1:0,'is_demo'=>0,'source'=>'manual-admin','created'=>current_time('mysql'),'updated'=>current_time('mysql')));
        $this->redirect_admin('wp-newslatter-campaigns-subscribers', $id ? 'Subscriber saved.' : '', $id ? '' : 'Subscriber could not be saved.');
    }

    public function add_demo_subscribers_admin() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('wp_newslatter_campaigns_add_demo_subscribers');
        $raw = wp_unslash($_POST['demo_emails'] ?? '');
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $raw, $matches);
        $emails = array_values(array_unique(array_map('strtolower', $matches[0] ?? array())));
        $count = 0;
        foreach ($emails as $email) {
            if (!is_email($email)) continue;
            $this->upsert_subscriber(array('email'=>$email,'status'=>'subscribed','enabled'=>!empty($_POST['enabled'])?1:0,'is_demo'=>1,'source'=>'demo-admin','created'=>current_time('mysql'),'updated'=>current_time('mysql')));
            $count++;
        }
        $this->redirect_admin('wp-newslatter-campaigns-demo-subscribers', 'Saved ' . $count . ' demo subscriber(s).');
    }

    public function toggle_subscriber_admin() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $id = absint($_GET['id'] ?? 0);
        check_admin_referer('wp_newslatter_campaigns_toggle_subscriber_' . $id);
        $enabled = !empty($_GET['enabled']) ? 1 : 0;
        $return_page = sanitize_key(wp_unslash($_GET['return_page'] ?? 'wp-newslatter-campaigns-subscribers'));
        if (!in_array($return_page, array('wp-newslatter-campaigns-subscribers','wp-newslatter-campaigns-demo-subscribers'), true)) $return_page = 'wp-newslatter-campaigns-subscribers';
        global $wpdb;
        $wpdb->update($this->table('subscribers'), array('enabled'=>$enabled,'updated'=>current_time('mysql')), array('id'=>$id));
        $this->redirect_admin($return_page, $enabled ? 'Subscriber activated.' : 'Subscriber disabled.');
    }

    public function bulk_subscriber_status_admin() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $scope = sanitize_key(wp_unslash($_POST['scope'] ?? 'regular'));
        if (!in_array($scope, array('regular','demo'), true)) $scope = 'regular';
        check_admin_referer('wp_newslatter_campaigns_bulk_subscriber_status_' . $scope);
        $enabled = !empty($_POST['enabled']) ? 1 : 0;
        global $wpdb;
        $is_demo = $scope === 'demo' ? 1 : 0;
        $wpdb->update($this->table('subscribers'), array('enabled'=>$enabled,'updated'=>current_time('mysql')), array('is_demo'=>$is_demo));
        $page = $is_demo ? 'wp-newslatter-campaigns-demo-subscribers' : 'wp-newslatter-campaigns-subscribers';
        $this->redirect_admin($page, ($enabled ? 'Activated' : 'Disabled') . ' all ' . ($is_demo ? 'demo subscribers.' : 'subscribers.'));
    }

    public function import_csv() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('wp_newslatter_campaigns_import_csv');
        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) $this->redirect_admin('wp-newslatter-campaigns-import', '', 'Choose a subscriber CSV file.');
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) $this->redirect_admin('wp-newslatter-campaigns-import', '', 'The CSV file could not be opened.');
        $sample = (string)fgets($fh);
        $delimiter = substr_count($sample, ';') > substr_count($sample, ',') ? ';' : ',';
        rewind($fh);
        $header = fgetcsv($fh, 0, $delimiter);
        if (!$header) { fclose($fh); $this->redirect_admin('wp-newslatter-campaigns-import', '', 'The CSV header is missing.'); }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
        $normalised = array_map(function($value){ return strtolower(trim((string)$value)); }, $header);
        $map = array_flip($normalised); $count = 0;
        while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
            $get = function($names, $default = '') use ($row, $map) {
                foreach ((array)$names as $name) if (isset($map[$name]) && isset($row[$map[$name]])) return $row[$map[$name]];
                return $default;
            };
            $email = sanitize_email($get('email'));
            if (!$email || !is_email($email)) continue;
            $lists = array();
            foreach ($normalised as $index=>$name) if (preg_match('/^list\s+(\d+)$/', $name, $m) && !empty($row[$index]) && (string)$row[$index] !== '0') $lists[] = (int)$m[1];
            $this->upsert_subscriber(array('source_id'=>absint($get(array('id','source_id'),0)),'email'=>$email,'first_name'=>$get(array('first_name','name')),'last_name'=>$get(array('last_name','surname')),'status'=>$get('status','subscribed'),'token'=>$get('token'),'source'=>$get(array('source','referrer'),'csv'),'language'=>$get('language'),'ip'=>$get('ip'),'country'=>$get('country'),'lists'=>$lists,'wp_user_id'=>absint($get(array('wp user id','wp_user_id'),0)),'enabled'=>(int)$get('enabled',1) !== 0 ? 1 : 0,'is_demo'=>(int)$get(array('is_demo','demo'),0) !== 0 ? 1 : 0,'created'=>$get(array('created','date'),current_time('mysql')),'updated'=>$get(array('updated','last activity'),current_time('mysql'))));
            $count++;
        }
        fclose($fh);
        $this->redirect_admin('wp-newslatter-campaigns-import', 'Imported ' . $count . ' subscribers');
    }

    public function export_csv() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        global $wpdb;
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=wp-newslatter-campaigns-subscribers-' . gmdate('Ymd-His') . '.csv');
        $out = fopen('php://output', 'w');
        $header = array('email','first_name','last_name','status','enabled','is_demo','source','token','language','ip','country','wp_user_id','created','updated');
        for ($i = 1; $i <= self::LIST_MAX; $i++) $header[] = 'List ' . $i;
        fputcsv($out, $header);
        $rows = $wpdb->get_results("SELECT email,first_name,last_name,status,enabled,is_demo,source,token,language,ip,country,wp_user_id,created,updated,lists FROM {$this->table('subscribers')} ORDER BY is_demo ASC,email ASC", ARRAY_A);
        foreach ($rows as $row) {
            $ids = array_flip($this->normalize_list_ids($row['lists'] ?? array()));
            unset($row['lists']);
            for ($i = 1; $i <= self::LIST_MAX; $i++) $row[] = isset($ids[$i]) ? 1 : 0;
            fputcsv($out, $row);
        }
        fclose($out); exit;
    }

    public function export_campaigns() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('wp_newslatter_campaigns_export_campaigns');
        global $wpdb;
        $rows = $wpdb->get_results("SELECT source_id,title,subject,html,text,status,type,list_id,total,sent,open_count,click_count,options,scheduled_at,created,updated FROM {$this->table('campaigns')} ORDER BY id ASC", ARRAY_A);
        $payload = array('format'=>'wp-newslatter-campaigns-campaigns','version'=>self::VERSION,'exported_at'=>gmdate('c'),'campaigns'=>$rows);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=wp-newslatter-campaigns-campaigns-' . gmdate('Ymd-His') . '.json');
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function import_campaigns() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('wp_newslatter_campaigns_import_campaigns');
        if (empty($_FILES['campaigns_json']['tmp_name']) || !is_uploaded_file($_FILES['campaigns_json']['tmp_name'])) $this->redirect_admin('wp-newslatter-campaigns-import', '', 'Choose a campaign JSON file.');
        if (!empty($_FILES['campaigns_json']['size']) && (int)$_FILES['campaigns_json']['size'] > 25 * MB_IN_BYTES) $this->redirect_admin('wp-newslatter-campaigns-import', '', 'Campaign import file is too large.');
        $decoded = json_decode((string)file_get_contents($_FILES['campaigns_json']['tmp_name']), true);
        $campaigns = isset($decoded['campaigns']) && is_array($decoded['campaigns']) ? $decoded['campaigns'] : (is_array($decoded) ? $decoded : array());
        if (!$campaigns) $this->redirect_admin('wp-newslatter-campaigns-import', '', 'No campaigns were found in the JSON file.');
        global $wpdb;
        $count = 0; $updated = 0;
        $allowed_status = array('draft','new','sending','sent','paused','error');
        foreach ($campaigns as $campaign) {
            if (!is_array($campaign)) continue;
            $subject = sanitize_text_field($campaign['subject'] ?? '');
            $html = (string)($campaign['html'] ?? '');
            if ($subject === '' && trim($html) === '') continue;
            $source_id = absint($campaign['source_id'] ?? 0);
            $created = sanitize_text_field($campaign['created'] ?? current_time('mysql'));
            $existing = 0;
            if ($source_id) $existing = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table('campaigns')} WHERE source_id=%d LIMIT 1", $source_id));
            if (!$existing) $existing = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table('campaigns')} WHERE subject=%s AND created=%s LIMIT 1", $subject, $created));
            $status = sanitize_key($campaign['status'] ?? 'draft');
            if (!in_array($status, $allowed_status, true)) $status = 'draft';
            $row = array('source_id'=>$source_id,'title'=>sanitize_text_field($campaign['title'] ?? $subject),'subject'=>$subject,'html'=>$html,'text'=>(string)($campaign['text'] ?? ''),'status'=>$status,'type'=>sanitize_text_field($campaign['type'] ?? 'imported'),'list_id'=>sanitize_text_field($campaign['list_id'] ?? ''),'total'=>absint($campaign['total'] ?? 0),'sent'=>absint($campaign['sent'] ?? 0),'open_count'=>absint($campaign['open_count'] ?? 0),'click_count'=>absint($campaign['click_count'] ?? 0),'options'=>(string)($campaign['options'] ?? ''),'scheduled_at'=>!empty($campaign['scheduled_at'])?sanitize_text_field($campaign['scheduled_at']):null,'created'=>$created,'updated'=>sanitize_text_field($campaign['updated'] ?? current_time('mysql')));
            if ($existing) { $wpdb->update($this->table('campaigns'), $row, array('id'=>$existing)); $updated++; }
            else $wpdb->insert($this->table('campaigns'), $row);
            $count++;
        }
        $this->redirect_admin('wp-newslatter-campaigns-import', 'Imported ' . $count . ' campaign(s); updated ' . $updated . '.');
    }

    public function save_campaign() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $id = absint($_POST['id'] ?? 0); check_admin_referer('wp_newslatter_campaigns_save_campaign_' . $id); global $wpdb;
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? $subject));
        $html = wp_unslash($_POST['html'] ?? '');
        $list_id = absint($_POST['list_id'] ?? 0);
        if ($list_id && !isset($this->configured_lists(false)[$list_id])) $list_id = 0;
        if (!$id || !$subject || trim($html) === '') $this->redirect_admin('wp-newslatter-campaigns-campaigns', '', 'Subject and HTML are required.');
        $wpdb->update($this->table('campaigns'), array('title'=>$title,'subject'=>$subject,'html'=>$html,'list_id'=>$list_id ? (string)$list_id : '','updated'=>current_time('mysql')), array('id'=>$id));
        $this->redirect_admin('wp-newslatter-campaigns-campaigns&edit=' . $id, 'Campaign saved.');
    }

    public function test_campaign() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $id = absint($_POST['id'] ?? 0); check_admin_referer('wp_newslatter_campaigns_test_campaign_' . $id); global $wpdb;
        $email = sanitize_email(wp_unslash($_POST['test_email'] ?? ''));
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('campaigns')} WHERE id=%d", $id));
        if (!$campaign || !$email || !is_email($email)) $this->redirect_admin('wp-newslatter-campaigns-campaigns&edit=' . $id, '', 'Valid campaign and test email are required.');
        $sub = (object)array('id'=>0,'email'=>$email,'first_name'=>'Client','last_name'=>'Tester','token'=>'');
        $html = $this->prepare_test_email_html($campaign, $sub, 'Proof email');
        $ok = $this->send_html_mail($email, '[TEST] ' . $campaign->subject, $html);
        $this->record_test_log($email, $ok ? 'accepted' : 'failed', $ok ? 'WordPress accepted the proof email for the active mail queue.' : 'wp_mail reported failure. Check the site mail plugin logs.', $id);
        $this->record_delivery_log(array('campaign_id'=>$id,'recipient'=>$email,'delivery_type'=>'proof','status'=>$ok?'accepted':'failed','attempt'=>1,'response'=>$ok?$this->last_mail_response:($this->last_mail_error ?: $this->last_mail_response)));
        $success = 'Test email accepted by WordPress. Check the active site mail queue for delivery status.';
        $this->redirect_admin('wp-newslatter-campaigns-campaigns&edit=' . $id, $ok ? $success : '', $ok ? '' : 'Test email failed.');
    }

    public function demo_campaign() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $id = absint($_POST['id'] ?? 0); check_admin_referer('wp_newslatter_campaigns_demo_campaign_' . $id);
        $transport_check = $this->external_delivery_preflight();
        if (is_wp_error($transport_check)) $this->redirect_admin('wp-newslatter-campaigns-campaigns&edit=' . $id, '', $transport_check->get_error_message());
        global $wpdb;
        $email = sanitize_email(wp_unslash($_POST['demo_email'] ?? ''));
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('campaigns')} WHERE id=%d", $id));
        if (!$campaign || !$email || !is_email($email)) $this->redirect_admin('wp-newslatter-campaigns-campaigns&edit=' . $id, '', 'Valid campaign and demo email are required.');
        $html = $this->business_demo_wrapper($campaign);
        $ok = $this->send_html_mail($email, '[DEMO] ' . $campaign->subject, $html);
        $this->record_test_log($email, $ok ? 'demo accepted' : 'demo failed', $ok ? 'Demo email accepted by the WordPress mail system.' : 'Demo send failed. Review the active site mail plugin logs.', $id);
        $this->record_delivery_log(array('campaign_id'=>$id,'recipient'=>$email,'delivery_type'=>'demo','status'=>$ok?'accepted':'failed','attempt'=>1,'response'=>$ok?$this->last_mail_response:($this->last_mail_error ?: $this->last_mail_response)));
        $success = 'Demo email accepted by WordPress. Check the active site mail queue for delivery status.';
        $this->redirect_admin('wp-newslatter-campaigns-campaigns&edit=' . $id, $ok ? $success : '', $ok ? '' : 'Demo delivery failed.');
    }

    public function send_demo_subscribers() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $id = absint($_POST['id'] ?? 0);
        $return_to = sanitize_key(wp_unslash($_POST['return_to'] ?? 'edit'));
        $return_page = $return_to === 'list' ? 'wp-newslatter-campaigns-campaigns' : 'wp-newslatter-campaigns-campaigns&edit=' . $id;
        check_admin_referer('wp_newslatter_campaigns_send_demo_subscribers_' . $id);
        $transport_check = $this->external_delivery_preflight();
        if (is_wp_error($transport_check)) $this->redirect_admin($return_page, '', $transport_check->get_error_message());
        global $wpdb;
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('campaigns')} WHERE id=%d", $id));
        if (!$campaign) $this->redirect_admin('wp-newslatter-campaigns-campaigns', '', 'Campaign not found.');

        // Demo delivery is deliberately immediate. Demo lists are small, and an
        // immediate one-by-one pass avoids low-traffic WP-Cron sites leaving part
        // of a test run waiting in the background queue. Live campaigns continue
        // to use the throttled background queue.
        $subs = $wpdb->get_results("SELECT * FROM {$this->table('subscribers')} WHERE is_demo=1 AND enabled=1 ORDER BY id ASC");
        if (!$subs) $this->redirect_admin($return_page, '', 'No active demo subscribers are available. Add or activate a Demo Subscriber first.');

        $run_id = wp_generate_uuid4();
        $attempted = 0;
        $accepted = 0;
        $failed = 0;
        $invalid = 0;
        $total = count((array)$subs);

        foreach ((array)$subs as $index => $subscriber) {
            if (!is_email($subscriber->email)) {
                $invalid++;
                $failed++;
                $this->record_test_log($subscriber->email, 'demo failed', 'Invalid demo subscriber email address. Run: ' . $run_id, $id);
                $this->record_delivery_log(array('campaign_id'=>$id,'subscriber_id'=>$subscriber->id,'run_id'=>$run_id,'recipient'=>$subscriber->email,'delivery_type'=>'demo','status'=>'failed','attempt'=>0,'delivery_id'=>'','message_id'=>'','response'=>'Invalid demo subscriber email address.'));
                continue;
            }

            $attempted++;
            $this->record_delivery_log(array('campaign_id'=>$id,'subscriber_id'=>$subscriber->id,'run_id'=>$run_id,'recipient'=>$subscriber->email,'delivery_type'=>'demo','status'=>'processing','attempt'=>1,'delivery_id'=>'','message_id'=>'','response'=>'Starting individual demo send.'));
            $html = $this->prepare_test_email_html($campaign, $subscriber, 'Demo subscriber campaign');
            $ok = $this->send_html_mail($subscriber->email, '[DEMO] ' . $campaign->subject, $html);
            if ($ok) {
                $accepted++;
                $status = 'demo accepted';
                $detail = 'Mail transport accepted this demo message.';
            } else {
                $failed++;
                $status = 'demo failed';
                $detail = $this->last_mail_error !== '' ? $this->last_mail_error : 'wp_mail reported failure for this demo subscriber.';
            }
            $this->record_test_log($subscriber->email, $status, $detail . ' Run: ' . $run_id, $id);
            $this->record_delivery_log(array('campaign_id'=>$id,'subscriber_id'=>$subscriber->id,'run_id'=>$run_id,'recipient'=>$subscriber->email,'delivery_type'=>'demo','status'=>$ok?'accepted':'failed','attempt'=>1,'response'=>$ok?$this->last_mail_response:($this->last_mail_error ?: $detail)));

            // A small gap prevents local SMTP servers from receiving a burst of
            // several new connections in the same instant. It is intentionally
            // limited to demo delivery; live delivery remains background-throttled.
            if ((int)$index < $total - 1) usleep(1000000);
        }

        $notice = 'Demo send completed: ' . $accepted . ' of ' . $attempted . ' valid recipient(s) accepted by WordPress. Check the active site mail queue for final delivery status.';
        if ($invalid) $notice .= ' ' . $invalid . ' invalid address(es) were skipped.';
        $error = $failed ? $failed . ' demo message(s) failed. Review the latest test status and site mail plugin logs.' : '';
        $this->redirect_admin($return_page, $notice, $error);
    }

    private function schedule_demo_recipient($campaign_id, $subscriber_id, $run_id, $delay = 1) {
        $campaign_id = absint($campaign_id);
        $subscriber_id = absint($subscriber_id);
        $run_id = sanitize_text_field((string)$run_id);
        if (!$campaign_id || !$subscriber_id || $run_id === '') return false;
        $args = array($campaign_id, $subscriber_id, $run_id);
        $timestamp = time() + max(1, absint($delay));
        if ($this->action_scheduler_available()) {
            // Unique is deliberately false: a later click must create a fresh run
            // for the same campaign/subscriber pair.
            return (bool)as_schedule_single_action($timestamp, 'wp_newslatter_campaigns_send_demo_recipient', $args, 'wp-newslatter-campaigns-demo', false);
        }
        return (bool)wp_schedule_single_event($timestamp, 'wp_newslatter_campaigns_send_demo_recipient', $args, true);
    }

    public function process_demo_recipient($campaign_id = 0, $subscriber_id = 0, $run_id = '') {
        global $wpdb;
        $campaign_id = absint($campaign_id);
        $subscriber_id = absint($subscriber_id);
        $run_id = sanitize_text_field((string)$run_id);
        if (!$campaign_id || !$subscriber_id) return;

        $transport_check = $this->external_delivery_preflight();
        if (is_wp_error($transport_check)) {
            $error = $transport_check->get_error_message();
            $this->record_test_log('subscriber #' . $subscriber_id, 'demo failed', $error . ' Run: ' . $run_id, $campaign_id);
            $this->record_delivery_log(array('campaign_id'=>$campaign_id,'subscriber_id'=>$subscriber_id,'run_id'=>$run_id,'delivery_type'=>'demo','status'=>'failed','attempt'=>0,'response'=>$error));
            return;
        }

        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('campaigns')} WHERE id=%d", $campaign_id));
        $subscriber = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('subscribers')} WHERE id=%d AND is_demo=1 AND enabled=1", $subscriber_id));
        if (!$campaign || !$subscriber || !is_email($subscriber->email)) {
            $email = $subscriber && !empty($subscriber->email) ? $subscriber->email : 'subscriber #' . $subscriber_id;
            $this->record_test_log($email, 'demo skipped', 'Demo recipient is missing, disabled, or invalid for run ' . $run_id . '.', $campaign_id);
            $this->record_delivery_log(array('campaign_id'=>$campaign_id,'subscriber_id'=>$subscriber_id,'run_id'=>$run_id,'recipient'=>is_email($email)?$email:'','delivery_type'=>'demo','status'=>'skipped','delivery_id'=>'','message_id'=>'','response'=>'Demo recipient is missing, disabled, or invalid.'));
            return;
        }

        $html = $this->prepare_test_email_html($campaign, $subscriber, 'Demo subscriber campaign');
        $ok = $this->send_html_mail($subscriber->email, '[DEMO] ' . $campaign->subject, $html);
        $this->record_test_log(
            $subscriber->email,
            $ok ? 'demo accepted' : 'demo failed',
            ($ok ? 'Campaign accepted for demo delivery.' : 'wp_mail reported failure for this demo subscriber.') . ' Run: ' . $run_id,
            $campaign_id
        );
        $this->record_delivery_log(array('campaign_id'=>$campaign_id,'subscriber_id'=>$subscriber_id,'run_id'=>$run_id,'recipient'=>$subscriber->email,'delivery_type'=>'demo','status'=>$ok?'accepted':'failed','attempt'=>1,'response'=>$ok?$this->last_mail_response:($this->last_mail_error ?: $this->last_mail_response)));
    }

    private function prepare_test_email_html($campaign, $sub, $label = 'Proof email') {
        $html = $this->personalize((string)$campaign->html, $sub, $campaign);
        $html = $this->remove_test_email_side_gutters($html);
        return '<div style="font-family:Arial,sans-serif;background:#f4f7fb;padding:18px"><div style="width:100%;max-width:700px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden"><div style="padding:14px 18px;background:#8d0752;color:#fff;font-weight:700">WP Newsletter Campaigns ' . esc_html($label) . '</div><div style="padding:0;margin:0">' . $html . '</div><div style="padding:14px 18px;color:#64748b;font-size:12px">This is a test only. Check desktop, mobile, links, images, spelling and inbox placement before queueing the real campaign.</div></div></div>';
    }

    private function insert_before_email_end($html, $fragment) {
        $html = (string)$html;
        $fragment = (string)$fragment;
        if ($fragment === '') return $html;
        if (preg_match('/<\/body\s*>/i', $html)) {
            return preg_replace('/<\/body\s*>/i', $fragment . '</body>', $html, 1);
        }
        if (preg_match('/<\/html\s*>/i', $html)) {
            return preg_replace('/<\/html\s*>/i', $fragment . '</html>', $html, 1);
        }
        return $html . $fragment;
    }

    private function prepare_live_delivery_html($campaign, $subscriber) {
        $settings = $this->settings();
        $transport = $this->mail_transport_status();
        $capture_mode = in_array((string)($transport['key'] ?? ''), array('capture-smtp','capture-api'), true);
        $footer = '<div style="font-family:Arial,sans-serif;font-size:12px;line-height:1.5;color:#777;padding:14px 18px">'
            . esc_html($settings['footer_text'])
            . '<br><a href="' . esc_url($this->unsubscribe_url($subscriber)) . '">Unsubscribe</a></div>';

        if ($capture_mode) {
            // Local live tests use the same known-good document preparation as the
            // Demo Subscribers action. Tracking redirects/pixels are intentionally
            // omitted from Mailpit tests; they are not needed to test rendering and
            // previously left extra markup outside complete HTML documents.
            $html = $this->prepare_test_email_html($campaign, $subscriber, 'Live subscriber test');
            return $this->insert_before_email_end($html, $footer);
        }

        $html = $this->prepare_email_html($campaign->html, $subscriber, $campaign);
        return $this->insert_before_email_end($html, $footer . $this->tracking_pixel($campaign->id, $subscriber->id));
    }

    private function remove_test_email_side_gutters($html) {
        // Mail Designer exports 30px left/right show-through cells around the
        // 700px campaign. They are useful in a standalone template but create
        // an unwanted white strip inside the proof/demo frame.
        $clean = preg_replace(
            '~<td\b[^>]*\bclass\s*=\s*(["\'])[^"\']*\bEQ-01\b[^"\']*\1[^>]*>.*?</td>~is',
            '',
            (string)$html
        );
        if (!is_string($clean)) {
            $clean = (string)$html;
        }

        // Match the frame to the remaining 700px campaign content.
        $clean = preg_replace_callback(
            '~<table\b(?=[^>]*\bid\s*=\s*(["\'])email-body\1)[^>]*>~i',
            static function ($match) {
                $tag = $match[0];
                if (preg_match('~\bwidth\s*=\s*(["\'])?\d+\1~i', $tag)) {
                    $tag = preg_replace('~\bwidth\s*=\s*(["\'])?\d+\1~i', 'width="700"', $tag, 1);
                } else {
                    $tag = preg_replace('~>$~', ' width="700">', $tag);
                }
                return $tag;
            },
            $clean
        );

        return is_string($clean) ? $clean : (string)$html;
    }

    private function business_demo_wrapper($campaign) {
        $sub = (object)array('id'=>0,'email'=>get_option('admin_email'),'first_name'=>'Client','last_name'=>'Tester','token'=>'');
        $html = $this->personalize((string)$campaign->html, $sub, $campaign);
        return '<!doctype html><html><body style="margin:0;background:#f3f6fb;font-family:Arial,sans-serif"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:28px 12px"><table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #e5e7eb"><tr><td style="padding:22px 26px;background:#8d0752;color:#ffffff"><div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#ffc9e3">Demo campaign</div><h1 style="margin:6px 0 0;font-size:22px;line-height:1.2">' . esc_html($campaign->subject) . '</h1></td></tr><tr><td style="padding:0">' . $html . '</td></tr><tr><td style="padding:18px 26px;color:#667085;font-size:12px;border-top:1px solid #e5e7eb">Delivered one-by-one through WP Newsletter Campaigns. Use authenticated SMTP plus SPF, DKIM and DMARC to reduce spam placement.</td></tr></table></td></tr></table></body></html>';
    }

    private function mail_headers($subscriber = null, $delivery_id = '') {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'Precedence: bulk',
            'X-Auto-Response-Suppress: All',
        );
        if ($delivery_id !== '') $headers[] = 'X-WP-Delivery-ID: ' . sanitize_text_field($delivery_id);
        if (is_object($subscriber) && !empty($subscriber->id) && !empty($subscriber->token)) {
            $unsubscribe = $this->unsubscribe_url($subscriber);
            $headers[] = 'List-Unsubscribe: <' . esc_url_raw($unsubscribe) . '>';
            $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
            $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
            if ($host) $headers[] = 'List-ID: ' . sanitize_text_field(get_bloginfo('name')) . ' Newsletter <newsletter.' . preg_replace('/[^a-z0-9.-]/i', '', $host) . '>';
        }
        return $headers;
    }

    private function load_wordpress_phpmailer() {
        if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer') && class_exists('\\PHPMailer\\PHPMailer\\SMTP')) return true;
        $base = trailingslashit(ABSPATH . WPINC) . 'PHPMailer/';
        foreach (array('Exception.php','PHPMailer.php','SMTP.php') as $file) {
            $path = $base . $file;
            if (is_readable($path)) require_once $path;
        }
        return class_exists('\\PHPMailer\\PHPMailer\\PHPMailer') && class_exists('\\PHPMailer\\PHPMailer\\SMTP');
    }

    private function email_domain($email) {
        $email = sanitize_email((string)$email);
        if (!is_email($email) || strpos($email, '@') === false) return '';
        return strtolower((string)substr(strrchr($email, '@'), 1));
    }

    private function local_smtp_sender_identity() {
        $settings = $this->settings();
        $configured_from = $this->mail_from((string)get_option('admin_email'));
        $smtp_login = is_email($settings['smtp_username']) ? sanitize_email($settings['smtp_username']) : '';
        $force_aligned = !empty($settings['smtp_force_aligned_from']);
        $header_from = $configured_from;
        $envelope_from = $smtp_login !== '' ? $smtp_login : $configured_from;
        $reply_to = '';
        if ($force_aligned && $smtp_login !== '') {
            $header_from = $smtp_login;
            if (strcasecmp($configured_from, $header_from) !== 0) $reply_to = $configured_from;
        }
        return array(
            'header_from' => $header_from,
            'envelope_from' => $envelope_from,
            'reply_to' => $reply_to,
            'aligned' => $this->email_domain($header_from) !== '' && $this->email_domain($header_from) === $this->email_domain($envelope_from),
        );
    }

    private function message_id_for_log($message_id) {
        $message_id = trim((string)$message_id);
        $message_id = trim($message_id, "<> \t\n\r\0\x0B");
        return preg_replace('/[^a-z0-9@._+\-=]/i', '', $message_id);
    }

    private function inject_delivery_fingerprint($html, $to, $delivery_id) {
        $recipient_key = substr(hash('sha256', strtolower((string)$to) . '|' . (string)$delivery_id), 0, 24);
        $reference = 'wpnc-' . preg_replace('/[^a-z0-9-]/i', '', (string)$delivery_id) . '-' . $recipient_key;
        $marker = '<div aria-hidden="true" style="display:none!important;max-height:0!important;max-width:0!important;overflow:hidden!important;opacity:0!important;color:transparent!important;font-size:1px!important;line-height:1px!important">' . esc_html($reference) . '</div>';
        $html = (string)$html;
        if (preg_match('/<body\b[^>]*>/i', $html)) {
            $html = preg_replace('/<body\b[^>]*>/i', '$0' . $marker, $html, 1);
        } else {
            $html = $marker . $html;
        }
        return array($html, $reference);
    }

    private function normalize_capture_api_base($base) {
        $base = trim((string)$base);
        if ($base === '') return '';
        if (!preg_match('#^https?://#i', $base)) $base = 'http://' . ltrim($base, '/');
        $base = esc_url_raw($base);
        if ($base === '') return '';
        $base = preg_replace('#/api/v1(?:/send|/message/[^/?#]+|/messages)?/?(?:[?#].*)?$#i', '', $base);
        return untrailingslashit($base);
    }

    private function mailpit_internal_api_base() {
        $settings = $this->settings();
        $host = strtolower(trim((string)($settings['smtp_host'] ?? '')));
        if ($host === '') return '';
        $host = preg_replace('#^https?://#i', '', $host);
        $host = preg_replace('#/.*$#', '', $host);
        $host = preg_replace('/:\d+$/', '', $host);
        $host = trim($host, '[]');
        if (substr($host, -10) === '.localhost') $host = substr($host, 0, -10);
        if ($host === '' || strpos($host, 'mailpit') === false) return '';

        // In Docker/local development, the browser URL (for example
        // mailpit.localhost) normally resolves through a host reverse proxy. From
        // inside WordPress that hostname can point back to the Garilla site. The
        // Mailpit service name on the application network is the first host label.
        $service = preg_replace('/[^a-z0-9._-]/', '', explode('.', $host)[0]);
        if ($service === '' || strpos($service, 'mailpit') === false) $service = 'mailpit';
        return 'http://' . $service . ':8025';
    }

    private function capture_api_candidate_urls() {
        $settings = $this->settings();
        $configured = $this->normalize_capture_api_base((string)($settings['capture_api_url'] ?? ''));
        $internal = $this->normalize_capture_api_base($this->mailpit_internal_api_base());
        $candidates = array();

        $configured_host = $configured !== '' ? strtolower((string)wp_parse_url($configured, PHP_URL_HOST)) : '';
        $browser_style = $configured_host !== '' && (substr($configured_host, -10) === '.localhost' || $configured_host === 'localhost' || $configured_host === '127.0.0.1');

        // Prefer the Docker/network endpoint when the saved value is a browser-only
        // *.localhost URL. This prevents WordPress from posting the Mailpit payload
        // to its own public site and receiving a Garilla 404 page.
        if ($browser_style && $internal !== '') $candidates[] = $internal;
        if ($configured !== '') $candidates[] = $configured;
        if ($internal !== '') $candidates[] = $internal;

        return array_values(array_unique(array_filter($candidates)));
    }

    private function capture_api_base_url() {
        $candidates = $this->capture_api_candidate_urls();
        return $candidates ? (string)$candidates[0] : '';
    }

    private function should_use_capture_api() {
        // Mailpit's web UI/API port is not an SMTP transport. Proof, demo, live,
        // and unsent-retry delivery must all use the configured SMTP host/port
        // (normally mailpit:1025 locally), matching the working demo path.
        return false;
    }

    private function capture_api_error_excerpt($body, $code = 0) {
        $message = trim(wp_strip_all_tags((string)$body, true));
        $message = preg_replace('/\s+/', ' ', $message);
        if ($message === '') $message = 'HTTP ' . absint($code) . ' returned no JSON error message.';
        if (strlen($message) > 320) $message = substr($message, 0, 317) . '...';
        return $message;
    }

    private function capture_api_request($method, $path, $payload = null, $base_override = '') {
        $bases = $base_override !== ''
            ? array($this->normalize_capture_api_base($base_override))
            : $this->capture_api_candidate_urls();
        $bases = array_values(array_unique(array_filter($bases)));
        if (!$bases) return new WP_Error('capture_api_url', 'Mailpit API URL could not be determined. Use the internal server URL, normally http://mailpit:8025.');

        $settings = $this->settings();
        $headers = array('Accept' => 'application/json');
        if ($payload !== null) $headers['Content-Type'] = 'application/json; charset=utf-8';
        $api_user = trim((string)($settings['capture_api_username'] ?? ''));
        $api_pass = (string)($settings['capture_api_password'] ?? '');
        if ($api_user !== '') $headers['Authorization'] = 'Basic ' . base64_encode($api_user . ':' . $api_pass);
        $errors = array();

        foreach ($bases as $base) {
            $url = $base . '/' . ltrim((string)$path, '/');
            $args = array(
                'method' => strtoupper((string)$method),
                'timeout' => 20,
                'redirection' => 0,
                'headers' => $headers,
            );
            if ($payload !== null) $args['body'] = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $response = wp_remote_request($url, $args);
            if (is_wp_error($response)) {
                $errors[] = $base . ': ' . $response->get_error_message();
                continue;
            }
            $code = (int)wp_remote_retrieve_response_code($response);
            $body = (string)wp_remote_retrieve_body($response);
            if ($code < 200 || $code >= 300) {
                $errors[] = $base . ': HTTP ' . $code . ' - ' . $this->capture_api_error_excerpt($body, $code);
                continue;
            }
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                // A successful HTML page is not the Mailpit API. Continue to the
                // internal service endpoint instead of reporting a false capture.
                $errors[] = $base . ': non-JSON response - ' . $this->capture_api_error_excerpt($body, $code);
                continue;
            }
            return array('data'=>$decoded, 'url'=>$url, 'base'=>$base, 'code'=>$code);
        }

        return new WP_Error('capture_api_request', implode(' | ', $errors), array('bases'=>$bases));
    }

    private function mailpit_message_recipient($message) {
        if (!is_array($message)) return '';
        $to_list = $message['To'] ?? ($message['to'] ?? array());
        if (!is_array($to_list)) return '';
        foreach ($to_list as $address) {
            if (is_string($address) && is_email($address)) return sanitize_email($address);
            if (!is_array($address)) continue;
            foreach (array('Address', 'Email', 'address', 'email') as $key) {
                if (!empty($address[$key]) && is_email($address[$key])) return sanitize_email((string)$address[$key]);
            }
        }
        return '';
    }

    private function verify_mailpit_capture($mailpit_id, $expected_message_id, $expected_to, $api_base = '') {
        $mailpit_id = sanitize_text_field((string)$mailpit_id);
        $expected_message_id = $this->message_id_for_log($expected_message_id);
        $expected_to = sanitize_email((string)$expected_to);
        $last_note = '';

        // Read-back is optional. The official Send API response already returns
        // the stored database ID, so a missing/unsupported detail endpoint must
        // not delay the queue or turn a successful insert into a retry.
        $stored = $this->capture_api_request('GET', '/api/v1/message/' . rawurlencode($mailpit_id), null, $api_base);
        if (!is_wp_error($stored)) {
            $message = is_array($stored['data'] ?? null) ? $stored['data'] : array();
            $stored_to = $this->mailpit_message_recipient($message);
            if ($stored_to !== '' && strcasecmp($stored_to, $expected_to) !== 0) {
                return array('verified'=>false, 'mismatch'=>true, 'stored_to'=>$stored_to, 'message_id'=>(string)($message['MessageID'] ?? ''));
            }
            if ($stored_to !== '') {
                return array('verified'=>true, 'mismatch'=>false, 'stored_to'=>$stored_to, 'message_id'=>(string)($message['MessageID'] ?? ($message['message_id'] ?? '')), 'note'=>'detail endpoint');
            }
            $last_note = 'detail response did not include a recipient';
        } else {
            $data = $stored->get_error_data();
            $status = is_array($data) ? (int)($data['status'] ?? 0) : 0;
            $last_note = ($status ? 'detail HTTP ' . $status . ': ' : 'detail: ') . $stored->get_error_message();
        }

        // Compatibility fallback for Mailpit builds or proxies where the detail
        // route is unavailable but the standard mailbox list endpoint is exposed.
        $listed = $this->capture_api_request('GET', '/api/v1/messages?start=0&limit=100', null, $api_base);
        if (!is_wp_error($listed)) {
            $payload = is_array($listed['data'] ?? null) ? $listed['data'] : array();
            $messages = $payload['messages'] ?? ($payload['Messages'] ?? array());
            if (is_array($messages)) {
                foreach ($messages as $message) {
                    if (!is_array($message)) continue;
                    $candidate_id = sanitize_text_field((string)($message['ID'] ?? ($message['id'] ?? '')));
                    $candidate_message_id = $this->message_id_for_log((string)($message['MessageID'] ?? ($message['message_id'] ?? '')));
                    if ($candidate_id !== $mailpit_id && ($expected_message_id === '' || $candidate_message_id !== $expected_message_id)) continue;
                    $stored_to = $this->mailpit_message_recipient($message);
                    if ($stored_to !== '' && strcasecmp($stored_to, $expected_to) !== 0) {
                        return array('verified'=>false, 'mismatch'=>true, 'stored_to'=>$stored_to, 'message_id'=>$candidate_message_id);
                    }
                    if ($stored_to !== '') {
                        return array('verified'=>true, 'mismatch'=>false, 'stored_to'=>$stored_to, 'message_id'=>$candidate_message_id, 'note'=>'message list endpoint');
                    }
                }
                $last_note .= ($last_note !== '' ? '; ' : '') . 'database ID not yet visible in message list';
            } else {
                $last_note .= ($last_note !== '' ? '; ' : '') . 'message list response had no messages array';
            }
        } else {
            $data = $listed->get_error_data();
            $status = is_array($data) ? (int)($data['status'] ?? 0) : 0;
            $last_note .= ($last_note !== '' ? '; ' : '') . 'list ' . ($status ? 'HTTP ' . $status . ': ' : '') . $listed->get_error_message();
        }

        return array(
            'verified' => false,
            'mismatch' => false,
            'stored_to' => '',
            'message_id' => $expected_message_id,
            'note' => $last_note !== '' ? $last_note : 'read-back verification unavailable',
        );
    }

    private function send_via_capture_api($to, $subject, $html, $subscriber = null) {
        $identity = $this->local_smtp_sender_identity();
        $from_email = sanitize_email((string)$identity['header_from']);
        $from_name = $this->mail_from_name((string)get_bloginfo('name'));
        $to = sanitize_email((string)$to);
        if (!is_email($from_email)) {
            $this->last_mail_error = 'The Mailpit API From email address is invalid.';
            return false;
        }
        if (!is_email($to)) {
            $this->last_mail_error = 'The Mailpit API recipient email address is invalid.';
            return false;
        }

        $recipient_name = '';
        if (is_object($subscriber)) {
            $recipient_name = trim(sanitize_text_field(((string)($subscriber->first_name ?? '')) . ' ' . ((string)($subscriber->last_name ?? ''))));
        }
        list($fingerprinted_html, $delivery_reference) = $this->inject_delivery_fingerprint($html, $to, $this->last_mail_delivery_id);
        $api_headers = array(
            'Message-ID' => $this->current_mail_message_id,
            'Precedence' => 'bulk',
            'X-Auto-Response-Suppress' => 'All',
            'X-WP-Delivery-ID' => $this->last_mail_delivery_id,
            'X-Entity-Ref-ID' => $this->last_mail_delivery_id,
            'X-WP-Delivery-Reference' => $delivery_reference,
            'X-WP-Recipient-Key' => hash('sha256', strtolower($to) . '|' . $this->last_mail_delivery_id),
        );
        foreach ($this->mail_headers($subscriber, $this->last_mail_delivery_id) as $header) {
            if (stripos($header, 'Content-Type:') === 0) continue;
            $parts = explode(':', $header, 2);
            if (count($parts) !== 2) continue;
            $name = trim((string)$parts[0]);
            $value = trim((string)$parts[1]);
            if ($name !== '' && $value !== '') $api_headers[$name] = $value;
        }

        $payload = array(
            'From' => array('Email'=>$from_email, 'Name'=>$from_name),
            'To' => array(array('Email'=>$to, 'Name'=>$recipient_name)),
            'Subject' => (string)$subject,
            'HTML' => $fingerprinted_html,
            'Text' => wp_strip_all_tags((string)$html, true) . "\n\nDelivery reference: " . $delivery_reference,
            'Headers' => $api_headers,
            'Tags' => array('WP Newsletter Campaigns', 'WP ' . substr($this->last_mail_delivery_id, 0, 8)),
        );
        if (!empty($identity['reply_to']) && is_email($identity['reply_to'])) {
            $payload['ReplyTo'] = array(array('Email'=>sanitize_email($identity['reply_to']), 'Name'=>$from_name));
        }

        $sent = $this->capture_api_request('POST', '/api/v1/send', $payload);
        if (is_wp_error($sent)) {
            $this->last_mail_error = 'Mailpit API capture failed: ' . $sent->get_error_message();
            $this->last_mail_response = 'Mailpit API candidates: ' . implode(', ', $this->capture_api_candidate_urls());
            return false;
        }
        $mailpit_id = sanitize_text_field((string)($sent['data']['ID'] ?? ''));
        if ($mailpit_id === '') {
            $this->last_mail_error = 'Mailpit API did not return a stored message ID.';
            $this->last_mail_response = 'Mailpit API response did not confirm storage.';
            return false;
        }

        // The Send API's successful response contains Mailpit's database ID. Some
        // Mailpit versions or reverse-proxy setups do not expose the single-message
        // detail route immediately (or at all), so verification is best-effort and
        // must never turn a successful API insert into a retry loop.
        $api_base = $this->normalize_capture_api_base((string)($sent['base'] ?? ''));
        $verification = $this->verify_mailpit_capture($mailpit_id, $this->current_mail_message_id, $to, $api_base);
        if (!empty($verification['mismatch'])) {
            $this->last_mail_error = 'Mailpit verification returned a different recipient for stored message ' . $mailpit_id . '.';
            $this->last_mail_response = 'Expected To: ' . $to . ' | Stored To: ' . (string)($verification['stored_to'] ?? '') . ' | Mailpit ID: ' . $mailpit_id;
            return false;
        }

        $stored_message_id = trim((string)($verification['message_id'] ?? ''));
        $this->last_mail_message_id = $stored_message_id !== '' ? $stored_message_id : $this->current_mail_message_id;
        $verification_text = !empty($verification['verified'])
            ? 'stored and recipient verified'
            : 'stored; detail verification unavailable';
        $this->last_mail_response = 'Mailpit API ' . $verification_text
            . ' | Mailpit ID: ' . $mailpit_id
            . ' | Header To: ' . $to
            . ' | Recipient mode: direct-to-no-bcc'
            . ' | Header From: ' . $from_email
            . ' | Message-ID: ' . $this->message_id_for_log($this->last_mail_message_id)
            . (!empty($verification['note']) ? ' | Verification: ' . sanitize_text_field((string)$verification['note']) : '')
            . ' | API: ' . ($api_base !== '' ? $api_base : $this->capture_api_base_url());
        $this->save_smtp_diagnostic(true, 'Mailpit HTTP API capture mode is active.', 'Mailpit ID: ' . $mailpit_id);
        return true;
    }

    private function send_via_local_smtp($to, $subject, $html, $subscriber = null) {
        if (!$this->load_wordpress_phpmailer()) {
            $this->last_mail_error = 'WordPress PHPMailer classes could not be loaded.';
            return false;
        }

        $settings = $this->settings();
        $server_replies = array();
        $waiting_for_data_reply = false;
        $data_reply = '';
        $mailer = null;

        try {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->isSMTP();
            $configured_smtp_host = sanitize_text_field((string)$settings['smtp_host']);
            $connection_smtp_host = $this->pinned_local_smtp_host();
            $mailer->Host = $connection_smtp_host !== '' ? $connection_smtp_host : $configured_smtp_host;
            $mailer->Port = max(1, min(65535, absint($settings['smtp_port'])));
            $mailer->SMTPAuth = !empty($settings['smtp_username']);
            if ($mailer->SMTPAuth) {
                $mailer->Username = sanitize_text_field($settings['smtp_username']);
                $mailer->Password = (string)$settings['smtp_password'];
            }
            $secure = sanitize_key((string)$settings['smtp_secure']);
            if ($secure === 'tls') {
                $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mailer->SMTPAutoTLS = true;
            } elseif ($secure === 'ssl') {
                $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                $mailer->SMTPAutoTLS = true;
            } else {
                $mailer->SMTPSecure = '';
                $mailer->SMTPAutoTLS = false;
            }
            $mailer->SMTPKeepAlive = false;
            $mailer->Timeout = 30;
            $mailer->Timelimit = 30;
            $mailer->CharSet = 'UTF-8';
            $mailer->Encoding = 'quoted-printable';
            $mailer->MessageID = $this->current_mail_message_id;
            $mailer->Hostname = preg_replace('/[^a-z0-9.-]+/i', '', (string)wp_parse_url(home_url('/'), PHP_URL_HOST));

            // Capture server replies only. Client commands and authentication data
            // are deliberately excluded from the admin delivery log.
            $mailer->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
            $mailer->Debugoutput = static function ($message, $level) use (&$server_replies, &$waiting_for_data_reply, &$data_reply) {
                if ((int)$level !== 2) return;
                $clean = preg_replace('/^\\s*SERVER -> CLIENT:\\s*/i', '', trim((string)$message));
                foreach (preg_split('/\\r\\n|\\r|\\n/', (string)$clean) as $line) {
                    $line = trim((string)$line);
                    if ($line === '' || !preg_match('/^(\\d{3})(?:[ -])(.*)$/', $line, $match)) continue;
                    $server_replies[] = $line;
                    $code = (int)$match[1];
                    if ($code === 354) {
                        $waiting_for_data_reply = true;
                        continue;
                    }
                    if ($waiting_for_data_reply) {
                        $data_reply = $line;
                        $waiting_for_data_reply = false;
                    }
                }
            };

            $identity = $this->local_smtp_sender_identity();
            $from_email = $identity['header_from'];
            $envelope_from = $identity['envelope_from'];
            $from_name = $this->mail_from_name((string)get_bloginfo('name'));
            if (!is_email($from_email)) throw new RuntimeException('The effective From email address is invalid.');
            if (!is_email($envelope_from)) throw new RuntimeException('The SMTP envelope sender is invalid.');
            if (!is_email($to)) throw new RuntimeException('The recipient email address is invalid.');
            $mailer->setFrom($from_email, $from_name, false);
            $mailer->Sender = $envelope_from;
            // Every newsletter message is a true one-to-one delivery. Clear all
            // recipient buckets explicitly, add the subscriber as a normal To
            // recipient, and disable PHPMailer's SingleTo compatibility mode. That
            // mode is intended for the PHP mail() transport and can produce an
            // undisclosed-recipient/BCC presentation when used with SMTP relays.
            $mailer->clearAddresses();
            $mailer->clearCCs();
            $mailer->clearBCCs();
            $mailer->clearReplyTos();
            if (!empty($identity['reply_to']) && is_email($identity['reply_to'])) {
                $mailer->addReplyTo($identity['reply_to'], $from_name);
            }
            $recipient_name = '';
            if (is_object($subscriber)) {
                $recipient_name = trim(sanitize_text_field(((string)($subscriber->first_name ?? '')) . ' ' . ((string)($subscriber->last_name ?? ''))));
            }
            $mailer->addAddress($to, $recipient_name);
            $mailer->SingleTo = false;
            $mailer->Subject = (string)$subject;
            $mailer->isHTML(true);
            list($fingerprinted_html, $delivery_reference) = $this->inject_delivery_fingerprint($html, $to, $this->last_mail_delivery_id);
            $mailer->Body = $fingerprinted_html;
            $mailer->AltBody = wp_strip_all_tags((string)$html, true) . "\n\nDelivery reference: " . $delivery_reference;

            $mailer->addCustomHeader('X-Entity-Ref-ID', $this->last_mail_delivery_id);
            $mailer->addCustomHeader('X-WP-Delivery-Reference', $delivery_reference);
            $mailer->addCustomHeader('X-WP-Recipient-Key', hash('sha256', strtolower((string)$to) . '|' . $this->last_mail_delivery_id));
            foreach ($this->mail_headers($subscriber, $this->last_mail_delivery_id) as $header) {
                if (stripos($header, 'Content-Type:') === 0) continue;
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) $mailer->addCustomHeader(trim($parts[0]), trim($parts[1]));
            }

            // Build the MIME message before opening the SMTP transaction and
            // validate that the subscriber is present in the visible To header,
            // with no CC/BCC recipients. This prevents a relay from receiving an
            // envelope-only/BCC message while the plugin reports a direct send.
            if (!$mailer->preSend()) {
                throw new RuntimeException(trim((string)$mailer->ErrorInfo) ?: 'PHPMailer could not prepare the message.');
            }
            $to_addresses = method_exists($mailer, 'getToAddresses') ? (array)$mailer->getToAddresses() : array();
            $cc_addresses = method_exists($mailer, 'getCcAddresses') ? (array)$mailer->getCcAddresses() : array();
            $bcc_addresses = method_exists($mailer, 'getBccAddresses') ? (array)$mailer->getBccAddresses() : array();
            $prepared_to = isset($to_addresses[0][0]) ? sanitize_email((string)$to_addresses[0][0]) : '';
            $mime_message = method_exists($mailer, 'getSentMIMEMessage') ? (string)$mailer->getSentMIMEMessage() : '';
            $mime_headers = preg_split('/\r\n\r\n|\n\n|\r\r/', $mime_message, 2)[0] ?? '';
            $prepared_message_id = '';
            if (preg_match('/^Message-ID:\s*(<[^>\r\n]+>)/mi', $mime_headers, $message_id_match)) {
                $prepared_message_id = trim((string)$message_id_match[1]);
            }
            if ($prepared_message_id === '' || strcasecmp($prepared_message_id, $this->current_mail_message_id) !== 0) {
                throw new RuntimeException('Unique Message-ID validation failed before SMTP submission.');
            }
            $this->last_mail_message_id = $prepared_message_id;
            $has_visible_to = preg_match('/^To:\s*.+$/mi', $mime_headers) === 1;
            $has_undisclosed = stripos($mime_headers, 'undisclosed-recipients') !== false;
            $has_bcc_header = preg_match('/^Bcc:/mi', $mime_headers) === 1;
            if (count($to_addresses) !== 1 || strcasecmp($prepared_to, sanitize_email($to)) !== 0 || $cc_addresses || $bcc_addresses || !$has_visible_to || $has_undisclosed || $has_bcc_header) {
                throw new RuntimeException('Recipient header validation failed. The message was not submitted because it was not a single visible To delivery.');
            }

            $sent = $mailer->postSend();
            $reported_message_id = method_exists($mailer, 'getLastMessageID') ? trim((string)$mailer->getLastMessageID()) : '';
            if ($reported_message_id !== '' && strcasecmp($reported_message_id, $this->current_mail_message_id) === 0) {
                $this->last_mail_message_id = $reported_message_id;
            }
            if ($this->last_mail_message_id === '') $this->last_mail_message_id = $this->current_mail_message_id;

            // A local SMTP message is only marked accepted when the server reply
            // immediately after DATA is a real 2xx response. A later 221 QUIT reply
            // is never used as delivery evidence.
            if (!$sent) {
                $this->last_mail_error = trim((string)$mailer->ErrorInfo) ?: 'PHPMailer reported a local SMTP failure.';
                $this->last_mail_response = $data_reply !== '' ? $data_reply : (end($server_replies) ?: '');
                return false;
            }
            if ($data_reply === '' || !preg_match('/^2\\d\\d(?:[ -])/', $data_reply)) {
                $this->last_mail_response = $data_reply !== '' ? $data_reply : (end($server_replies) ?: '');
                $this->last_mail_error = 'SMTP did not return a confirmed 2xx response after the message DATA. The message was not marked accepted.';
                return false;
            }

            $capture_reason = $this->smtp_capture_reason_from_replies($server_replies);
            if ($capture_reason !== '') {
                $this->save_smtp_diagnostic(true, $capture_reason, $data_reply);
            } else {
                $this->save_smtp_diagnostic(false, 'External SMTP server accepted the message transaction.', $data_reply);
            }

            $this->last_mail_response = $data_reply
                . ($capture_reason !== '' ? ' | Captured locally by test SMTP; external delivery not attempted' : '')
                . ' | RCPT TO: ' . sanitize_email($to)
                . ' | Header To: ' . sanitize_email($to)
                . ' | Recipient mode: direct-to-no-bcc'
                . ' | Header From: ' . sanitize_email($from_email)
                . ' | Envelope-From: ' . sanitize_email($envelope_from)
                . ' | Message-ID: ' . $this->message_id_for_log($this->last_mail_message_id)
                . $this->local_smtp_endpoint_log();
            $smtp = $mailer->getSMTPInstance();
            if (is_object($smtp) && method_exists($smtp, 'getLastTransactionID')) {
                $transaction_id = trim((string)$smtp->getLastTransactionID());
                if ($transaction_id !== '') $this->last_mail_response .= ' | Transaction ID: ' . $transaction_id;
            }
            return true;
        } catch (Throwable $e) {
            $this->last_mail_error = sanitize_text_field($e->getMessage());
            if ($mailer && property_exists($mailer, 'ErrorInfo') && trim((string)$mailer->ErrorInfo) !== '') {
                $this->last_mail_error = trim((string)$mailer->ErrorInfo);
            }
            if ($data_reply !== '') $this->last_mail_response = $data_reply;
            elseif ($server_replies) $this->last_mail_response = (string)end($server_replies);
            return false;
        } finally {
            if ($mailer && method_exists($mailer, 'smtpClose')) $mailer->smtpClose();
        }
    }

    private function live_transport_subscriber_context($subscriber) {
        // Demo delivery is the proven local-test path. Mailpit/capture transports
        // receive the same lean per-recipient headers for live tests, while the
        // personalized unsubscribe link remains in the message body. Real SMTP
        // transports still receive List-Unsubscribe and List-ID headers.
        $transport = $this->mail_transport_status();
        return in_array((string)($transport['key'] ?? ''), array('capture-smtp','capture-api'), true) ? null : $subscriber;
    }

    private function send_live_html_mail($to, $subject, $html, $subscriber = null) {
        // Live, retry, demo, proof, and welcome messages share the same wp_mail
        // handoff so the administrator-selected WordPress queue owns delivery.
        return $this->send_html_mail($to, $subject, $html, $subscriber);
    }

    /**
     * Keep a WP delivery on one visible recipient even if another wp_mail filter
     * attempts to append a To, CC, or BCC address before GD Mail Queue intercepts it.
     */
    public function force_single_wp_mail_recipient($args) {
        if ($this->current_mail_recipient === '' || $this->last_mail_delivery_id === '') return $args;

        $headers = $args['headers'] ?? array();
        $header_lines = is_array($headers) ? $headers : preg_split('/\r\n|\r|\n/', (string)$headers);
        $delivery_header = 'X-WP-Delivery-ID: ' . $this->last_mail_delivery_id;
        $is_current_delivery = false;
        $clean_headers = array();

        foreach ((array)$header_lines as $header) {
            $header = (string)$header;
            if (strcasecmp(trim($header), $delivery_header) === 0) $is_current_delivery = true;
            if (preg_match('/^\s*(?:to|cc|bcc)\s*:/i', $header)) continue;
            if ($header !== '') $clean_headers[] = $header;
        }

        if (!$is_current_delivery) return $args;
        $args['to'] = $this->current_mail_recipient;
        $args['headers'] = $clean_headers;
        return $args;
    }

    /**
     * GD Mail Queue can retain orphaned log-email relations when its log table is
     * cleared and auto-increment IDs are reused. The queue row remains correct,
     * but the admin log then displays unrelated historical addresses. WP's
     * unique delivery header lets us restore the actual To, From, and Reply-To
     * relations from the matching queue row.
     */
    private function gd_mail_queue_tables() {
        if ($this->gd_mail_queue_tables !== null) return $this->gd_mail_queue_tables;
        global $wpdb;
        $prefix = $wpdb->base_prefix;
        $tables = array(
            'log' => $prefix . 'gdmaq_log',
            'relation' => $prefix . 'gdmaq_log_email',
            'email' => $prefix . 'gdmaq_emails',
            'queue' => $prefix . 'gdmaq_queue',
        );
        foreach ($tables as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                $this->gd_mail_queue_tables = false;
                return false;
            }
        }
        $this->gd_mail_queue_tables = $tables;
        return $tables;
    }

    private function repair_gd_mail_queue_log_relations($delivery_id, $recipient) {
        global $wpdb;
        $delivery_id = sanitize_text_field((string)$delivery_id);
        $recipient = sanitize_email((string)$recipient);
        if ($delivery_id === '' || !is_email($recipient)) return 0;

        $tables = $this->gd_mail_queue_tables();
        if (!$tables) return 0;
        $log_table = $tables['log'];
        $relation_table = $tables['relation'];
        $email_table = $tables['email'];
        $queue_table = $tables['queue'];

        $email_id = absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$email_table} WHERE email=%s",
            $recipient
        )));
        if (!$email_id) return 0;

        $header_match = '%' . $wpdb->esc_like($delivery_id) . '%';
        $log_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT l.id
             FROM {$log_table} l
             INNER JOIN {$relation_table} current_to
                ON current_to.log_id=l.id AND current_to.rel='to' AND current_to.email_id=%d
            WHERE l.headers LIKE %s",
            $email_id,
            $header_match
        ));

        $expected = array('to' => array($email_id));
        $queue_extras = $wpdb->get_var($wpdb->prepare(
            "SELECT extras FROM {$queue_table}
             WHERE to_email=%s AND headers LIKE %s
             ORDER BY id DESC LIMIT 1",
            $recipient,
            $header_match
        ));
        $extras = json_decode((string)$queue_extras, true);
        if (is_array($extras)) {
            $expected_emails = array();
            if (!empty($extras['From']) && is_email($extras['From'])) {
                $expected_emails['from'] = array(sanitize_email($extras['From']));
            }
            foreach ((array)($extras['ReplyTo'] ?? array()) as $reply_to) {
                $reply_email = sanitize_email(is_array($reply_to) ? (string)($reply_to[0] ?? '') : (string)$reply_to);
                if (is_email($reply_email)) $expected_emails['reply_to'][] = $reply_email;
            }
            foreach ($expected_emails as $relation => $emails) {
                foreach (array_unique($emails) as $email) {
                    $relation_email_id = absint($wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$email_table} WHERE email=%s",
                        $email
                    )));
                    if ($relation_email_id) $expected[$relation][] = $relation_email_id;
                }
            }
        }

        $removed = 0;
        foreach ($log_ids as $log_id) {
            foreach ($expected as $relation => $email_ids) {
                $email_ids = array_values(array_unique(array_filter(array_map('absint', $email_ids))));
                if (!$email_ids) continue;
                $expected_list = implode(',', $email_ids);
                $present = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$relation_table}
                     WHERE log_id=%d AND rel=%s AND email_id IN ({$expected_list})",
                    absint($log_id),
                    $relation
                ));
                if ($present !== count($email_ids)) continue;
                $result = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$relation_table}
                     WHERE log_id=%d AND rel=%s AND email_id NOT IN ({$expected_list})",
                    absint($log_id),
                    $relation
                ));
                if ($result !== false) $removed += (int)$result;
            }
        }
        return $removed;
    }

    private function repair_existing_gd_mail_queue_log_relations() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT delivery_id,recipient
             FROM {$this->table('delivery_logs')}
             WHERE delivery_type='welcome' AND delivery_id<>'' AND recipient<>''
             ORDER BY id DESC
             LIMIT 500"
        );
        foreach ((array)$rows as $row) {
            $this->repair_gd_mail_queue_log_relations($row->delivery_id, $row->recipient);
        }
    }

    private function send_html_mail($to, $subject, $html, $subscriber = null) {
        $this->last_mail_error = '';
        $this->last_mail_message_id = '';
        $this->last_mail_response = '';
        $this->last_mail_delivery_id = wp_generate_uuid4();
        $this->current_mail_recipient = sanitize_email((string)$to);
        if (!is_email($this->current_mail_recipient)) {
            $this->last_mail_error = 'A valid single recipient is required.';
            $this->current_mail_recipient = '';
            return false;
        }
        $host = strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
        $host = preg_replace('/[^a-z0-9.-]+/i', '', $host);
        if ($host === '') $host = 'localhost.localdomain';
        $message_token = str_replace('-', '', $this->last_mail_delivery_id);
        $recipient_token = substr(hash('sha256', strtolower($this->current_mail_recipient)), 0, 16);
        $this->current_mail_message_id = '<wpnc-' . $message_token . '.' . $recipient_token . '@' . $host . '>';

        // WP Newsletter Campaigns deliberately stops at wp_mail(). GD Mail Queue or any
        // other administrator-selected WordPress mail plugin owns queueing,
        // SMTP/API transport, retries, and provider diagnostics from this point.
        add_filter('wp_mail', array($this, 'force_single_wp_mail_recipient'), PHP_INT_MAX);
        try {
            $ok = wp_mail($this->current_mail_recipient, $subject, $html, $this->mail_headers($subscriber, $this->last_mail_delivery_id));
            $this->repair_gd_mail_queue_log_relations($this->last_mail_delivery_id, $this->current_mail_recipient);
            $mailer = $GLOBALS['phpmailer'] ?? null;
            if (is_object($mailer)) {
                if (method_exists($mailer, 'getLastMessageID')) $this->last_mail_message_id = trim((string)$mailer->getLastMessageID());
                if ($this->last_mail_message_id === '') $this->last_mail_message_id = $this->current_mail_message_id;
                if (property_exists($mailer, 'ErrorInfo') && !$ok && $this->last_mail_error === '') $this->last_mail_error = trim((string)$mailer->ErrorInfo);
            }
            if ($ok) $this->last_mail_response = 'WordPress wp_mail accepted the message. Check the active site mail/queue plugin for delivery status.';
            return $ok;
        } finally {
            remove_filter('wp_mail', array($this, 'force_single_wp_mail_recipient'), PHP_INT_MAX);
            $this->current_mail_message_id = '';
            $this->current_mail_recipient = '';
            $mailer = $GLOBALS['phpmailer'] ?? null;
            if (is_object($mailer) && property_exists($mailer, 'MessageID')) $mailer->MessageID = '';
        }
    }

    public function capture_wp_mail_failure($error) {
        if (is_wp_error($error)) {
            $this->last_mail_error = $error->get_error_message();
        } elseif (is_object($error) && method_exists($error, 'getMessage')) {
            $this->last_mail_error = $error->getMessage();
        } else {
            $this->last_mail_error = 'wp_mail failed';
        }
    }

    private function record_delivery_log($args = array()) {
        global $wpdb;
        $defaults = array(
            'campaign_id' => 0,
            'subscriber_id' => 0,
            'run_id' => '',
            'delivery_id' => $this->last_mail_delivery_id,
            'recipient' => '',
            'delivery_type' => 'live',
            'status' => 'queued',
            'attempt' => 0,
            'transport' => $this->mail_transport_status()['key'],
            'message_id' => $this->last_mail_message_id,
            'response' => $this->last_mail_response,
        );
        $row = wp_parse_args(is_array($args) ? $args : array(), $defaults);
        $wpdb->insert($this->table('delivery_logs'), array(
            'campaign_id' => absint($row['campaign_id']),
            'subscriber_id' => absint($row['subscriber_id']),
            'run_id' => sanitize_text_field((string)$row['run_id']),
            'delivery_id' => sanitize_text_field((string)$row['delivery_id']),
            'recipient' => sanitize_email((string)$row['recipient']),
            'delivery_type' => sanitize_key((string)$row['delivery_type']),
            'status' => sanitize_key((string)$row['status']),
            'attempt' => absint($row['attempt']),
            'transport' => sanitize_key((string)$row['transport']),
            'message_id' => $this->message_id_for_log((string)$row['message_id']),
            'response' => sanitize_textarea_field((string)$row['response']),
            'created' => current_time('mysql'),
        ));
        $insert_id = (int)$wpdb->insert_id;
        if ($insert_id > 0 && $insert_id % 100 === 0) {
            $cutoff = (int)$wpdb->get_var("SELECT id FROM {$this->table('delivery_logs')} ORDER BY id DESC LIMIT 1 OFFSET 4999");
            if ($cutoff > 0) $wpdb->query($wpdb->prepare("DELETE FROM {$this->table('delivery_logs')} WHERE id<%d", $cutoff));
        }
        return $insert_id;
    }

    private function record_test_log($email, $status, $message, $campaign_id = 0) {
        $logs = get_option('wp_newslatter_campaigns_test_logs', array());
        if (!is_array($logs)) $logs = array();
        $logs[] = array('email'=>$email,'status'=>$status,'message'=>$message,'campaign_id'=>(int)$campaign_id,'time'=>current_time('mysql'));
        $logs = array_slice($logs, -50);
        update_option('wp_newslatter_campaigns_test_logs', $logs, false);
    }

    public function configure_phpmailer($phpmailer) {
        // WordPress reuses the global PHPMailer object. Assign a fresh, explicit
        // Message-ID for every recipient so mailbox and relay de-duplication can
        // never collapse separate live deliveries.
        if (is_object($phpmailer) && property_exists($phpmailer, 'MessageID')) {
            $phpmailer->MessageID = $this->current_mail_message_id !== '' ? $this->current_mail_message_id : '';
        }
        if ($this->is_post_smtp_active()) return;
        $s = $this->settings();
        if (empty($s['smtp_enabled']) || empty($s['smtp_host'])) return;
        $phpmailer->isSMTP();
        $phpmailer->Host = sanitize_text_field($s['smtp_host']);
        $phpmailer->Port = absint($s['smtp_port']);
        $phpmailer->SMTPAuth = !empty($s['smtp_username']);
        if (!empty($s['smtp_username'])) $phpmailer->Username = sanitize_text_field($s['smtp_username']);
        if (!empty($s['smtp_password'])) $phpmailer->Password = (string)$s['smtp_password'];
        if (!empty($s['smtp_secure']) && $s['smtp_secure'] !== 'none') $phpmailer->SMTPSecure = sanitize_key($s['smtp_secure']);
        $phpmailer->SMTPKeepAlive = false;
        $phpmailer->Timeout = 30;
    }

    public function mail_content_type($content_type) { return $content_type; }

    public function send_campaign_now() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $id = absint($_GET['id'] ?? 0);
        check_admin_referer('wp_newslatter_campaigns_send_' . $id);
        $transport_check = $this->external_delivery_preflight();
        if (is_wp_error($transport_check)) $this->redirect_admin('wp-newslatter-campaigns-campaigns&edit=' . $id, '', $transport_check->get_error_message());
        global $wpdb;
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT status,total,sent FROM {$this->table('campaigns')} WHERE id=%d", $id));
        $recipient_history = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table('sent')} WHERE campaign_id=%d", $id));
        if (!$campaign) $this->redirect_admin('wp-newslatter-campaigns-campaigns', '', 'Campaign not found.');
        if ($campaign->status !== 'draft' || (int)$campaign->total > 0 || (int)$campaign->sent > 0 || $recipient_history > 0) {
            $this->redirect_admin('wp-newslatter-campaigns-campaigns&edit=' . $id, '', 'This campaign already has live-send history. Retry only unsent subscribers, or use the restricted Reset to Draft control before a full new live send.');
        }
        $queued = $this->queue_campaign($id, false);
        if (is_wp_error($queued)) $this->redirect_admin('wp-newslatter-campaigns-campaigns', '', $queued->get_error_message());
        $count = is_array($queued) ? absint($queued['count'] ?? 0) : absint($queued);
        $run_id = is_array($queued) ? sanitize_text_field((string)($queued['run_id'] ?? '')) : '';

        // Complete small lists now, one recipient at a time in the same request.
        // Larger lists retain WP's durable recipient scheduler while every
        // message is handed to the administrator-selected WordPress mail queue.
        $settings = $this->settings();
        $capture_mode = in_array($this->mail_transport_status()['key'], array('capture-smtp','capture-api'), true);
        $first_limit = $capture_mode ? min(100, $count) : ($count <= 25 ? $count : min($count, max(1, absint($settings['send_batch_size']))));
        $processed = $this->process_live_run_immediately($id, $run_id, $first_limit);

        $counts = $this->live_run_status_counts($id, $run_id);
        $remaining = $counts['pending'] + $counts['retry'] + $counts['processing'];
        if ($remaining) {
            $this->schedule_live_burst($id, $run_id, time() + max(1, min(2, absint($this->settings()['send_batch_interval']))));
            if (function_exists('spawn_cron')) spawn_cron();
        }
        $notice = 'Live send run: ' . absint($counts['sent']) . ' of ' . absint($count) . ' accepted by WordPress; check the active site mail queue for final delivery status';
        if ($counts['error']) $notice .= ', ' . absint($counts['error']) . ' failed';
        $notice .= $remaining ? ', ' . absint($remaining) . ' waiting/retrying.' : '. All active recipients were processed.';
        $error_notice = $counts['error']
            ? absint($counts['error']) . ' live recipient(s) failed. Open the campaign and review Unsent live subscribers.'
            : '';
        $this->redirect_admin('wp-newslatter-campaigns-campaigns', $notice, $error_notice);
    }

    public function resume_campaign_now() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $id = absint($_GET['id'] ?? 0);
        check_admin_referer('wp_newslatter_campaigns_resume_' . $id);
        $transport_check = $this->external_delivery_preflight();
        if (is_wp_error($transport_check)) $this->redirect_admin('wp-newslatter-campaigns-campaigns&edit=' . $id, '', $transport_check->get_error_message());
        $run_id = $this->latest_live_run_id($id);
        if ($run_id === '') $this->redirect_admin('wp-newslatter-campaigns-campaigns', '', 'No live delivery run is available to resume.');

        $before = $this->live_run_status_counts($id, $run_id);
        $waiting_before = $before['pending'] + $before['retry'] + $before['processing'];
        $processed = $waiting_before <= 25
            ? $this->process_live_run_immediately($id, $run_id, max(1, $waiting_before))
            : $this->process_live_burst($id, $run_id);

        $counts = $this->live_run_status_counts($id, $run_id);
        $remaining = $counts['pending'] + $counts['retry'] + $counts['processing'];
        if ($remaining) {
            $this->schedule_live_burst($id, $run_id, time() + max(1, min(2, absint($this->settings()['send_batch_interval']))));
            if (function_exists('spawn_cron')) spawn_cron();
        }
        $notice = 'Queue resumed: ' . absint($processed) . ' recipient(s) attempted now; ' . absint($counts['sent']) . ' accepted in this run';
        if ($counts['error']) $notice .= ', ' . absint($counts['error']) . ' failed';
        $notice .= $remaining ? ', ' . absint($remaining) . ' waiting/retrying.' : '. Queue completed.';
        $error_notice = $counts['error'] ? absint($counts['error']) . ' recipient(s) still failed after resuming.' : '';
        $this->redirect_admin('wp-newslatter-campaigns-campaigns', $notice, $error_notice);
    }

    public function send_unsent_subscribers() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $id = absint($_POST['id'] ?? 0);
        check_admin_referer('wp_newslatter_campaigns_send_unsent_subscribers_' . $id);
        if (!$id) $this->redirect_admin('wp-newslatter-campaigns-campaigns', '', 'Invalid campaign.');
        $transport_check = $this->external_delivery_preflight();
        if (is_wp_error($transport_check)) $this->redirect_admin('wp-newslatter-campaigns-campaigns&edit=' . $id, '', $transport_check->get_error_message());

        global $wpdb;
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('campaigns')} WHERE id=%d", $id));
        if (!$campaign) $this->redirect_admin('wp-newslatter-campaigns-campaigns', '', 'Campaign not found.');
        $run_id = $this->latest_live_run_id($id);
        if ($run_id === '') $this->redirect_admin('wp-newslatter-campaigns-campaigns&edit=' . $id, '', 'No previous live recipient run is available.');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT q.id,q.subscriber_id,s.email
             FROM {$this->table('sent')} q
             INNER JOIN {$this->table('subscribers')} s ON s.id=q.subscriber_id
             WHERE q.campaign_id=%d AND q.run_id=%s
               AND q.status IN ('pending','retry','processing','error')
               AND s.status='subscribed' AND s.enabled=1 AND s.is_demo=0
             ORDER BY q.id ASC",
            $id,
            $run_id
        ));
        if (!$rows) $this->redirect_admin('wp-newslatter-campaigns-campaigns&edit=' . $id, '', 'No eligible unsent live subscribers are available.');

        $this->cancel_campaign_delivery_actions($id);
        $now = current_time('mysql');
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table('sent')} q
             LEFT JOIN {$this->table('subscribers')} s ON s.id=q.subscriber_id
             SET q.status='skipped',q.next_attempt=NULL,q.lock_token='',q.error='Subscriber is no longer active or eligible.',q.updated=%s
             WHERE q.campaign_id=%d AND q.run_id=%s
               AND q.status IN ('pending','retry','processing','error')
               AND (s.id IS NULL OR s.status<>'subscribed' OR s.enabled<>1 OR s.is_demo<>0)",
            $now,
            $id,
            $run_id
        ));
        $requeued = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table('sent')} q
             INNER JOIN {$this->table('subscribers')} s ON s.id=q.subscriber_id
             SET q.status='pending',q.attempts=0,q.next_attempt=NULL,q.sent_at=NULL,q.lock_token='',q.error='',q.updated=%s
             WHERE q.campaign_id=%d AND q.run_id=%s
               AND q.status IN ('pending','retry','processing','error')
               AND s.status='subscribed' AND s.enabled=1 AND s.is_demo=0",
            $now,
            $id,
            $run_id
        ));
        if ($requeued === false) $this->redirect_admin('wp-newslatter-campaigns-campaigns&edit=' . $id, '', 'The unsent recipient queue could not be rebuilt.');
        $eligible_count = count($rows);

        foreach ((array)$rows as $row) {
            $this->record_delivery_log(array(
                'campaign_id'=>$id,
                'subscriber_id'=>$row->subscriber_id,
                'run_id'=>$run_id,
                'recipient'=>$row->email,
                'delivery_type'=>'live',
                'status'=>'queued',
                'attempt'=>0,
                'delivery_id'=>'',
                'message_id'=>'',
                'response'=>'Manually requeued by Send to unsent subscribers. Previously successful recipients were excluded.',
            ));
        }

        $sent_count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table('sent')} WHERE campaign_id=%d AND run_id=%s AND status='sent'", $id, $run_id));
        $total_count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table('sent')} WHERE campaign_id=%d AND run_id=%s AND status<>'skipped'", $id, $run_id));
        $wpdb->update($this->table('campaigns'), array('status'=>'sending','total'=>$total_count,'sent'=>$sent_count,'updated'=>$now), array('id'=>$id));

        $settings = $this->settings();
        $capture_mode = in_array($this->mail_transport_status()['key'], array('capture-smtp','capture-api'), true);
        $first_limit = $capture_mode ? min(100, $eligible_count) : ($eligible_count <= 25 ? $eligible_count : min($eligible_count, max(1, absint($settings['send_batch_size']))));
        $this->process_live_run_immediately($id, $run_id, $first_limit);
        $counts = $this->live_run_status_counts($id, $run_id);
        $remaining = $counts['pending'] + $counts['retry'] + $counts['processing'];
        if ($remaining) {
            $this->schedule_live_burst($id, $run_id, time() + max(1, min(2, absint($settings['send_batch_interval']))));
            if (function_exists('spawn_cron')) spawn_cron();
        }
        $notice = 'Unsent retry: ' . absint($counts['sent'] - $sent_count) . ' newly accepted; ' . absint($remaining) . ' waiting/retrying';
        if ($counts['error']) $notice .= '; ' . absint($counts['error']) . ' failed';
        $notice .= '. Successfully sent subscribers were not included.';
        $error_notice = $counts['error'] ? absint($counts['error']) . ' recipient(s) failed during the unsent retry.' : '';
        $this->redirect_admin('wp-newslatter-campaigns-campaigns&edit=' . $id, $notice, $error_notice);
    }

    public function duplicate_campaign() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $id = absint($_GET['id'] ?? 0); check_admin_referer('wp_newslatter_campaigns_duplicate_' . $id); global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('campaigns')} WHERE id=%d", $id), ARRAY_A);
        if (!$row) $this->redirect_admin('wp-newslatter-campaigns-campaigns', '', 'Campaign not found.');
        unset($row['id']); $row['subject'] = 'Copy of ' . $row['subject']; $row['title'] = 'Copy of ' . $row['title']; $row['status'] = 'draft'; $row['sent'] = 0; $row['open_count'] = 0; $row['click_count'] = 0; $row['created'] = current_time('mysql'); $row['updated'] = current_time('mysql');
        $wpdb->insert($this->table('campaigns'), $row);
        $this->redirect_admin('wp-newslatter-campaigns-campaigns', 'Campaign duplicated');
    }

    private function current_user_can_reset_campaigns() {
        if (!is_user_logged_in() || !current_user_can('manage_options')) return false;
        $user = wp_get_current_user();
        if (!$user || empty($user->user_login)) return false;
        return in_array((string)$user->user_login, array('wp_raivis','wp_alan','wp_soniya'), true);
    }

    private function current_user_can_remove_subscribers() {
        if (!is_user_logged_in() || !current_user_can('manage_options')) return false;
        $user = wp_get_current_user();
        if (!$user || empty($user->user_login)) return false;
        $roles = is_array($user->roles) ? $user->roles : array();
        return in_array('administrator', $roles, true) && strpos((string)$user->user_login, 'wp_') === 0;
    }

    public function delete_subscriber_admin() {
        if (!$this->current_user_can_remove_subscribers()) wp_die('Forbidden');
        $id = absint($_GET['id'] ?? 0);
        check_admin_referer('wp_newslatter_campaigns_delete_subscriber_' . $id);
        $return_page = sanitize_key(wp_unslash($_GET['return_page'] ?? 'wp-newslatter-campaigns-subscribers'));
        if (!in_array($return_page, array('wp-newslatter-campaigns-subscribers','wp-newslatter-campaigns-demo-subscribers'), true)) $return_page = 'wp-newslatter-campaigns-subscribers';
        if (!$id) $this->redirect_admin($return_page, '', 'Invalid subscriber.');
        global $wpdb;
        $wpdb->delete($this->table('subscriber_lists'), array('subscriber_id' => $id), array('%d'));
        $deleted = $wpdb->delete($this->table('subscribers'), array('id' => $id), array('%d'));
        if ($deleted) $this->redirect_admin($return_page, 'Subscriber removed.');
        $this->redirect_admin($return_page, '', 'Subscriber was not found.');
    }

    private function cancel_campaign_delivery_actions($campaign_id) {
        global $wpdb;
        $campaign_id = absint($campaign_id);
        if (!$campaign_id) return;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,run_id FROM {$this->table('sent')} WHERE campaign_id=%d",
            $campaign_id
        ));
        $run_ids = array();
        foreach ((array)$rows as $row) {
            $run_id = sanitize_text_field((string)$row->run_id);
            if ($run_id !== '') $run_ids[$run_id] = true;
            $args = array($campaign_id, absint($row->id), $run_id);
            if ($this->action_scheduler_available() && function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions('wp_newslatter_campaigns_send_live_recipient', $args, 'wp-newslatter-campaigns-live');
            }
            wp_clear_scheduled_hook('wp_newslatter_campaigns_send_live_recipient', $args);
        }
        foreach (array_keys($run_ids) as $run_id) {
            $args = array($campaign_id, $run_id);
            if ($this->action_scheduler_available() && function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions('wp_newslatter_campaigns_process_live_burst', $args, 'wp-newslatter-campaigns-live');
            }
            wp_clear_scheduled_hook('wp_newslatter_campaigns_process_live_burst', $args);
        }
        if ($this->action_scheduler_available() && function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('wp_newslatter_campaigns_process_campaign_batch', array($campaign_id), 'wp-newslatter-campaigns');
        }
        wp_clear_scheduled_hook('wp_newslatter_campaigns_process_campaign_batch', array($campaign_id));
        $this->release_campaign_lock($campaign_id);
    }

    public function reset_campaign_to_draft() {
        if (!$this->current_user_can_reset_campaigns()) wp_die('Forbidden');
        $id = absint($_GET['id'] ?? 0);
        check_admin_referer('wp_newslatter_campaigns_reset_campaign_draft_' . $id);
        if (!$id) $this->redirect_admin('wp-newslatter-campaigns-campaigns', '', 'Invalid campaign.');
        global $wpdb;
        $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table('campaigns')} WHERE id=%d", $id));
        if (!$exists) $this->redirect_admin('wp-newslatter-campaigns-campaigns', '', 'Campaign not found.');
        $this->cancel_campaign_delivery_actions($id);
        $wpdb->delete($this->table('sent'), array('campaign_id'=>$id));
        $updated = $wpdb->update($this->table('campaigns'), array(
            'status'=>'draft',
            'total'=>0,
            'sent'=>0,
            'scheduled_at'=>null,
            'updated'=>current_time('mysql'),
        ), array('id'=>$id));
        $return_to = sanitize_key(wp_unslash($_GET['return_to'] ?? ''));
        $page = $return_to === 'edit' ? 'wp-newslatter-campaigns-campaigns&edit=' . $id : 'wp-newslatter-campaigns-campaigns';
        if ($updated === false) $this->redirect_admin($page, '', 'Campaign could not be reset to Draft.');
        $this->redirect_admin($page, 'Campaign reset to Draft. Pending live delivery was cancelled and send progress was cleared.');
    }

    public function delete_campaign() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $id = absint($_GET['id'] ?? 0); check_admin_referer('wp_newslatter_campaigns_delete_campaign_' . $id); global $wpdb;
        $this->cancel_campaign_delivery_actions($id);
        $wpdb->delete($this->table('campaigns'), array('id'=>$id));
        $wpdb->delete($this->table('sent'), array('campaign_id'=>$id));
        $wpdb->delete($this->table('events'), array('campaign_id'=>$id));
        $this->redirect_admin('wp-newslatter-campaigns-campaigns', 'Campaign deleted');
    }

    private function queue_campaign($campaign_id, $force_restart = false) {
        global $wpdb;
        $campaign_id = absint($campaign_id);
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('campaigns')} WHERE id=%d", $campaign_id));
        if (!$campaign) return new WP_Error('campaign_missing', 'Campaign not found.');
        if (trim((string)$campaign->subject) === '' || trim((string)$campaign->html) === '') return new WP_Error('campaign_incomplete', 'Campaign subject and HTML are required.');

        $sent_table = $this->table('sent');
        $subscribers_table = $this->table('subscribers');
        $in_progress = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$sent_table} WHERE campaign_id=%d AND status IN ('pending','retry','processing')", $campaign_id));
        if ($campaign->status === 'sending' && $in_progress && !$force_restart) {
            return new WP_Error('campaign_busy', 'This campaign already has an active delivery queue. Use Resume now or Send to unsent subscribers. A full new live send requires the restricted Reset to Draft action.');
        }

        $list_id = absint($campaign->list_id ?? 0);
        $audience_join = $list_id ? " INNER JOIN {$this->table('subscriber_lists')} sl ON sl.subscriber_id=s.id AND sl.list_id=" . $list_id : '';
        $active_total = (int)$wpdb->get_var("SELECT COUNT(DISTINCT s.id) FROM {$subscribers_table} s{$audience_join} WHERE s.status='subscribed' AND s.enabled=1 AND s.is_demo=0");
        if (!$active_total) return new WP_Error('no_recipients', 'No active subscribed recipients are available for ' . $this->list_label($list_id) . '.');

        $now = current_time('mysql');
        $run_id = wp_generate_uuid4();
        $wpdb->query('START TRANSACTION');
        try {
            // A new send is a completely fresh snapshot. Reusing old campaign rows
            // allowed stale run IDs and statuses to leave the final recipient out.
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$sent_table} WHERE campaign_id=%d", $campaign_id));
            if ($deleted === false) throw new RuntimeException('Could not reset the previous campaign recipient snapshot.');

            $sql = "INSERT INTO {$sent_table} (campaign_id,subscriber_id,status,attempts,next_attempt,created,updated,sent_at,run_id,lock_token,error)
                    SELECT %d,s.id,'pending',0,NULL,%s,%s,NULL,%s,'',''
                    FROM {$subscribers_table} s{$audience_join}
                    WHERE s.status='subscribed' AND s.enabled=1 AND s.is_demo=0
                    ORDER BY s.id ASC";
            $inserted = $wpdb->query($wpdb->prepare($sql, $campaign_id, $now, $now, $run_id));
            if ($inserted === false) throw new RuntimeException('Could not create the live recipient queue.');

            $snapshot_total = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$sent_table} WHERE campaign_id=%d AND run_id=%s AND status='pending'",
                $campaign_id,
                $run_id
            ));
            if ($snapshot_total !== $active_total) {
                throw new RuntimeException('Recipient snapshot mismatch: expected ' . $active_total . ', queued ' . $snapshot_total . '.');
            }

            $updated = $wpdb->update($this->table('campaigns'), array('status'=>'sending','total'=>$snapshot_total,'sent'=>0,'updated'=>$now), array('id'=>$campaign_id));
            if ($updated === false) throw new RuntimeException('Could not initialise the campaign send status.');
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('queue_snapshot_failed', sanitize_text_field($e->getMessage()));
        }

        $queued_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT q.subscriber_id,s.email FROM {$sent_table} q INNER JOIN {$subscribers_table} s ON s.id=q.subscriber_id WHERE q.campaign_id=%d AND q.run_id=%s AND q.status='pending' ORDER BY q.id ASC",
            $campaign_id,
            $run_id
        ));
        foreach ((array)$queued_rows as $queued_row) {
            $this->record_delivery_log(array('campaign_id'=>$campaign_id,'subscriber_id'=>$queued_row->subscriber_id,'run_id'=>$run_id,'recipient'=>$queued_row->email,'delivery_type'=>'live','status'=>'queued','attempt'=>0,'delivery_id'=>'','message_id'=>'','response'=>'Included in fresh live-send snapshot.'));
        }
        return array('count'=>$active_total, 'run_id'=>$run_id);
    }

    private function live_run_status_counts($campaign_id, $run_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status,COUNT(*) AS qty FROM {$this->table('sent')} WHERE campaign_id=%d AND run_id=%s GROUP BY status",
            absint($campaign_id),
            sanitize_text_field((string)$run_id)
        ), OBJECT_K);
        $counts = array('pending'=>0,'retry'=>0,'processing'=>0,'sent'=>0,'error'=>0,'skipped'=>0);
        foreach ((array)$rows as $status => $row) {
            if (array_key_exists($status, $counts)) $counts[$status] = (int)$row->qty;
        }
        return $counts;
    }

    private function process_live_run_immediately($campaign_id, $run_id, $limit = 25) {
        global $wpdb;
        $campaign_id = absint($campaign_id);
        $run_id = sanitize_text_field((string)$run_id);
        $limit = max(1, min(100, absint($limit)));
        if (!$campaign_id || $run_id === '') return 0;

        $settings = $this->settings();
        // Small sends are deliberately paced before wp_mail handoff while larger
        // lists continue through configured background bursts.
        $capture_mode = in_array($this->mail_transport_status()['key'], array('capture-smtp','capture-api'), true);
        $pause_ms = $capture_mode ? 1000 : max(0, min(2000, absint($settings['send_batch_pause_ms'])));
        $now = current_time('mysql');
        $stale = wp_date('Y-m-d H:i:s', time() - 60, wp_timezone());
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table('sent')} SET status='retry',next_attempt=%s,lock_token='',error='Recovered an interrupted immediate send.',updated=%s WHERE campaign_id=%d AND run_id=%s AND status='processing' AND updated<%s",
            $now,
            $now,
            $campaign_id,
            $run_id,
            $stale
        ));

        $processed = 0;
        $guard = 0;
        while ($processed < $limit && $guard < ($limit * 3)) {
            $guard++;
            $queue_id = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table('sent')} WHERE campaign_id=%d AND run_id=%s AND status IN ('pending','retry') AND (next_attempt IS NULL OR next_attempt<=%s) ORDER BY id ASC LIMIT 1",
                $campaign_id,
                $run_id,
                current_time('mysql')
            ));
            if (!$queue_id) break;

            // Never let an incorrectly finalised campaign block a valid pending row.
            $wpdb->query($wpdb->prepare("UPDATE {$this->table('campaigns')} SET status='sending',updated=%s WHERE id=%d AND status<>'sending'", current_time('mysql'), $campaign_id));
            try {
                $this->process_live_recipient($campaign_id, $queue_id, $run_id);
            } catch (Throwable $e) {
                $wpdb->update($this->table('sent'), array(
                    'status'=>'retry',
                    'next_attempt'=>wp_date('Y-m-d H:i:s', time() + 60, wp_timezone()),
                    'lock_token'=>'',
                    'error'=>'Immediate worker error: ' . sanitize_text_field($e->getMessage()),
                    'updated'=>current_time('mysql'),
                ), array('id'=>$queue_id,'campaign_id'=>$campaign_id,'run_id'=>$run_id));
            }

            $after = (string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$this->table('sent')} WHERE id=%d AND campaign_id=%d AND run_id=%s", $queue_id, $campaign_id, $run_id));
            if ($after === 'pending') {
                $wpdb->update($this->table('sent'), array(
                    'status'=>'retry',
                    'next_attempt'=>wp_date('Y-m-d H:i:s', time() + 30, wp_timezone()),
                    'error'=>'Recipient worker did not claim this queue row; automatic retry scheduled.',
                    'updated'=>current_time('mysql'),
                ), array('id'=>$queue_id,'campaign_id'=>$campaign_id,'run_id'=>$run_id));
            }
            $processed++;
            if ($pause_ms > 0 && $processed < $limit) usleep($pause_ms * 1000);
        }
        $this->finalize_live_campaign($campaign_id, $run_id);
        return $processed;
    }

    private function latest_live_run_id($campaign_id) {
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare(
            "SELECT run_id FROM {$this->table('sent')} WHERE campaign_id=%d AND run_id<>'' ORDER BY updated DESC,id DESC LIMIT 1",
            absint($campaign_id)
        ));
    }

    private function live_run_pending_count($campaign_id, $run_id) {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table('sent')} WHERE campaign_id=%d AND run_id=%s AND status IN ('pending','retry','processing')",
            absint($campaign_id),
            sanitize_text_field((string)$run_id)
        ));
    }

    private function live_burst_schedule_key($campaign_id, $run_id) {
        return 'wpnc_live_burst_' . absint($campaign_id) . '_' . substr(md5((string)$run_id), 0, 16);
    }

    private function schedule_live_burst($campaign_id, $run_id, $timestamp = 0) {
        $campaign_id = absint($campaign_id);
        $run_id = sanitize_text_field((string)$run_id);
        if (!$campaign_id || $run_id === '') return false;
        $timestamp = max(time() + 1, absint($timestamp ?: time() + 1));
        $args = array($campaign_id, $run_id);
        if ($this->action_scheduler_available()) {
            if ($timestamp <= time() + 2 && function_exists('as_enqueue_async_action')) {
                return (bool)as_enqueue_async_action('wp_newslatter_campaigns_process_live_burst', $args, 'wp-newslatter-campaigns-live', false);
            }
            return (bool)as_schedule_single_action($timestamp, 'wp_newslatter_campaigns_process_live_burst', $args, 'wp-newslatter-campaigns-live', false);
        }
        if (!wp_next_scheduled('wp_newslatter_campaigns_process_live_burst', $args)) {
            $scheduled = (bool)wp_schedule_single_event($timestamp, 'wp_newslatter_campaigns_process_live_burst', $args, true);
        } else {
            $scheduled = true;
        }
        if ($scheduled && function_exists('spawn_cron')) spawn_cron();
        return $scheduled;
    }

    // Backward-compatible helper retained for older calls. One smart burst action
    // replaces one separate cron action per recipient, avoiding queues where only
    // the first recipient runs and the rest wait indefinitely.
    private function schedule_live_recipient_jobs($campaign_id, $run_id = '') {
        $campaign_id = absint($campaign_id);
        $run_id = sanitize_text_field((string)$run_id);
        if (!$campaign_id) return 0;
        if ($run_id === '') $run_id = $this->latest_live_run_id($campaign_id);
        if ($run_id === '') return 0;
        $pending = $this->live_run_pending_count($campaign_id, $run_id);
        if ($pending) $this->schedule_live_burst($campaign_id, $run_id, time() + 1);
        return $pending;
    }

    public function process_live_burst($campaign_id = 0, $run_id = '') {
        global $wpdb;
        $campaign_id = absint($campaign_id);
        $run_id = sanitize_text_field((string)$run_id);
        if (!$campaign_id) return 0;
        if ($run_id === '') $run_id = $this->latest_live_run_id($campaign_id);
        if ($run_id === '' || !$this->acquire_campaign_lock($campaign_id)) return 0;

        $processed = 0;
        try {
            $campaign_status = (string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$this->table('campaigns')} WHERE id=%d", $campaign_id));
            if ($campaign_status !== 'sending') {
                if (!$this->live_run_pending_count($campaign_id, $run_id)) return 0;
                $wpdb->update($this->table('campaigns'), array('status'=>'sending','updated'=>current_time('mysql')), array('id'=>$campaign_id));
            }

            $settings = $this->settings();
            $batch_size = max(1, min(100, absint($settings['send_batch_size'])));
            $hourly_limit = max(0, absint($settings['send_hourly_limit']));
            if ($hourly_limit) {
                $since = wp_date('Y-m-d H:i:s', time() - HOUR_IN_SECONDS, wp_timezone());
                $sent_last_hour = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table('delivery_logs')} WHERE delivery_type='live' AND status='accepted' AND created>=%s", $since));
                $batch_size = min($batch_size, max(0, $hourly_limit - $sent_last_hour));
                if ($batch_size < 1) {
                    $this->schedule_live_burst($campaign_id, $run_id, time() + 60);
                    return 0;
                }
            }

            $now = current_time('mysql');
            $stale = wp_date('Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS, wp_timezone());
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table('sent')} SET status='retry',next_attempt=%s,lock_token='',error='Recovered a stalled delivery attempt.',updated=%s WHERE campaign_id=%d AND run_id=%s AND status='processing' AND updated<%s",
                $now,
                $now,
                $campaign_id,
                $run_id,
                $stale
            ));

            $queue_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$this->table('sent')} WHERE campaign_id=%d AND run_id=%s AND status IN ('pending','retry') AND (next_attempt IS NULL OR next_attempt<=%s) ORDER BY id ASC LIMIT %d",
                $campaign_id,
                $run_id,
                $now,
                $batch_size
            ));

            foreach ((array)$queue_ids as $queue_id) {
                try {
                    $this->process_live_recipient($campaign_id, absint($queue_id), $run_id);
                    $processed++;
                } catch (Throwable $e) {
                    $wpdb->update($this->table('sent'), array(
                        'status'=>'retry',
                        'next_attempt'=>wp_date('Y-m-d H:i:s', time() + 60, wp_timezone()),
                        'lock_token'=>'',
                        'error'=>'Live burst error: ' . sanitize_text_field($e->getMessage()),
                        'updated'=>current_time('mysql'),
                    ), array('id'=>absint($queue_id)));
                }
                // A very small pause protects shared SMTP without turning a small
                // live send into a minutes-long queue.
                $pause_ms = max(0, min(2000, absint($settings['send_batch_pause_ms'])));
                if ($pause_ms > 0 && count($queue_ids) > 1) usleep($pause_ms * 1000);
            }
        } finally {
            $this->release_campaign_lock($campaign_id);
        }

        $remaining = $this->live_run_pending_count($campaign_id, $run_id);
        if ($remaining) {
            $next_due = $wpdb->get_var($wpdb->prepare(
                "SELECT MIN(next_attempt) FROM {$this->table('sent')} WHERE campaign_id=%d AND run_id=%s AND status='retry' AND next_attempt IS NOT NULL",
                $campaign_id,
                $run_id
            ));
            $delay = max(2, absint($settings['send_batch_interval']));
            if ($next_due) {
                $next_due_gmt = get_gmt_from_date($next_due);
                $delay = max($delay, strtotime($next_due_gmt . ' UTC') - time());
            }
            $this->schedule_live_burst($campaign_id, $run_id, time() + max(1, $delay));
        }
        $this->finalize_live_campaign($campaign_id, $run_id);
        return $processed;
    }

    private function finalize_live_campaign($campaign_id, $run_id = '') {
        global $wpdb;
        $campaign_id = absint($campaign_id);
        $run_id = sanitize_text_field((string)$run_id);
        if (!$campaign_id) return;
        if ($run_id === '') $run_id = $this->latest_live_run_id($campaign_id);
        if ($run_id === '') return;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status,COUNT(*) AS qty FROM {$this->table('sent')} WHERE campaign_id=%d AND run_id=%s GROUP BY status",
            $campaign_id,
            $run_id
        ), OBJECT_K);
        $counts = array('pending'=>0,'retry'=>0,'processing'=>0,'sent'=>0,'error'=>0,'skipped'=>0);
        foreach ((array)$rows as $status => $row) $counts[$status] = (int)$row->qty;
        $pending = $counts['pending'] + $counts['retry'] + $counts['processing'];
        $total = $pending + $counts['sent'] + $counts['error'];
        $status = $pending ? 'sending' : ($counts['error'] ? 'error' : 'sent');
        $wpdb->update($this->table('campaigns'), array(
            'status'=>$status,
            'total'=>$total,
            'sent'=>$counts['sent'],
            'updated'=>current_time('mysql'),
        ), array('id'=>$campaign_id));
    }

    private function schedule_live_retry($campaign_id, $queue_id, $run_id, $timestamp) {
        $args = array(absint($campaign_id), absint($queue_id), sanitize_text_field((string)$run_id));
        $timestamp = max(time() + 1, absint($timestamp));
        if ($this->action_scheduler_available()) {
            return (bool)as_schedule_single_action($timestamp, 'wp_newslatter_campaigns_send_live_recipient', $args, 'wp-newslatter-campaigns-live', false);
        }
        return (bool)wp_schedule_single_event($timestamp, 'wp_newslatter_campaigns_send_live_recipient', $args, true);
    }

    public function process_live_recipient($campaign_id = 0, $queue_id = 0, $run_id = '') {
        global $wpdb;
        $campaign_id = absint($campaign_id);
        $queue_id = absint($queue_id);
        $run_id = sanitize_text_field((string)$run_id);
        if (!$campaign_id || !$queue_id || $run_id === '') return;

        $transport_check = $this->external_delivery_preflight();
        if (is_wp_error($transport_check)) {
            $error = $transport_check->get_error_message();
            $wpdb->update($this->table('sent'), array('status'=>'error','next_attempt'=>null,'lock_token'=>'','error'=>$error,'updated'=>current_time('mysql')), array('id'=>$queue_id));
            $this->record_delivery_log(array('campaign_id'=>$campaign_id,'run_id'=>$run_id,'delivery_type'=>'live','status'=>'failed','attempt'=>0,'response'=>$error));
            $this->finalize_live_campaign($campaign_id, $run_id);
            return;
        }

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT q.*,s.email,s.first_name,s.last_name,s.token,s.status AS subscriber_status,s.enabled,s.is_demo,c.subject,c.html,c.status AS campaign_status
             FROM {$this->table('sent')} q
             LEFT JOIN {$this->table('subscribers')} s ON s.id=q.subscriber_id
             LEFT JOIN {$this->table('campaigns')} c ON c.id=q.campaign_id
             WHERE q.id=%d AND q.campaign_id=%d AND q.run_id=%s",
            $queue_id,
            $campaign_id,
            $run_id
        ));
        if (!$item || in_array($item->status, array('sent','error','skipped'), true)) return;

        if (!$item->subject && !$item->html) return;
        if ($item->campaign_status !== 'sending') {
            $wpdb->update($this->table('campaigns'), array('status'=>'sending','updated'=>current_time('mysql')), array('id'=>$campaign_id));
        }
        if (!$item->email || !is_email($item->email) || $item->subscriber_status !== 'subscribed' || (int)$item->enabled !== 1 || (int)$item->is_demo !== 0) {
            $wpdb->update($this->table('sent'), array('status'=>'skipped','next_attempt'=>null,'lock_token'=>'','error'=>'Subscriber is no longer active or eligible.','updated'=>current_time('mysql')), array('id'=>$queue_id));
            $this->record_delivery_log(array('campaign_id'=>$campaign_id,'subscriber_id'=>$item->subscriber_id,'run_id'=>$run_id,'recipient'=>$item->email,'delivery_type'=>'live','status'=>'skipped','attempt'=>$item->attempts,'delivery_id'=>'','message_id'=>'','response'=>'Subscriber is no longer active or eligible.'));
            $this->finalize_live_campaign($campaign_id, $run_id);
            return;
        }
        if (!empty($item->next_attempt)) {
            $next_gmt = get_gmt_from_date($item->next_attempt);
            $next_timestamp = strtotime($next_gmt . ' UTC');
            if ($next_timestamp > time() + 2) {
                $this->schedule_live_retry($campaign_id, $queue_id, $run_id, $next_timestamp);
                return;
            }
        }

        $settings = $this->settings();
        $max_attempts = 1 + max(0, min(10, absint($settings['send_max_retries'])));
        if ((int)$item->attempts >= $max_attempts) {
            $wpdb->update($this->table('sent'), array('status'=>'error','next_attempt'=>null,'lock_token'=>'','error'=>'Maximum delivery attempts reached.','updated'=>current_time('mysql')), array('id'=>$queue_id));
            $this->finalize_live_campaign($campaign_id, $run_id);
            return;
        }

        $lock_token = wp_generate_uuid4();
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table('sent')} SET status='processing',attempts=attempts+1,lock_token=%s,updated=%s WHERE id=%d AND campaign_id=%d AND run_id=%s AND status IN ('pending','retry')",
            $lock_token,
            current_time('mysql'),
            $queue_id,
            $campaign_id,
            $run_id
        ));
        if (!$claimed) return;

        $attempt = (int)$item->attempts + 1;
        $subscriber = (object)array('id'=>absint($item->subscriber_id),'email'=>$item->email,'first_name'=>$item->first_name,'last_name'=>$item->last_name,'token'=>$item->token);
        $campaign = (object)array('id'=>$campaign_id,'subject'=>$item->subject,'html'=>$item->html);
        $this->record_delivery_log(array('campaign_id'=>$campaign_id,'subscriber_id'=>$subscriber->id,'run_id'=>$run_id,'recipient'=>$subscriber->email,'delivery_type'=>'live','status'=>'processing','attempt'=>$attempt,'delivery_id'=>'','message_id'=>'','response'=>'Sequential live-recipient handoff to the WordPress mail system started.'));
        $html = $this->prepare_live_delivery_html($campaign, $subscriber);
        $ok = $this->send_live_html_mail($subscriber->email, $campaign->subject, $html, $this->live_transport_subscriber_context($subscriber));
        if ($ok) {
            $wpdb->update($this->table('sent'), array('status'=>'sent','sent_at'=>current_time('mysql'),'updated'=>current_time('mysql'),'next_attempt'=>null,'lock_token'=>'','error'=>''), array('id'=>$queue_id));
            $this->record_delivery_log(array('campaign_id'=>$campaign_id,'subscriber_id'=>$subscriber->id,'run_id'=>$run_id,'recipient'=>$subscriber->email,'delivery_type'=>'live','status'=>'accepted','attempt'=>$attempt,'response'=>$this->last_mail_response));
        } else {
            $error = $this->last_mail_error ?: 'WordPress wp_mail reported failure.';
            if ($attempt < $max_attempts) {
                $retry_delay = max(60, absint($settings['send_retry_delay'])) * max(1, $attempt);
                $next_timestamp = time() + $retry_delay;
                $next = wp_date('Y-m-d H:i:s', $next_timestamp, wp_timezone());
                $wpdb->update($this->table('sent'), array('status'=>'retry','next_attempt'=>$next,'updated'=>current_time('mysql'),'lock_token'=>'','error'=>$error), array('id'=>$queue_id));
                $this->record_delivery_log(array('campaign_id'=>$campaign_id,'subscriber_id'=>$subscriber->id,'run_id'=>$run_id,'recipient'=>$subscriber->email,'delivery_type'=>'live','status'=>'retry','attempt'=>$attempt,'response'=>$error . ' Next attempt: ' . $next));
                $this->schedule_live_retry($campaign_id, $queue_id, $run_id, $next_timestamp);
            } else {
                $wpdb->update($this->table('sent'), array('status'=>'error','next_attempt'=>null,'updated'=>current_time('mysql'),'lock_token'=>'','error'=>$error), array('id'=>$queue_id));
                $this->record_delivery_log(array('campaign_id'=>$campaign_id,'subscriber_id'=>$subscriber->id,'run_id'=>$run_id,'recipient'=>$subscriber->email,'delivery_type'=>'live','status'=>'failed','attempt'=>$attempt,'response'=>$error));
            }
        }
        $this->fire_webhooks('send', array('campaign_id'=>$campaign_id,'subscriber_id'=>$subscriber->id,'email'=>$subscriber->email,'ok'=>$ok,'attempt'=>$attempt,'transport'=>$this->mail_transport_status()['key']));
        $this->finalize_live_campaign($campaign_id, $run_id);
    }

    private function schedule_campaign_worker($campaign_id, $delay = 1, $force = false) {
        $campaign_id = absint($campaign_id);
        if (!$campaign_id) return false;
        $delay = max(1, absint($delay));
        $args = array($campaign_id);
        if ($this->action_scheduler_available()) {
            if (!$force && function_exists('as_has_scheduled_action') && as_has_scheduled_action('wp_newslatter_campaigns_process_campaign_batch', $args, 'wp-newslatter-campaigns')) return true;
            return (bool)as_schedule_single_action(time() + $delay, 'wp_newslatter_campaigns_process_campaign_batch', $args, 'wp-newslatter-campaigns', !$force);
        }
        if (!wp_next_scheduled('wp_newslatter_campaigns_process_campaign_batch', $args)) {
            return wp_schedule_single_event(time() + $delay, 'wp_newslatter_campaigns_process_campaign_batch', $args, true);
        }
        return true;
    }

    private function acquire_campaign_lock($campaign_id) {
        $key = 'wp_newslatter_campaigns_campaign_lock_' . absint($campaign_id);
        $existing = (int)get_option($key, 0);
        if ($existing && $existing > time() - 15 * MINUTE_IN_SECONDS) return false;
        if ($existing) delete_option($key);
        return add_option($key, time(), '', false);
    }

    private function release_campaign_lock($campaign_id) {
        delete_option('wp_newslatter_campaigns_campaign_lock_' . absint($campaign_id));
    }

    private function campaign_queue_counts($campaign_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT status,COUNT(*) AS qty FROM {$this->table('sent')} WHERE campaign_id=%d GROUP BY status", $campaign_id), OBJECT_K);
        $counts = array('pending'=>0,'retry'=>0,'processing'=>0,'sent'=>0,'error'=>0,'skipped'=>0);
        foreach ((array)$rows as $status => $row) $counts[$status] = (int)$row->qty;
        return $counts;
    }

    public function process_campaign_batch($campaign_id = 0) {
        global $wpdb;
        $campaign_id = absint($campaign_id);
        if (!$campaign_id) {
            $campaign_id = (int)$wpdb->get_var("SELECT id FROM {$this->table('campaigns')} WHERE status='sending' ORDER BY id ASC LIMIT 1");
        }
        if (!$campaign_id || !$this->acquire_campaign_lock($campaign_id)) return;
        try {
            $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('campaigns')} WHERE id=%d", $campaign_id));
            if (!$campaign || $campaign->status !== 'sending') return;
            if (!(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table('sent')} WHERE campaign_id=%d", $campaign_id))) {
                $queued = $this->queue_campaign($campaign_id);
                if (is_wp_error($queued)) {
                    $wpdb->update($this->table('campaigns'), array('status'=>'error','updated'=>current_time('mysql')), array('id'=>$campaign_id));
                    return;
                }
            }
            $settings = $this->settings();
            $now = current_time('mysql');
            $stale = wp_date('Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS, wp_timezone());
            $wpdb->query($wpdb->prepare("UPDATE {$this->table('sent')} SET status='retry',next_attempt=%s,updated=%s,error='Recovered an interrupted queue item.' WHERE campaign_id=%d AND status='processing' AND updated<%s", $now, $now, $campaign_id, $stale));
            $wpdb->query($wpdb->prepare("UPDATE {$this->table('sent')} q LEFT JOIN {$this->table('subscribers')} s ON s.id=q.subscriber_id SET q.status='skipped',q.updated=%s,q.error='Subscriber is no longer active or eligible.' WHERE q.campaign_id=%d AND q.status IN ('pending','retry') AND (s.id IS NULL OR s.status<>'subscribed' OR s.enabled<>1 OR s.is_demo<>0)", $now, $campaign_id));

            $batch_size = max(1, min(100, absint($settings['send_batch_size'])));
            $hourly_limit = max(0, absint($settings['send_hourly_limit']));
            if ($hourly_limit) {
                $since = wp_date('Y-m-d H:i:s', time() - HOUR_IN_SECONDS, wp_timezone());
                $last_hour = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table('sent')} WHERE status='sent' AND sent_at>=%s", $since));
                $batch_size = min($batch_size, max(0, $hourly_limit - $last_hour));
                if ($batch_size < 1) {
                    $this->schedule_campaign_worker($campaign_id, max(60, absint($settings['send_batch_interval'])), true);
                    return;
                }
            }

            $max_attempts = 1 + max(0, min(10, absint($settings['send_max_retries'])));
            $wpdb->query($wpdb->prepare("UPDATE {$this->table('sent')} SET status='error',updated=%s,error=IF(error IS NULL OR error='', 'Maximum delivery attempts reached.', error) WHERE campaign_id=%d AND status IN ('pending','retry') AND attempts>=%d", $now, $campaign_id, $max_attempts));
            $items = $wpdb->get_results($wpdb->prepare("SELECT q.*,s.email,s.first_name,s.last_name,s.token FROM {$this->table('sent')} q INNER JOIN {$this->table('subscribers')} s ON s.id=q.subscriber_id WHERE q.campaign_id=%d AND q.status IN ('pending','retry') AND q.attempts<%d AND (q.next_attempt IS NULL OR q.next_attempt<=%s) ORDER BY q.id ASC LIMIT %d", $campaign_id, $max_attempts, $now, $batch_size));

            foreach ((array)$items as $item) {
                $lock_token = wp_generate_uuid4();
                $claimed = $wpdb->query($wpdb->prepare("UPDATE {$this->table('sent')} SET status='processing',attempts=attempts+1,lock_token=%s,updated=%s WHERE id=%d AND status IN ('pending','retry')", $lock_token, $now, $item->id));
                if (!$claimed) continue;
                $queue_id = absint($item->id);
                $subscriber = (object)array('id'=>absint($item->subscriber_id),'email'=>$item->email,'first_name'=>$item->first_name,'last_name'=>$item->last_name,'token'=>$item->token);
                $attempt = (int)$item->attempts + 1;
                $this->record_delivery_log(array('campaign_id'=>$campaign_id,'subscriber_id'=>$subscriber->id,'run_id'=>$item->run_id,'recipient'=>$subscriber->email,'delivery_type'=>'live','status'=>'processing','attempt'=>$attempt,'delivery_id'=>'','message_id'=>'','response'=>'Background worker started this recipient handoff to the WordPress mail system.'));
                $html = $this->prepare_live_delivery_html($campaign, $subscriber);
                $ok = $this->send_live_html_mail($subscriber->email, $campaign->subject, $html, $this->live_transport_subscriber_context($subscriber));
                if ($ok) {
                    $wpdb->update($this->table('sent'), array('status'=>'sent','sent_at'=>current_time('mysql'),'updated'=>current_time('mysql'),'next_attempt'=>null,'lock_token'=>'','error'=>''), array('id'=>$queue_id));
                    $this->record_delivery_log(array('campaign_id'=>$campaign_id,'subscriber_id'=>$subscriber->id,'run_id'=>$item->run_id,'recipient'=>$subscriber->email,'delivery_type'=>'live','status'=>'accepted','attempt'=>$attempt,'response'=>$this->last_mail_response));
                } else {
                    $error = $this->last_mail_error ?: 'WordPress wp_mail reported failure.';
                    if ($attempt < $max_attempts) {
                        $retry_delay = max(60, absint($settings['send_retry_delay'])) * max(1, $attempt);
                        $next = wp_date('Y-m-d H:i:s', time() + $retry_delay, wp_timezone());
                        $wpdb->update($this->table('sent'), array('status'=>'retry','next_attempt'=>$next,'updated'=>current_time('mysql'),'lock_token'=>'','error'=>$error), array('campaign_id'=>$campaign_id,'subscriber_id'=>$subscriber->id));
                        $this->record_delivery_log(array('campaign_id'=>$campaign_id,'subscriber_id'=>$subscriber->id,'run_id'=>$item->run_id,'recipient'=>$subscriber->email,'delivery_type'=>'live','status'=>'retry','attempt'=>$attempt,'response'=>$error . ' Next attempt: ' . $next));
                    } else {
                        $wpdb->update($this->table('sent'), array('status'=>'error','next_attempt'=>null,'updated'=>current_time('mysql'),'lock_token'=>'','error'=>$error), array('campaign_id'=>$campaign_id,'subscriber_id'=>$subscriber->id));
                        $this->record_delivery_log(array('campaign_id'=>$campaign_id,'subscriber_id'=>$subscriber->id,'run_id'=>$item->run_id,'recipient'=>$subscriber->email,'delivery_type'=>'live','status'=>'failed','attempt'=>$attempt,'response'=>$error));
                    }
                }
                $this->fire_webhooks('send', array('campaign_id'=>$campaign_id,'subscriber_id'=>$subscriber->id,'email'=>$subscriber->email,'ok'=>$ok,'attempt'=>$attempt,'transport'=>$this->mail_transport_status()['key']));
            }

            $counts = $this->campaign_queue_counts($campaign_id);
            $total = $counts['pending'] + $counts['retry'] + $counts['processing'] + $counts['sent'] + $counts['error'];
            $pending = $counts['pending'] + $counts['retry'] + $counts['processing'];
            $status = $pending ? 'sending' : ($counts['error'] ? 'error' : 'sent');
            $wpdb->update($this->table('campaigns'), array('status'=>$status,'total'=>$total,'sent'=>$counts['sent'],'updated'=>current_time('mysql')), array('id'=>$campaign_id));
            if ($pending) {
                $next_due = $wpdb->get_var($wpdb->prepare("SELECT MIN(next_attempt) FROM {$this->table('sent')} WHERE campaign_id=%d AND status='retry' AND next_attempt IS NOT NULL", $campaign_id));
                $delay = max(15, absint($settings['send_batch_interval']));
                if (!$items && $next_due) {
                    $next_due_gmt = get_gmt_from_date($next_due);
                    $delay = max($delay, strtotime($next_due_gmt . ' UTC') - time());
                }
                $this->schedule_campaign_worker($campaign_id, $delay, true);
            }
        } finally {
            $this->release_campaign_lock($campaign_id);
        }
    }

    public function cron_send() {
        global $wpdb;
        $this->run_due_automations();
        $campaign_id = (int)$wpdb->get_var("SELECT id FROM {$this->table('campaigns')} WHERE status='sending' ORDER BY id ASC LIMIT 1");
        if ($campaign_id) {
            $run_id = $this->latest_live_run_id($campaign_id);
            if ($run_id !== '') $this->process_live_burst($campaign_id, $run_id);
        }
    }

    public function prepare_email_html($html, $sub, $campaign) {
        $html = $this->personalize((string)$html, $sub, $campaign);
        $html = $this->wrap_links_for_tracking($html, (int)$campaign->id, (int)$sub->id);
        return $html;
    }

    public function personalize($html, $sub, $campaign) {
        return str_replace(array('{name}','{first_name}','{surname}','{last_name}','{email_subject}','{email}'), array($sub->first_name, $sub->first_name, $sub->last_name, $sub->last_name, $campaign->subject, $sub->email), (string)$html);
    }

    private function wrap_links_for_tracking($html, $campaign_id, $subscriber_id) {
        return preg_replace_callback('/href=(["\'])(.*?)\1/i', function($m) use ($campaign_id, $subscriber_id) {
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($url === '' || preg_match('/^(mailto:|tel:|#|data:)/i', $url)) return $m[0];
            $s = $this->settings();
            $tracked_url = add_query_arg(array('utm_source'=>$s['ga_utm_source'],'utm_medium'=>$s['ga_utm_medium'],'utm_campaign'=>$s['ga_utm_campaign']), $url);
            $redirect = add_query_arg(array('wpnc_nl_click'=>1,'c'=>$campaign_id,'s'=>$subscriber_id,'u'=>rawurlencode($tracked_url)), home_url('/'));
            return 'href=' . $m[1] . esc_url($redirect) . $m[1];
        }, $html);
    }

    public function tracking_pixel($campaign_id, $subscriber_id) {
        return '<img src="' . esc_url(add_query_arg(array('wpnc_nl_open'=>1,'c'=>$campaign_id,'s'=>$subscriber_id), home_url('/'))) . '" width="1" height="1" alt="">';
    }

    public function maybe_track() {
        if (!isset($_GET['wpnc_nl_open'])) return;
        global $wpdb;
        $c = absint($_GET['c'] ?? 0); $s = absint($_GET['s'] ?? 0);
        $wpdb->insert($this->table('events'), array('campaign_id'=>$c,'subscriber_id'=>$s,'event_type'=>'open','ip'=>$this->ip(),'created'=>current_time('mysql')));
        if ($c) $wpdb->query($wpdb->prepare("UPDATE {$this->table('campaigns')} SET open_count=open_count+1 WHERE id=%d", $c));
        $this->fire_webhooks('open', array('campaign_id'=>$c, 'subscriber_id'=>$s));
        header('Content-Type:image/gif'); echo base64_decode('R0lGODlhAQABAPAAAP///wAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw=='); exit;
    }

    public function maybe_click() {
        if (!isset($_GET['wpnc_nl_click'])) return;
        global $wpdb;
        $c = absint($_GET['c'] ?? 0); $s = absint($_GET['s'] ?? 0); $url = esc_url_raw(rawurldecode(wp_unslash($_GET['u'] ?? '')));
        $wpdb->insert($this->table('events'), array('campaign_id'=>$c,'subscriber_id'=>$s,'event_type'=>'click','url'=>$url,'ip'=>$this->ip(),'created'=>current_time('mysql')));
        if ($c) $wpdb->query($wpdb->prepare("UPDATE {$this->table('campaigns')} SET click_count=click_count+1 WHERE id=%d", $c));
        $this->fire_webhooks('click', array('campaign_id'=>$c, 'subscriber_id'=>$s, 'url'=>$url));
        wp_safe_redirect($url ?: home_url('/')); exit;
    }

    public function fire_webhooks($event, $payload) {
        global $wpdb;
        $s = $this->settings();
        $urls = array_filter(array_map('trim', explode("\n", $s['webhook_urls'])));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table('webhooks')} WHERE enabled=1 AND event=%s", $event));
        foreach ((array)$rows as $row) $urls[] = $row->url;
        foreach (array_unique($urls) as $url) {
            wp_remote_post($url, array('timeout'=>5, 'body'=>array('event'=>$event, 'payload'=>wp_json_encode($payload))));
        }
    }

    public function save_webhook() {
        if (!current_user_can('manage_options')) wp_die('Forbidden'); check_admin_referer('wp_newslatter_campaigns_save_webhook'); global $wpdb;
        $wpdb->insert($this->table('webhooks'), array('name'=>sanitize_text_field(wp_unslash($_POST['name'] ?? '')),'event'=>sanitize_key(wp_unslash($_POST['event'] ?? 'subscribe')),'url'=>esc_url_raw(wp_unslash($_POST['url'] ?? '')),'enabled'=>!empty($_POST['enabled'])?1:0,'created'=>current_time('mysql')));
        $this->redirect_admin('wp-newslatter-campaigns-webhooks', 'Webhook saved');
    }

    public function delete_webhook() {
        if (!current_user_can('manage_options')) wp_die('Forbidden'); $id = absint($_GET['id'] ?? 0); check_admin_referer('wp_newslatter_campaigns_delete_webhook_' . $id); global $wpdb;
        $wpdb->delete($this->table('webhooks'), array('id'=>$id));
        $this->redirect_admin('wp-newslatter-campaigns-webhooks', 'Webhook deleted');
    }

    public function save_automation() {
        if (!current_user_can('manage_options')) wp_die('Forbidden'); check_admin_referer('wp_newslatter_campaigns_save_automation'); global $wpdb;
        $wpdb->insert($this->table('automations'), array('name'=>sanitize_text_field(wp_unslash($_POST['name'] ?? '')),'enabled'=>!empty($_POST['enabled'])?1:0,'frequency'=>sanitize_key(wp_unslash($_POST['frequency'] ?? 'weekly')),'subject'=>sanitize_text_field(wp_unslash($_POST['subject'] ?? '')),'intro'=>wp_unslash($_POST['intro'] ?? ''),'post_count'=>absint($_POST['post_count'] ?? 5),'created'=>current_time('mysql'),'updated'=>current_time('mysql')));
        $this->redirect_admin('wp-newslatter-campaigns-automations', 'Automation saved');
    }


    public function addons_catalog() {
        return array(
            'core' => array('title'=>'Core newsletter system','desc'=>'Unlimited subscribers, HTML campaigns, WordPress wp_mail/GD Mail Queue-compatible delivery, Action Scheduler/WP-Cron throttling, retries, tracking, and one-click unsubscribe.'),
            'addons-manager' => array('title'=>'Addons Manager','desc'=>'Single screen showing native replacement modules and which integrations are available.'),
            'automated' => array('title'=>'Automated Newsletters','desc'=>'Daily, weekly, or monthly latest-post campaigns generated through WP-Cron.'),
            'bounce' => array('title'=>'Bounce Addon','desc'=>'Manual bounce paste/import with subscriber status updates and bounce log.'),
            'contact-form-7' => array('title'=>'Contact Form 7 Integration','desc'=>'Captures opted-in CF7 submissions when CF7 is installed.'),
            'google-analytics' => array('title'=>'Google Analytics','desc'=>'UTM source, medium, and campaign parameters are appended to tracked links.'),
            'import-export' => array('title'=>'Import / Export','desc'=>'CSV subscriber import/export plus complete JSON campaign import/export.'),
            'reports-retargeting' => array('title'=>'Reports and Retargeting','desc'=>'Campaign open/click reports, recent event view, and tracked-link event storage.'),
            'webhooks' => array('title'=>'Webhooks','desc'=>'Event webhooks for subscribe, send, open, and click.'),
            'woocommerce' => array('title'=>'WooCommerce','desc'=>'Checkout opt-in capture when WooCommerce is active.'),
            'wp-users' => array('title'=>'WP Users Addon','desc'=>'New WordPress user capture.'),
            'campaign-upload' => array('title'=>'Campaign Upload','desc'=>'Mail Designer ZIP import, HTML cleanup, versioned file manager, desktop/mobile preview, and draft campaign creation.'),
        );
    }

    private function addon_badges() {
        $out = '<div class="wpnc-addon-badges">';
        foreach ($this->addons_catalog() as $key => $addon) $out .= '<span>' . esc_html($addon['title']) . '</span>';
        return $out . '</div>';
    }

    public function page_addons() {
        $this->admin_wrap_start(__('Addons Manager', 'wp-newslatter-campaigns'));
        echo '<p>This screen replaces the old Newsletter Addons Manager with native WP modules. Modules are built into this plugin, so there is no extra premium add-on stack to install.</p>';
        echo '<div class="wpnc-addon-grid">';
        foreach ($this->addons_catalog() as $key => $addon) {
            echo '<div class="wpnc-addon-card"><div class="wpnc-addon-status">Active</div><h2>' . esc_html($addon['title']) . '</h2><p>' . esc_html($addon['desc']) . '</p><code>' . esc_html($key) . '</code></div>';
        }
        echo '</div>';
        $this->admin_wrap_end();
    }

    public function page_bounces() {
        global $wpdb;
        $this->admin_wrap_start(__('Bounces', 'wp-newslatter-campaigns'));
        echo '<div class="wpnc-card"><h2>Process bounce list</h2><p>Paste bounce messages or one email per line. Matching subscribers are marked as bounced.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wp_newslatter_campaigns_process_bounces');
        echo '<input type="hidden" name="action" value="wp_newslatter_campaigns_process_bounces"><textarea class="large-text code" rows="8" name="bounces" placeholder="failed@example.com"></textarea><p><button class="button button-primary">Process bounces</button></p></form></div>';
        $rows = $wpdb->get_results("SELECT * FROM {$this->table('bounces')} ORDER BY id DESC LIMIT 200");
        echo '<table class="widefat striped"><thead><tr><th>Email</th><th>Subscriber</th><th>Message</th><th>Created</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td>' . esc_html($r->email) . '</td><td>' . absint($r->subscriber_id) . '</td><td>' . esc_html(wp_trim_words((string)$r->message, 18)) . '</td><td>' . esc_html($r->created) . '</td></tr>';
        echo '</tbody></table>';
        $this->admin_wrap_end();
    }

    public function process_bounces() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('wp_newslatter_campaigns_process_bounces');
        global $wpdb;
        $body = wp_unslash($_POST['bounces'] ?? '');
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $body, $matches);
        $emails = array_unique(array_map('strtolower', $matches[0] ?? array()));
        $count = 0;
        foreach ($emails as $email) {
            $sub_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table('subscribers')} WHERE email=%s", $email));
            if ($sub_id) $wpdb->update($this->table('subscribers'), array('status'=>'bounced','updated'=>current_time('mysql')), array('id'=>$sub_id));
            $wpdb->insert($this->table('bounces'), array('subscriber_id'=>$sub_id,'email'=>$email,'message'=>sanitize_textarea_field($body),'created'=>current_time('mysql')));
            $count++;
        }
        $this->redirect_admin('wp-newslatter-campaigns-bounces', sprintf('Processed %d bounced email(s).', $count));
    }

    private function is_domain_blocked($email) {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        if (!$domain) return false;
        $settings = $this->settings();
        $blocked = array_filter(array_map('trim', explode("\n", strtolower((string)$settings['domain_blacklist']))));
        return in_array($domain, $blocked, true);
    }

    public function comment_optin_field() {
        $s = $this->settings();
        if (empty($s['subscribe_on_comment'])) return;
        echo '<p class="comment-form-wp-newslatter-campaigns"><label><input type="checkbox" name="wp_newslatter_campaigns_comment_optin" value="1"> ' . esc_html__('Send me email updates from this site.', 'wp-newslatter-campaigns') . '</label></p>';
    }

    public function capture_comment_optin($commentdata) {
        $s = $this->settings();
        if (empty($s['subscribe_on_comment']) || empty($_POST['wp_newslatter_campaigns_comment_optin'])) return $commentdata;
        $email = sanitize_email($commentdata['comment_author_email'] ?? '');
        if ($email && is_email($email) && !$this->is_domain_blocked($email)) $this->upsert_subscriber(array('email'=>$email,'first_name'=>sanitize_text_field($commentdata['comment_author'] ?? ''),'status'=>'subscribed','source'=>'comment-optin','ip'=>$this->ip(),'created'=>current_time('mysql'),'updated'=>current_time('mysql')));
        return $commentdata;
    }

    public function capture_cf7_optin($contact_form, $result) {
        $s = $this->settings();
        if (empty($s['cf7_optin']) || !class_exists('WPCF7_Submission')) return;
        $submission = WPCF7_Submission::get_instance();
        if (!$submission) return;
        $data = $submission->get_posted_data();
        $has_optin = false;
        foreach (array('newsletter','wp_newslatter_campaigns','subscribe') as $key) if (!empty($data[$key])) $has_optin = true;
        if (!$has_optin) return;
        $email = '';
        foreach ($data as $key => $value) { if (is_string($value) && is_email($value)) { $email = sanitize_email($value); break; } }
        if ($email && !$this->is_domain_blocked($email)) $this->upsert_subscriber(array('email'=>$email,'first_name'=>sanitize_text_field($data['your-name'] ?? ($data['name'] ?? '')),'status'=>'subscribed','source'=>'contact-form-7','ip'=>$this->ip(),'created'=>current_time('mysql'),'updated'=>current_time('mysql')));
    }

    private function unsubscribe_url($sub) {
        $id = is_object($sub) ? (int)$sub->id : (int)($sub['id'] ?? 0);
        $token = is_object($sub) ? (string)$sub->token : (string)($sub['token'] ?? '');
        return add_query_arg(array('wpnc_nl_unsubscribe'=>1,'s'=>$id,'t'=>$token), home_url('/'));
    }

    public function maybe_unsubscribe() {
        if (!isset($_GET['wpnc_nl_unsubscribe']) && !isset($_POST['wpnc_nl_unsubscribe'])) return;
        global $wpdb;
        $id = absint($_REQUEST['s'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_REQUEST['t'] ?? ''));
        if ($id && $token) {
            $subscriber = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('subscribers')} WHERE id=%d AND token=%s", $id, $token));
            if ($subscriber) $wpdb->update($this->table('subscribers'), array('status'=>'unsubscribed','enabled'=>0,'updated'=>current_time('mysql')), array('id'=>$id));
        }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            status_header(200);
            nocache_headers();
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Unsubscribed';
            exit;
        }
        wp_die('<h1>Unsubscribed</h1><p>You have been unsubscribed from future newsletters.</p>', 'Unsubscribed', array('response'=>200));
    }

    private function run_due_automations() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$this->table('automations')} WHERE enabled=1");
        foreach ((array)$rows as $a) {
            if (!$this->automation_due($a)) continue;
            $html = $this->build_automation_html($a);
            if (trim($html) === '') continue;
            $subject = $a->subject ?: $a->name;
            $wpdb->insert($this->table('campaigns'), array('title'=>$a->name,'subject'=>$subject,'html'=>$html,'status'=>'sending','type'=>'automated','created'=>current_time('mysql'),'updated'=>current_time('mysql')));
            $wpdb->update($this->table('automations'), array('last_run'=>current_time('mysql'),'updated'=>current_time('mysql')), array('id'=>$a->id));
        }
    }

    private function automation_due($a) {
        if (empty($a->last_run)) return true;
        $last = strtotime($a->last_run); if (!$last) return true;
        $days = $a->frequency === 'daily' ? 1 : ($a->frequency === 'monthly' ? 28 : 7);
        return (time() - $last) >= DAY_IN_SECONDS * $days;
    }

    private function build_automation_html($a) {
        $q = new WP_Query(array('post_type'=>$a->post_type ?: 'post','posts_per_page'=>max(1, min(20, (int)$a->post_count)),'post_status'=>'publish','ignore_sticky_posts'=>true));
        $html = '<div class="wpnc-automated-newsletter">' . wp_kses_post($a->intro);
        while ($q->have_posts()) { $q->the_post(); $html .= '<article><h2><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h2><p>' . esc_html(wp_trim_words(get_the_excerpt(), 36)) . '</p></article>'; }
        wp_reset_postdata();
        return $html . '</div>';
    }

    public function ip() { return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''); }

    public function woocommerce_checkout_checkbox() {
        if (!function_exists('is_checkout')) return;
        $s = $this->settings(); if (empty($s['woocommerce_checkout_optin'])) return;
        echo '<p class="form-row wp-newslatter-campaigns-checkout"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" type="checkbox" name="wp_newslatter_campaigns_optin" value="1"> <span>' . esc_html__('Send me Garilla prize and giveaway updates by email.', 'wp-newslatter-campaigns') . '</span></label></p>';
    }

    public function woocommerce_checkout_optin($order_id, $data) {
        $s = $this->settings(); if (empty($s['woocommerce_checkout_optin']) || empty($_POST['wp_newslatter_campaigns_optin']) || !function_exists('wc_get_order')) return;
        $order = wc_get_order($order_id);
        if ($order) $this->upsert_subscriber(array('email'=>$order->get_billing_email(),'first_name'=>$order->get_billing_first_name(),'last_name'=>$order->get_billing_last_name(),'source'=>'woocommerce-checkout','wp_user_id'=>$order->get_user_id(),'created'=>current_time('mysql')));
    }

    public function wp_user_optin($user_id) {
        $s = $this->settings(); if (empty($s['wp_user_optin'])) return;
        $u = get_user_by('id', $user_id);
        if ($u && is_email($u->user_email)) $this->upsert_subscriber(array('email'=>$u->user_email,'first_name'=>$u->first_name,'last_name'=>$u->last_name,'source'=>'wp-user','wp_user_id'=>$user_id,'created'=>current_time('mysql')));
    }
}

add_action('plugins_loaded', array('WP_Newslatter_Campaigns_Plugin', 'instance'));
