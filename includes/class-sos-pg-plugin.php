<?php
if (!defined('ABSPATH')) exit;

class SOS_PG_Plugin {
    private static $instance = null;
    private $table_logs = '';
    private $booking_table = '';
    private $booking_meta_table = '';
    private $booking_partner_table = '';
    private $settings_key = 'sos_pg_settings';
    private $routes_key = 'sos_pg_partner_routes';
    private $discounts_key = 'sos_pg_partner_discounts';
    private $webhooks_key = 'sos_pg_partner_webhooks';
    private $tester_webhook_key = 'sos_pg_main_last_webhook';
    private $db_version_key = 'sos_pg_db_version';
    private $partner_original_total = null;
    private $settings_helper;
    private $partner_registry;
    private $embedded_booking;
    private $rest_router;
    private $handoff_token;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        error_log('[SOS SSO] CONSTRUCTOR HARD PATH=' . __FILE__);
        error_log('[SOS SSO] PLUGIN LOADED HARD CHECK PATH=' . __FILE__);

        global $wpdb;
        $this->table_logs = $wpdb->prefix . SOS_PG_TABLE_LOGS;
        $this->booking_table = $wpdb->prefix . 'latepoint_bookings';
        $this->booking_meta_table = $wpdb->prefix . 'latepoint_booking_meta';
        $this->booking_partner_table = $wpdb->prefix . 'sos_pg_booking_partner';

        $this->settings_helper = new SOS_PG_Settings($this->settings_key, $this->routes_key, $this->discounts_key, $this->webhooks_key);
        $this->partner_registry = new SOS_PG_Partner_Registry($this->settings_helper);
        $this->embedded_booking = new SOS_PG_Embedded_Booking($this->partner_registry);
        if (class_exists('SOS_PG_Handoff_Token')) {
            $this->handoff_token = new SOS_PG_Handoff_Token();
        } else {
            error_log('SOS_PG: classe SOS_PG_Handoff_Token non trovata');
            $this->handoff_token = null;
        }
        $this->rest_router = new SOS_PG_REST_Router($this);

        register_activation_hook(SOS_PG_FILE, [$this, 'activate']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_notices', [$this, 'admin_notice_missing_key']);
        add_action('admin_post_sos_pg_save_settings', [$this, 'handle_save_settings']);

        $partner_mode = $this->is_partner_mode();

        // metabox sempre disponibile in admin
        if (is_admin()) {
            add_action('add_meta_boxes', [$this, 'register_partner_page_metabox']);
            add_action('save_post_page', [$this, 'save_partner_page_meta'], 10, 2);
        }

        // The LatePoint completion monitor and completion endpoints must be available
        // on the real booking page regardless of site_role.
        error_log('[SOS SSO] HOOK REGISTER HARD wp_enqueue_scripts -> enqueue_partner_completion_monitor_script PATH=' . __FILE__);
        error_log('[SOS SSO] HOOK REGISTER HARD init -> handle_partner_completion PATH=' . __FILE__);
        error_log('[SOS SSO] HOOK REGISTER HARD init -> handle_partner_completion_url_request PATH=' . __FILE__);
        add_action('init', [$this, 'handle_partner_completion'], 1);
        add_action('init', [$this, 'handle_partner_completion_url_request'], 1);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_partner_completion_monitor_script']);

        if ($partner_mode) {
            // Modalità partner: solo funzionalità lato partner attive.
            add_action('init', [$this, 'handle_book_now_request'], 1);
            add_action('init', [$this, 'handle_partner_tester_webhook'], 1);
            add_shortcode('sos_partner_prenota', [$this, 'shortcode_partner_prenota']);
        } else {
            // Modalità sito principale: funzionalità gateway complete.
            add_action('init', [$this, 'handle_partner_login'], 1);
            add_action('init', [$this, 'handle_book_now_request'], 1);
            add_action('template_redirect', [$this, 'protect_partner_pages'], 1);
            add_action('init', [$this, 'handle_payment_callback'], 1);

            // Shortcode [sos_partner_prenota] per embed booking button su portali propri.
            add_shortcode('sos_partner_prenota', [$this, 'shortcode_partner_prenota']);

            add_action('admin_post_sos_pg_block_ip', [$this, 'handle_block_ip']);
            add_action('admin_post_sos_pg_unlock_ip', [$this, 'handle_unlock_ip']);
            add_action('admin_post_sos_pg_clear_logs', [$this, 'handle_clear_logs']);
            add_action('admin_post_sos_pg_save_routes', [$this, 'handle_save_routes']);
            add_action('admin_post_sos_pg_save_discounts', [$this, 'handle_save_discounts']);
            add_action('admin_post_sos_pg_save_webhooks', [$this, 'handle_save_webhooks']);
            add_action('admin_post_sos_pg_send_payment_test', [$this, 'handle_send_payment_test']);
            add_action('admin_post_sos_pg_save_partner_configs', [$this, 'handle_save_partner_configs']);

            // LatePoint sconto partner.
            add_filter('latepoint_full_amount', [$this, 'apply_partner_discount'], 20, 3);
            add_filter('latepoint_full_amount_for_service', [$this, 'apply_partner_discount'], 20, 3);
            add_filter('latepoint_deposit_amount', [$this, 'apply_partner_discount'], 20, 3);
            add_filter('latepoint_deposit_amount_for_service', [$this, 'apply_partner_discount'], 20, 3);

            // LatePoint booking lifecycle.
            add_action('latepoint_after_create_booking', [$this, 'handle_booking_created'], 20, 2);
            add_action('latepoint_booking_created', [$this, 'handle_booking_created'], 20, 2);
        }

        // Crea/aggiorna le tabelle del plugin anche sugli upgrade (non solo in activate).
        $this->maybe_upgrade_database();
    }

    public function activate() {
        $this->maybe_upgrade_database();

        if (get_option($this->settings_key) === false) {
            add_option($this->settings_key, [
                'site_role' => 'main',
                'endpoint_slug' => 'partner-login',
                'courtesy_page_id' => 0,
                'debug_logging_enabled' => 1,
                'max_fail_short' => 10,
                'max_fail_long' => 25,
                'ban_short_minutes' => 60,
                'ban_long_minutes' => 1440,
                'window_short_minutes' => 10,
                'window_long_minutes' => 1440,
                'public_key_pem' => '',
                'enable_latepoint_discount_hooks' => 0,
                'payment_callback_slug' => 'partner-payment-callback',
                'payment_callback_secret' => '',
                'payment_success_status' => 'pending',
                'self_login_private_key_pem' => '',
                'self_login_partner_id' => '',
                'self_login_endpoint_url' => '',
                'partner_webhook_secret' => '',
                'partner_callback_url' => '',
                'partner_callback_secret' => '',
            ]);
        }
    }

    public function maybe_upgrade_database() {
        if ((string) get_option($this->db_version_key) === SOS_PG_DB_VERSION) {
            return;
        }

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_logs = $this->table_logs;
        $sql_logs = "CREATE TABLE {$table_logs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            level VARCHAR(20) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            partner_id VARCHAR(191) DEFAULT '' NOT NULL,
            email VARCHAR(191) DEFAULT '' NOT NULL,
            ip VARCHAR(64) DEFAULT '' NOT NULL,
            reason TEXT NULL,
            user_agent TEXT NULL,
            context LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY partner_id (partner_id),
            KEY email (email),
            KEY ip (ip)
        ) {$charset_collate};";

        dbDelta($sql_logs);

        $bp_table = $this->booking_partner_table;
        $sql_bp = "CREATE TABLE {$bp_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lp_booking_id BIGINT UNSIGNED NOT NULL,
            partner_id VARCHAR(64) NOT NULL,
            location_id VARCHAR(64) NOT NULL DEFAULT '',
            payment_transaction_id VARCHAR(191) NOT NULL DEFAULT '',
            payment_external_ref VARCHAR(191) NOT NULL DEFAULT '',
            payment_status VARCHAR(20) NOT NULL DEFAULT '',
            partner_charge DECIMAL(10,4) DEFAULT NULL,
            confirmed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_lp_booking_id (lp_booking_id),
            KEY partner_id (partner_id),
            KEY payment_transaction_id (payment_transaction_id)
        ) {$charset_collate};";

        dbDelta($sql_bp);

        update_option($this->db_version_key, SOS_PG_DB_VERSION);
    }

    public function admin_notice_missing_key() {
        if (!current_user_can('manage_options')) {
            return;
        }
        // In modalità partner la chiave pubblica non è necessaria su questo sito.
        if ($this->is_partner_mode()) {
            return;
        }
        if (trim((string) $this->get_settings()['public_key_pem']) !== '') {
            return;
        }
        $url = admin_url('admin.php?page=sos-partner-gateway-settings');
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo '<strong>SOS Partner Gateway</strong>: nessuna chiave pubblica ECC configurata. ';
            echo 'Il login partner non funzionerà finché non viene impostata una chiave in ';
            echo '<a href="' . esc_url($url) . '">Impostazioni &rarr; Chiave pubblica PEM</a>.';
            echo '</p></div>';
    }

    private function get_settings() {

        $defaults = [
            'site_role' => 'main',
            'endpoint_slug' => 'partner-login',
            'courtesy_page_id' => 0,
            'debug_logging_enabled' => 1,
            'max_fail_short' => 10,
            'max_fail_long' => 25,
            'ban_short_minutes' => 60,
            'ban_long_minutes' => 1440,
            'window_short_minutes' => 10,
            'window_long_minutes' => 1440,
            'public_key_pem' => '',
            'enable_latepoint_discount_hooks' => 0,
            'payment_callback_slug' => 'partner-payment-callback',
            'payment_callback_secret' => '',
            'payment_success_status' => 'pending',
            'self_login_private_key_pem' => '',
            'self_login_partner_id' => '',
            'self_login_endpoint_url' => '',
            'partner_webhook_secret' => '',
            'partner_callback_url' => '',
            'partner_callback_secret' => '',
        ];

        $settings = get_option($this->settings_key, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $result = wp_parse_args($settings, $defaults);
        return $result;
    }

    private function is_partner_mode() {
        return $this->get_settings()['site_role'] === 'partner';
    }

    public function get_partner_registry() {
        return $this->partner_registry;
    }

    public function get_embedded_booking_service() {
        return $this->embedded_booking;
    }

    public function get_handoff_token_service() {
        return $this->handoff_token;
    }

    public function log_public_event($level, $code, $data = []) {
        $this->log_event($level, $code, $data);
    }

    public function get_health_payload() {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_data = function_exists('get_plugin_data') ? get_plugin_data(SOS_PG_FILE, false, false) : [];
        $version = isset($plugin_data['Version']) ? (string) $plugin_data['Version'] : '';
        $settings = $this->get_settings();
        $site_role = ($settings['site_role'] ?? '') === 'partner' ? 'partner' : 'main';

        return [
            'ok' => true,
            'plugin' => 'sos-partner-gateway',
            'version' => $version,
            'site_role' => $site_role,
            'timestamp' => current_time('mysql'),
        ];
    }

    private function get_partner_routes() {

        $routes = get_option($this->routes_key, []);
        if (!is_array($routes)) {
            return [];
        }

        $clean = [];
        foreach ($routes as $pid => $path) {
            $pid = sanitize_text_field((string) $pid);
            $path = trim((string) $path);
            if ($pid === '' || $path === '') {
                continue;
            }

            // Accetta sia path relativo che URL assoluto.
            if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
                $clean[$pid] = esc_url_raw($path);
            } else {
                $clean[$pid] = '/' . ltrim($path, '/');
            }
        }

        return $clean;
    }

    private function get_partner_discounts() {

        $map = get_option($this->discounts_key, []);
        $result = is_array($map) ? $map : [];
        return $result;
    }

    private function get_partner_webhooks() {

        $map = get_option($this->webhooks_key, []);
        $result = is_array($map) ? $map : [];
        return $result;
    }

    /**
     * Individua il partner_id dal location_id LatePoint configurato nel webhook.
     * Usato come fallback quando get_current_partner_id() non restituisce nulla
     * (es. prenotazione creata in contesto AJAX senza sessione WP).
     */
    private function get_partner_id_by_location($location_id) {
        if ((string) $location_id === '') {
            return '';
        }
        foreach ($this->get_partner_webhooks() as $pid => $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            $cfg_loc = (string) ($cfg['location_id'] ?? '');
            if ($cfg_loc !== '' && $cfg_loc === (string) $location_id) {
                return (string) $pid;
            }
        }
        return '';
    }

    private function get_partner_discount_config($partner_id = '') {
        if ($partner_id === '') {
            $partner_id = $this->get_current_partner_id();
        }

        $defaults = ['amount' => 0.0, 'type' => 'fixed', 'pay_on_partner' => false];

        if ($partner_id === '') {
            return $defaults;
        }

        $map = $this->get_partner_discounts();
        if (isset($map[$partner_id])) {
            $entry = $map[$partner_id];

            // Retrocompatibilità: vecchio formato era un float semplice.
            if (!is_array($entry)) {
                return array_merge($defaults, ['amount' => (float) $entry]);
            }

            return [
                'amount'          => (float) ($entry['amount'] ?? 0.0),
                'type'            => in_array($entry['type'] ?? 'fixed', ['fixed', 'percent'], true) ? $entry['type'] : 'fixed',
                'pay_on_partner'  => !empty($entry['pay_on_partner']),
            ];
        }

        // Fallback: partner non presente nella mappa sconti, usa config partner multipli.
        $cfg = $this->partner_registry ? $this->partner_registry->get_partner_config($partner_id) : null;
        if ($cfg) {
            $pay_on_partner = !empty($cfg['pay_on_partner']) || !empty($cfg['no_upfront_cost']);
            return [
                'amount'          => 0.0,
                'type'            => 'fixed',
                'pay_on_partner'  => $pay_on_partner,
            ];
        }

        return $defaults;
    }

    private function upsert_partner_booking_record(array $data): bool {
        if (empty($data['lp_booking_id']) || empty($data['partner_id'])) {
            return false;
        }

        global $wpdb;
        $now = current_time('mysql');

        $lp_booking_id          = (int)    $data['lp_booking_id'];
        $partner_id             = (string) $data['partner_id'];
        $location_id            = (string) ($data['location_id'] ?? '');
        $payment_transaction_id = (string) ($data['payment_transaction_id'] ?? '');
        $payment_external_ref   = (string) ($data['payment_external_ref'] ?? '');
        $payment_status         = (string) ($data['payment_status'] ?? '');
        $partner_charge         = (array_key_exists('partner_charge', $data) && $data['partner_charge'] !== null)
                                      ? (float) $data['partner_charge'] : null;
        $confirmed_at           = (array_key_exists('confirmed_at', $data) && $data['confirmed_at'] !== null)
                                      ? (string) $data['confirmed_at'] : null;

        // Pre-prepare nullable SQL fragments before the main prepare() call.
        // %f returns a bare numeric string (no quotes); %s returns a quoted+escaped string.
        // 'NULL' is emitted as a literal for truly null values.
        $charge_sql    = $partner_charge !== null ? $wpdb->prepare('%f', $partner_charge) : 'NULL';
        $confirmed_sql = $confirmed_at   !== null ? $wpdb->prepare('%s', $confirmed_at)   : 'NULL';

        // Selective ON DUPLICATE KEY UPDATE clause: only overwrite fields present in $data.
        // partner_id and created_at are intentionally excluded — never overwritten on duplicate.
        $on_dup = ['updated_at = VALUES(updated_at)'];
        if (array_key_exists('location_id', $data)) {
            $on_dup[] = 'location_id = VALUES(location_id)';
        }
        if (array_key_exists('payment_transaction_id', $data)) {
            $on_dup[] = 'payment_transaction_id = VALUES(payment_transaction_id)';
        }
        if (array_key_exists('payment_external_ref', $data)) {
            $on_dup[] = 'payment_external_ref = VALUES(payment_external_ref)';
        }
        if (array_key_exists('payment_status', $data)) {
            $on_dup[] = 'payment_status = VALUES(payment_status)';
        }
        if (array_key_exists('partner_charge', $data)) {
            $on_dup[] = 'partner_charge = VALUES(partner_charge)';
        }
        if (array_key_exists('confirmed_at', $data)) {
            $on_dup[] = 'confirmed_at = VALUES(confirmed_at)';
        }
        $on_dup_sql = implode(', ', $on_dup);

        $t = $this->booking_partner_table;

        // Single atomic INSERT ... ON DUPLICATE KEY UPDATE — eliminates any TOCTOU race condition.
        // The $charge_sql / $confirmed_sql fragments are interpolated before $wpdb->prepare()
        // processes the remaining %d/%s placeholders; they contain no user input.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "INSERT INTO `{$t}`
                (lp_booking_id, partner_id, location_id, payment_transaction_id, payment_external_ref,
                 payment_status, partner_charge, confirmed_at, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s, {$charge_sql}, {$confirmed_sql}, %s, %s)
             ON DUPLICATE KEY UPDATE {$on_dup_sql}",
            $lp_booking_id,
            $partner_id,
            $location_id,
            $payment_transaction_id,
            $payment_external_ref,
            $payment_status,
            $now,
            $now
        );

        $result = $wpdb->query($sql);

        if ($result === false) {
            $this->log_event('ERROR', 'UPSERT_BOOKING_RECORD_FAIL', [
                'context' => [
                    'lp_booking_id' => $lp_booking_id,
                    'partner_id'    => $partner_id,
                    'db_error'      => (string) $wpdb->last_error,
                ],
            ]);
            return false;
        }

        return true;
    }

    private function get_partner_booking_record($booking_id) {
        if (!$booking_id) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->booking_partner_table} WHERE lp_booking_id = %d LIMIT 1",
                (int) $booking_id
            )
        );
        return $row ?: null;
    }

    private function get_partner_booking_record_by_external_reference($partner_id, $external_reference) {
        $partner_id = sanitize_text_field((string) $partner_id);
        $external_reference = sanitize_text_field((string) $external_reference);
        if ($partner_id === '' || $external_reference === '') {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->booking_partner_table} WHERE partner_id = %s AND payment_external_ref = %s ORDER BY id DESC LIMIT 1",
                $partner_id,
                $external_reference
            )
        );

        return $row ?: null;
    }

    private function get_booking_meta_value($booking_id, array $meta_keys) {
        $booking_id = absint($booking_id);
        if ($booking_id <= 0 || empty($meta_keys)) {
            return '';
        }

        global $wpdb;

        foreach ($meta_keys as $meta_key) {
            $meta_key = sanitize_text_field((string) $meta_key);
            if ($meta_key === '') {
                continue;
            }

            $value = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$this->booking_meta_table} WHERE object_id = %d AND meta_key = %s LIMIT 1",
                    $booking_id,
                    $meta_key
                )
            );

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function get_booking_service_name($service_id, $booking = null) {
        $service_name = sanitize_text_field((string) $this->safe_get($booking, 'service_name'));
        if ($service_name !== '') {
            return $service_name;
        }

        $service_name = sanitize_text_field((string) $this->safe_get_nested($booking, ['service', 'name']));
        if ($service_name !== '') {
            return $service_name;
        }

        $service_id = absint($service_id);
        if ($service_id <= 0) {
            return '';
        }

        global $wpdb;
        $service_table = $wpdb->prefix . 'latepoint_services';
        $service_name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT name FROM {$service_table} WHERE id = %d LIMIT 1",
                $service_id
            )
        );

        return is_scalar($service_name) ? sanitize_text_field((string) $service_name) : '';
    }

    private function build_booking_datetime_value($start_date, $start_time = '') {
        $start_date = trim((string) $start_date);
        $start_time = trim((string) $start_time);

        if ($start_date === '') {
            return '';
        }

        $date_string = trim($start_date . ' ' . $start_time);

        try {
            $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
            $datetime = new DateTimeImmutable($date_string, $timezone);
            return $datetime->format(DATE_ATOM);
        } catch (Exception $exception) {
            return '';
        }
    }

    private function get_partner_webhook_config($partner_id) {
        $partner_id = sanitize_text_field((string) $partner_id);
        if ($partner_id === '') {
            return ['url' => '', 'secret' => '', 'source' => ''];
        }

        $partner_cfg = $this->partner_registry ? $this->partner_registry->get_partner_config($partner_id) : null;
        if (is_array($partner_cfg)) {
            $url = esc_url_raw((string) ($partner_cfg['webhook_url'] ?? ''));
            if ($url !== '') {
                return [
                    'url' => $url,
                    'secret' => (string) ($partner_cfg['webhook_secret'] ?? ''),
                    'source' => 'partner_config',
                ];
            }
        }

        $webhooks = $this->get_partner_webhooks();
        if (isset($webhooks[$partner_id]) && is_array($webhooks[$partner_id])) {
            return [
                'url' => esc_url_raw((string) ($webhooks[$partner_id]['url'] ?? '')),
                'secret' => (string) ($webhooks[$partner_id]['secret'] ?? ''),
                'source' => 'legacy_webhooks',
            ];
        }

        return ['url' => '', 'secret' => '', 'source' => ''];
    }

    private function resolve_booking_external_reference($partner_id, $booking_id, $booking = null, $customer_email = '') {
        $booking_id = absint($booking_id);
        $customer_email = sanitize_email((string) $customer_email);

        $candidate_keys = ['external_reference', 'external_ref', 'payment_external_ref'];
        foreach ($candidate_keys as $candidate_key) {
            $candidate_value = sanitize_text_field((string) $this->safe_get($booking, $candidate_key));
            if ($candidate_value !== '') {
                return $candidate_value;
            }
        }

        $meta_candidate = sanitize_text_field((string) $this->get_booking_meta_value($booking_id, [
            'payment_external_reference',
            'external_reference',
            'partner_external_reference',
            'payment_external_ref',
        ]));
        if ($meta_candidate !== '') {
            return $meta_candidate;
        }

        $existing_record = $this->get_partner_booking_record($booking_id);
        if ($existing_record && !empty($existing_record->payment_external_ref)) {
            $record_value = sanitize_text_field((string) $existing_record->payment_external_ref);
            if ($record_value !== '') {
                return $record_value;
            }
        }

        $mapping = $this->partner_registry ? $this->partner_registry->get_external_reference_mapping($partner_id) : '';
        $mapping = sanitize_text_field((string) $mapping);
        if ($mapping === '') {
            return '';
        }

        $mapped_meta_value = sanitize_text_field((string) $this->get_booking_meta_value($booking_id, [$mapping]));
        if ($mapped_meta_value !== '') {
            return $mapped_meta_value;
        }

        $mapped_value = sanitize_text_field((string) $this->safe_get($booking, $mapping));
        if ($mapped_value !== '') {
            return $mapped_value;
        }

        if (strpos($mapping, '.') !== false) {
            $mapped_nested_value = sanitize_text_field((string) $this->safe_get_nested($booking, array_map('trim', explode('.', $mapping))));
            if ($mapped_nested_value !== '') {
                return $mapped_nested_value;
            }
        }

        switch (strtolower($mapping)) {
            case 'booking_id':
            case 'id':
                return $booking_id > 0 ? (string) $booking_id : '';

            case 'customer_email':
            case 'email':
                return $customer_email;

            case 'partner_id':
                return sanitize_text_field((string) $partner_id);

            case 'service_id':
                return sanitize_text_field((string) $this->safe_get($booking, 'service_id'));
        }

        return '';
    }

    private function get_current_partner_id($user_id = 0) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            // Fallback su cookie se l’utente non risulta autenticato (es. sessione persa nel frontend LatePoint).
            $cookie_pid = sanitize_text_field((string) ($_COOKIE['sos_pg_partner_id'] ?? ''));
            return preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $cookie_pid) ? $cookie_pid : '';
        }

        // Nuova chiave prefissata (Fase 4 hardening); fallback sulla vecchia chiave per utenti esistenti.
        $partner_id = get_user_meta($user_id, 'sos_pg_partner_id', true);
        if (!is_string($partner_id) || trim($partner_id) === '') {
            // Fallback compat: utenti creati prima della rinomina dei meta.
            $partner_id = get_user_meta($user_id, 'partner_id', true);
        }
        if (is_string($partner_id) && trim($partner_id) !== '') {
            return trim($partner_id);
        }

        // Fallback su cookie anche quando l'utente è autenticato ma il meta è assente
        // (es. primo accesso dopo il fix del login, prima che il meta venga riscritto).
        $cookie_pid = sanitize_text_field((string) ($_COOKIE['sos_pg_partner_id'] ?? ''));
        // Accetta solo valori con i caratteri ammessi per un partner_id (alfanumerici e trattini).
        return preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $cookie_pid) ? $cookie_pid : '';
    }

    private function current_endpoint_path() {
        $slug = trim((string) $this->get_settings()['endpoint_slug'], '/');
        return '/' . $slug;
    }

    public function get_login_endpoint_url() {
        return home_url($this->current_endpoint_path() . '/');
    }

    private function current_payment_callback_path() {
        $slug = trim((string) $this->get_settings()['payment_callback_slug'], '/');
        return '/' . ($slug === '' ? 'partner-payment-callback' : $slug);
    }

    private function current_completion_path() {
        return '/partner-completion';
    }

    private function get_environment_type_from_host($host) {
        $host = strtolower(trim((string) $host));

        if ($host === '') {
            return 'unknown';
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || preg_match('/(^|\.)localhost$|(^|\.)local$|(^|\.)test$|(^|\.)invalid$/', $host)) {
            return 'local';
        }

        foreach (['staging', 'stage', 'dev', 'preview', 'qa', 'uat', 'sandbox', 'demo'] as $token) {
            if (strpos($host, $token) !== false) {
                return 'staging';
            }
        }

        return 'production';
    }

    private function describe_environment_type($type) {
        switch ((string) $type) {
            case 'local':
                return 'locale';
            case 'staging':
                return 'staging/dev';
            case 'production':
                return 'produzione';
            case 'missing':
                return 'non configurato';
            default:
                return 'non determinato';
        }
    }

    private function get_url_environment_info($url) {
        $url = trim((string) $url);

        if ($url === '') {
            return [
                'url' => '',
                'host' => '',
                'path' => '',
                'type' => 'missing',
                'type_label' => $this->describe_environment_type('missing'),
                'is_valid' => false,
                'is_https' => false,
            ];
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $type = $this->get_environment_type_from_host($host);

        return [
            'url' => $url,
            'host' => $host,
            'path' => $path,
            'type' => $type,
            'type_label' => $this->describe_environment_type($type),
            'is_valid' => (bool) wp_http_validate_url($url),
            'is_https' => $scheme === 'https',
        ];
    }

    private function should_warn_environment_mismatch(array $current, array $target) {
        if (empty($current['is_valid']) || empty($target['is_valid'])) {
            return false;
        }

        if (in_array($current['type'], ['unknown', 'missing'], true) || in_array($target['type'], ['unknown', 'missing'], true)) {
            return false;
        }

        return (string) $current['type'] !== (string) $target['type'];
    }

    private function is_non_local_http_url(array $info) {
        if (empty($info['is_valid'])) {
            return false;
        }

        if (!empty($info['is_https'])) {
            return false;
        }

        return !in_array($info['type'], ['local', 'missing'], true);
    }

    private function collect_environment_report($scope = 'settings', array $partner_configs = []) {
        $settings = $this->get_settings();
        $site_info = $this->get_url_environment_info(home_url('/'));
        $callback_info = $this->get_url_environment_info(home_url($this->current_payment_callback_path() . '/'));
        $completion_info = $this->get_url_environment_info(home_url($this->current_completion_path() . '/'));

        $summary = [
            'Sito corrente' => $site_info['url'],
            'Tipo ambiente' => $site_info['type_label'],
            'Callback URL effettivo' => $callback_info['url'],
            'Completion URL effettivo' => $completion_info['url'],
        ];

        $warnings = [];
        $add_warning = function($message) use (&$warnings) {
            $message = trim((string) $message);
            if ($message !== '') {
                $warnings[$message] = $message;
            }
        };

        if ($this->is_non_local_http_url($callback_info)) {
            $add_warning('L\'URL callback effettivo usa HTTP e non HTTPS.');
        }

        if ($this->is_non_local_http_url($completion_info)) {
            $add_warning('L\'URL completion effettivo usa HTTP e non HTTPS.');
        }

        $partner_callback_info = $this->get_url_environment_info((string) ($settings['partner_callback_url'] ?? ''));
        if ($partner_callback_info['url'] !== '') {
            $summary['Callback partner configurato'] = $partner_callback_info['url'] . ' [' . $partner_callback_info['type_label'] . ']';

            if (!$partner_callback_info['is_valid']) {
                $add_warning('partner_callback_url non è una URL valida.');
            } else {
                if ($this->is_non_local_http_url($partner_callback_info)) {
                    $add_warning('partner_callback_url usa HTTP e non HTTPS.');
                }

                if ($this->should_warn_environment_mismatch($site_info, $partner_callback_info)) {
                    $add_warning('partner_callback_url sembra puntare a un ambiente ' . $partner_callback_info['type_label'] . ' mentre questo sito appare in ' . $site_info['type_label'] . '.');
                }

                $current_callback_path = untrailingslashit((string) $callback_info['path']);
                $partner_callback_path = untrailingslashit((string) $partner_callback_info['path']);
                if ($current_callback_path !== '' && $partner_callback_path !== '' && $partner_callback_path !== $current_callback_path) {
                    $add_warning('Il path configurato in partner_callback_url non coincide con il path callback attivo di questo ambiente.');
                }
            }
        }

        if ($scope === 'partner_configs') {
            $webhooks = $this->get_partner_webhooks();
            if (!is_array($webhooks)) {
                $webhooks = [];
            }

            foreach ($webhooks as $partner_id => $cfg) {
                $url = is_array($cfg) ? (string) ($cfg['url'] ?? '') : '';
                if ($url === '') {
                    continue;
                }

                $info = $this->get_url_environment_info($url);
                if (!$info['is_valid']) {
                    $add_warning('Webhook URL del partner "' . $partner_id . '" non è valida.');
                    continue;
                }

                if ($this->is_non_local_http_url($info)) {
                    $add_warning('Webhook URL del partner "' . $partner_id . '" usa HTTP e non HTTPS.');
                }

                if ($this->should_warn_environment_mismatch($site_info, $info)) {
                    $add_warning('Webhook URL del partner "' . $partner_id . '" sembra puntare a un ambiente ' . $info['type_label'] . ' mentre questo sito appare in ' . $site_info['type_label'] . '.');
                }
            }

            if (empty($partner_configs) && $this->settings_helper && method_exists($this->settings_helper, 'get_partner_configs_option')) {
                $partner_configs = $this->settings_helper->get_partner_configs_option();
            }

            if (is_array($partner_configs)) {
                foreach ($partner_configs as $partner_id => $cfg) {
                    $completion_return_url = is_array($cfg) ? (string) ($cfg['completion_return_url'] ?? '') : '';
                    if ($completion_return_url === '') {
                        continue;
                    }

                    $info = $this->get_url_environment_info($completion_return_url);
                    if (!$info['is_valid']) {
                        $add_warning('Completion return URL del partner "' . $partner_id . '" non è valida.');
                        continue;
                    }

                    if ($this->is_non_local_http_url($info)) {
                        $add_warning('Completion return URL del partner "' . $partner_id . '" usa HTTP e non HTTPS.');
                    }

                    if ($this->should_warn_environment_mismatch($site_info, $info)) {
                        $add_warning('Completion return URL del partner "' . $partner_id . '" sembra puntare a un ambiente ' . $info['type_label'] . ' mentre questo sito appare in ' . $site_info['type_label'] . '.');
                    }
                }
            }
        }

        return [
            'summary' => $summary,
            'warnings' => array_values($warnings),
        ];
    }

    private function render_environment_warning_panel($scope = 'settings', array $partner_configs = []) {
        $report = $this->collect_environment_report($scope, $partner_configs);

        echo '<div class="notice notice-info inline"><p><strong>Riepilogo ambiente</strong></p><ul style="margin:0 0 0 18px;list-style:disc;">';
        foreach ($report['summary'] as $label => $value) {
            if ((string) $value === '') {
                continue;
            }
            echo '<li><strong>' . esc_html($label) . ':</strong> <code>' . esc_html((string) $value) . '</code></li>';
        }
        echo '</ul><p class="description" style="margin-top:8px;">Questi controlli sono solo informativi: non bloccano il salvataggio e non modificano i flussi runtime.</p></div>';

        if (!empty($report['warnings'])) {
            foreach ($report['warnings'] as $warning) {
                echo '<div class="notice notice-warning inline"><p>' . esc_html($warning) . '</p></div>';
            }
        } else {
            echo '<div class="notice notice-success inline"><p>Nessun mismatch ambiente evidente rilevato nelle URL configurate per questa sezione.</p></div>';
        }
    }

    public function get_partner_completion_url($booking_id, array $args = []) {
        $booking_id = (int) $booking_id;
        if ($booking_id <= 0) {
            return '';
        }

        $partner_id = sanitize_text_field((string) ($args['partner_id'] ?? ''));
        if (!$this->is_valid_partner_id($partner_id)) {
            $partner_id = '';
        }

        $query = [
            'booking_id' => $booking_id,
            'completion_token' => $this->generate_completion_token($booking_id, $partner_id),
        ];

        if ($partner_id !== '') {
            $query['partner_id'] = $partner_id;
        }

        $phase = sanitize_key((string) ($args['phase'] ?? ''));
        if ($phase !== '') {
            $query['phase'] = $phase;
        }

        $external_reference = sanitize_text_field((string) ($args['external_reference'] ?? ''));
        if ($external_reference !== '') {
            $query['external_reference'] = $external_reference;
        }

        $opener_origin = $this->sanitize_opener_origin((string) ($args['opener_origin'] ?? ''));
        if ($opener_origin !== '') {
            $query['opener_origin'] = $opener_origin;
        }

        $source = sanitize_key((string) ($args['source'] ?? ''));
        if ($source !== '') {
            $query['source'] = $source;
        }

        return add_query_arg($query, home_url($this->current_completion_path() . '/'));
    }

    private function current_request_path() {
        return (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    }

    private function is_valid_partner_id($partner_id) {
        return is_string($partner_id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $partner_id) === 1;
    }

    private function normalize_local_path($path) {
        $path = (string) $path;
        if ($path === '') {
            return '';
        }

        $normalized = '/' . ltrim($path, '/');
        $normalized = untrailingslashit($normalized);
        return $normalized === '' ? '/' : $normalized;
    }

    private function normalize_partner_id_key($partner_id) {
        $partner_id = trim((string) $partner_id);
        return $this->is_valid_partner_id($partner_id) ? strtolower($partner_id) : '';
    }

    private function completion_token_ttl() {
        return 10 * MINUTE_IN_SECONDS;
    }

    private function completion_token_secret() {
        return wp_salt('auth') . '|sos_pg_partner_completion';
    }

    private function base64url_encode($value) {
        return rtrim(strtr(base64_encode((string) $value), '+/', '-_'), '=');
    }

    private function base64url_decode($value) {
        $value = (string) $value;
        if ($value === '') {
            return false;
        }

        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function generate_completion_token($booking_id, $partner_id = '') {
        $booking_id = (int) $booking_id;
        if ($booking_id <= 0) {
            return '';
        }

        $issued_at = time();
        $partner_key = $this->normalize_partner_id_key($partner_id);
        $payload = [
            'b' => $booking_id,
            'p' => $partner_key,
            'iat' => $issued_at,
            'exp' => $issued_at + $this->completion_token_ttl(),
        ];
        $payload_encoded = $this->base64url_encode(wp_json_encode($payload));
        $signature = hash_hmac('sha256', $payload_encoded, $this->completion_token_secret());

        return $payload_encoded . '.' . $signature;
    }

    private function verify_completion_token($token, $booking_id, $partner_id = '') {
        $token = trim((string) $token);
        $booking_id = (int) $booking_id;
        if ($token === '' || $booking_id <= 0) {
            return false;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return false;
        }

        $payload_encoded = $parts[0];
        $signature = $parts[1];
        $expected_signature = hash_hmac('sha256', $payload_encoded, $this->completion_token_secret());
        if (!hash_equals($expected_signature, $signature)) {
            return false;
        }

        $decoded = $this->base64url_decode($payload_encoded);
        if (!is_string($decoded) || $decoded === '') {
            return false;
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            return false;
        }

        $token_booking_id = isset($payload['b']) ? (int) $payload['b'] : 0;
        $issued_at = isset($payload['iat']) ? (int) $payload['iat'] : 0;
        $expires_at = isset($payload['exp']) ? (int) $payload['exp'] : 0;
        $token_partner_key = $this->normalize_partner_id_key((string) ($payload['p'] ?? ''));
        $expected_partner_key = $this->normalize_partner_id_key($partner_id);

        if ($token_booking_id !== $booking_id || $issued_at <= 0 || $expires_at <= 0) {
            return false;
        }

        $now = time();
        if ($issued_at > ($now + 60) || $expires_at < $now || ($expires_at - $issued_at) > $this->completion_token_ttl()) {
            return false;
        }

        if ($expected_partner_key !== '' && $token_partner_key !== $expected_partner_key) {
            return false;
        }

        return true;
    }

    private function sanitize_opener_origin($origin) {
        $origin = trim((string) $origin);
        if ($origin === '') {
            return '';
        }

        $parts = wp_parse_url($origin);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }

        $normalized = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $normalized .= ':' . (int) $parts['port'];
        }

        return $normalized;
    }

    private function sanitize_partner_return_url($url) {
        $url = esc_url_raw((string) $url);
        if ($url === '' || !wp_http_validate_url($url)) {
            error_log('[SOS SSO] return_url validation invalid value=' . ($url !== '' ? $url : ''));
            return '';
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            error_log('[SOS SSO] return_url validation invalid_scheme value=' . $url);
            return '';
        }

        error_log('[SOS SSO] return_url validation ok value=' . $url);
        return $url;
    }

    private function is_partner_enabled_page_id($post_id) {
        $post_id = (int) $post_id;
        return $post_id > 0 && (int) get_post_meta($post_id, '_sos_pg_partner_enabled', true) === 1;
    }

    private function has_active_partner_session() {
        return $this->is_valid_partner_id($this->get_current_partner_id());
    }

    private function is_partner_page_url($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }

        $home_host = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
        $url_host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($url_host !== '' && $home_host !== '' && $url_host !== $home_host) {
            return false;
        }

        $post_id = url_to_postid($url);
        if ($this->is_partner_enabled_page_id($post_id)) {
            return true;
        }

        $url_path = $this->normalize_local_path((string) parse_url($url, PHP_URL_PATH));
        if ($url_path === '') {
            return false;
        }

        foreach ($this->get_partner_routes() as $route) {
            $route_host = strtolower((string) parse_url((string) $route, PHP_URL_HOST));
            if ($route_host !== '' && $home_host !== '' && $route_host !== $home_host) {
                continue;
            }

            $route_path = $this->normalize_local_path((string) parse_url((string) $route, PHP_URL_PATH));
            if ($route_path !== '' && $route_path === $url_path) {
                return true;
            }
        }

        return false;
    }

    private function is_partner_rest_route($rest_route = '') {
        $rest_route = (string) $rest_route;
        return $rest_route !== '' && strpos($rest_route, '/sos-pg/v1/') === 0;
    }

    private function is_partner_rest_request($rest_route = '') {
        if ($this->is_partner_rest_route($rest_route)) {
            return true;
        }

        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return false;
        }

        $request_uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        return strpos($request_uri, '/wp-json/sos-pg/v1/') !== false;
    }

    private function is_partner_endpoint_request() {
        $request_path = $this->normalize_local_path($this->current_request_path());
        $login_path = $this->normalize_local_path($this->current_endpoint_path());
        $callback_path = $this->normalize_local_path($this->current_payment_callback_path());
        $completion_path = $this->normalize_local_path($this->current_completion_path());

        if ($request_path !== '' && ($request_path === $login_path || $request_path === $callback_path || $request_path === $completion_path)) {
            return true;
        }

        return isset($_GET['sos_pg_webhook'])
            || isset($_GET['sos_pg_tester_webhook'])
            || isset($_GET['sos_pg_book_now'])
            || isset($_GET['sos_pg_completion_url']);
    }

    private function is_partner_latepoint_flow_request() {
        $route_name = sanitize_text_field((string) ($_REQUEST['route_name'] ?? ''));
        if ($route_name === '') {
            return false;
        }

        $allowed_routes = [
            'steps__start',
            'steps__load_step',
            'steps__reload_booking_form_summary_panel',
        ];

        return in_array($route_name, $allowed_routes, true);
    }

    public function is_partner_context($scope = 'generic', array $context = []) {
        $rest_route = isset($context['rest_route']) ? (string) $context['rest_route'] : '';
        if ($this->is_partner_rest_request($rest_route) || $this->is_partner_endpoint_request()) {
            return true;
        }

        $active_partner_id = $this->get_current_partner_id();
        $has_active_partner = $this->is_valid_partner_id($active_partner_id);
        if ($has_active_partner && $this->is_partner_latepoint_flow_request()) {
            return true;
        }

        if (is_admin()) {
            return false;
        }

        if ($has_active_partner) {
            $post_id = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
            if ($this->is_partner_enabled_page_id($post_id)) {
                return true;
            }

            $referer = isset($context['referer']) ? (string) $context['referer'] : (string) wp_get_referer();
            if ($referer !== '' && $this->is_partner_page_url($referer)) {
                return true;
            }
        }

        if (!empty($context['allow_location_lookup'])) {
            $location_partner_id = $this->get_partner_id_by_location($context['location_id'] ?? '');
            if ($this->is_valid_partner_id($location_partner_id)) {
                return true;
            }
        }

        $verified_partner_id = isset($context['verified_partner_id']) ? (string) $context['verified_partner_id'] : '';
        if ($this->is_valid_partner_id($verified_partner_id)) {
            return true;
        }

        return false;
    }



    private function get_ip() {
        return sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }

    private function is_debug_logging_enabled() {
        $settings = $this->get_settings();
        return !empty($settings['debug_logging_enabled']);
    }

    private function log_event($level, $code, $data = []) {
        $lvl = strtoupper(trim((string) $level));
        $event = trim((string) $code);
        $payload = is_array($data) ? $data : ['message' => (string) $data];

        // Il flag debug governa solo eventi rumorosi/di sviluppo.
        $debug_only_events = [
            'BOOKING_WEBHOOK_SKIP_NO_PARTNER',
            'WEBHOOK_PARTNER_SKIP_NO_URL',
        ];
        $is_noisy = ($lvl === 'DEBUG'
            || strpos($event, 'DEBUG') !== false
            || strpos($event, 'PERF') !== false
            || in_array($event, $debug_only_events, true));
        if ($is_noisy && !$this->is_debug_logging_enabled()) {
            return;
        }

        $partner_id = isset($payload['partner_id']) && is_scalar($payload['partner_id'])
            ? sanitize_text_field((string) $payload['partner_id'])
            : '';
        $email = isset($payload['email']) && is_scalar($payload['email'])
            ? sanitize_email((string) $payload['email'])
            : '';
        $ip = isset($payload['ip']) && is_scalar($payload['ip'])
            ? sanitize_text_field((string) $payload['ip'])
            : '';
        $reason = isset($payload['reason']) && is_scalar($payload['reason'])
            ? sanitize_text_field((string) $payload['reason'])
            : '';
        $user_agent = isset($payload['user_agent']) && is_scalar($payload['user_agent'])
            ? sanitize_text_field((string) $payload['user_agent'])
            : '';

        $context_payload = [];
        if (array_key_exists('context', $payload)) {
            $raw_context = $payload['context'];
            if (is_array($raw_context)) {
                $context_payload = $raw_context;
            } elseif (is_scalar($raw_context) || is_null($raw_context)) {
                $context_payload = ['value' => $raw_context];
            } else {
                $context_payload = ['value' => (string) wp_json_encode($raw_context)];
            }
        }

        if (!array_key_exists('request_id', $context_payload)) {
            $context_payload['request_id'] = function_exists('wp_generate_uuid4')
                ? wp_generate_uuid4()
                : uniqid('', true);
        }
        if (!array_key_exists('source', $context_payload)) {
            $context_payload['source'] = 'plugin';
        }
        if (!array_key_exists('timestamp_unix', $context_payload)) {
            $context_payload['timestamp_unix'] = time();
        }
        if (!array_key_exists('url', $context_payload)) {
            $context_payload['url'] = sanitize_text_field((string) ($_SERVER['REQUEST_URI'] ?? ''));
        }
        if (!array_key_exists('method', $context_payload)) {
            $context_payload['method'] = sanitize_text_field((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        }
        if (!array_key_exists('group_key', $context_payload)) {
            $booking_id_for_group = '';
            if (array_key_exists('booking_id', $context_payload) && is_scalar($context_payload['booking_id'])) {
                $booking_id_for_group = trim((string) $context_payload['booking_id']);
            } elseif (isset($payload['booking_id']) && is_scalar($payload['booking_id'])) {
                $booking_id_for_group = trim((string) $payload['booking_id']);
            }

            if ($booking_id_for_group !== '') {
                $context_payload['group_key'] = 'booking_' . $booking_id_for_group;
            } elseif ($email !== '') {
                $context_payload['group_key'] = 'user_' . md5(strtolower($email));
            }
        }

        $unmapped = $payload;
        unset($unmapped['partner_id'], $unmapped['email'], $unmapped['ip'], $unmapped['reason'], $unmapped['user_agent'], $unmapped['context']);
        if (!empty($unmapped)) {
            $context_payload['_unmapped'] = $unmapped;
        }

        $json = function_exists('wp_json_encode') ? wp_json_encode($context_payload) : json_encode($context_payload);
        if ($json === false) {
            $json = '{}';
        }

        $insert_ok = false;
        if (!empty($this->table_logs)) {
            global $wpdb;
            if (isset($wpdb) && is_object($wpdb)) {
                $insert_ok = (false !== $wpdb->insert(
                    $this->table_logs,
                    [
                        'created_at' => current_time('mysql'),
                        'level' => $lvl,
                        'event_type' => $event,
                        'partner_id' => $partner_id,
                        'email' => $email,
                        'ip' => $ip,
                        'reason' => $reason,
                        'user_agent' => $user_agent,
                        'context' => $json,
                    ],
                    ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
                ));
            }
        }

        $critical_info_events = ['PARTNER_LOGIN_OK', 'PAYMENT_CALLBACK_OK', 'WEBHOOK_BOOKING_SENT', 'WEBHOOK_BOOKING_RESPONSE'];
        $is_critical = in_array($lvl, ['WARN', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)
            || in_array($event, $critical_info_events, true)
            || strpos($event, 'FAIL') !== false;

        // Manteniamo error_log per eventi critici e sempre come fallback DB.
        if ($is_critical || !$insert_ok || $this->is_debug_logging_enabled()) {
            error_log(sprintf('SOS_PG %s %s %s', $lvl, $event, $json));
        }
    }

    private function ban_key($ip) {
        return 'sos_pg_ban_' . md5($ip);
    }

    private function fail_short_key($ip) {
        return 'sos_pg_fail_short_' . md5($ip);
    }

    private function fail_long_key($ip) {
        return 'sos_pg_fail_long_' . md5($ip);
    }

    private function partner_login_ok_throttle_key($email, $ip) {
        $email = strtolower(trim((string) $email));
        $ip = trim((string) $ip);
        return 'sos_pg_partner_login_ok_' . md5($email . '|' . $ip);
    }

    private function public_key_resource($partner_id = '') {
        // Partner login must verify against partner-specific public key only
        if ($partner_id !== '') {
            $cfg = $this->partner_registry ? $this->partner_registry->get_partner_config($partner_id) : null;
            if (!$cfg || empty($cfg['public_key_pem'])) {
                return false;
            }
            $pem = trim((string) $cfg['public_key_pem']);
        } else {
            $pem = trim((string) ($this->get_settings()['public_key_pem'] ?? ''));
        }

        if ($pem === '') {
            return false;
        }

        return openssl_pkey_get_public($pem);
    }

    public function register_partner_page_metabox() {
        add_meta_box(
            'sos_pg_partner_page',
            __('SOS Partner Gateway', 'sos-pg'),
            [$this, 'render_partner_page_metabox'],
            'page',
            'side',
            'default'
        );
    }

    public function render_partner_page_metabox($post) {
        wp_nonce_field('sos_pg_save_partner_page', 'sos_pg_partner_page_nonce');

        $enabled         = (int) get_post_meta($post->ID, '_sos_pg_partner_enabled', true);
        $partner_id      = (string) get_post_meta($post->ID, '_sos_pg_partner_id', true);
        $redirect_path   = (string) get_post_meta($post->ID, '_sos_pg_redirect_path', true);
        $discount_amount = (string) get_post_meta($post->ID, '_sos_pg_discount_amount', true);
        $initial_status  = (string) get_post_meta($post->ID, '_sos_pg_initial_status', true);
        $location_label  = (string) get_post_meta($post->ID, '_sos_pg_location_label', true);

        echo '<p><label><input type="checkbox" name="sos_pg_partner_enabled" value="1" ' . checked($enabled, 1, false) . '> ' . esc_html__('Abilita pagina partner', 'sos-pg') . '</label></p>';

        echo '<p><label for="sos_pg_partner_id"><strong>' . esc_html__('Partner ID', 'sos-pg') . '</strong></label><br>';
        echo '<input type="text" id="sos_pg_partner_id" name="sos_pg_partner_id" value="' . esc_attr($partner_id) . '" class="widefat"></p>';

        echo '<p><label for="sos_pg_redirect_path"><strong>' . esc_html__('Redirect path', 'sos-pg') . '</strong></label><br>';
        echo '<input type="text" id="sos_pg_redirect_path" name="sos_pg_redirect_path" value="' . esc_attr($redirect_path) . '" class="widefat" placeholder="/pagina-destinazione"></p>';

        echo '<p><label for="sos_pg_discount_amount"><strong>' . esc_html__('Sconto', 'sos-pg') . '</strong></label><br>';
        echo '<input type="text" id="sos_pg_discount_amount" name="sos_pg_discount_amount" value="' . esc_attr($discount_amount) . '" class="widefat"></p>';

        echo '<p><label for="sos_pg_initial_status"><strong>' . esc_html__('Stato iniziale', 'sos-pg') . '</strong></label><br>';
        echo '<input type="text" id="sos_pg_initial_status" name="sos_pg_initial_status" value="' . esc_attr($initial_status) . '" class="widefat"></p>';

        echo '<p><label for="sos_pg_location_label"><strong>' . esc_html__('Location label', 'sos-pg') . '</strong></label><br>';
        echo '<input type="text" id="sos_pg_location_label" name="sos_pg_location_label" value="' . esc_attr($location_label) . '" class="widefat"></p>';
    }

    public function save_partner_page_meta($post_id, $post) {
        if (!isset($_POST['sos_pg_partner_page_nonce']) || !wp_verify_nonce($_POST['sos_pg_partner_page_nonce'], 'sos_pg_save_partner_page')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_page', $post_id)) {
            return;
        }

        update_post_meta($post_id, '_sos_pg_partner_enabled', isset($_POST['sos_pg_partner_enabled']) ? 1 : 0);
        update_post_meta($post_id, '_sos_pg_partner_id', sanitize_text_field(wp_unslash($_POST['sos_pg_partner_id'] ?? '')));
        update_post_meta($post_id, '_sos_pg_redirect_path', sanitize_text_field(wp_unslash($_POST['sos_pg_redirect_path'] ?? '')));
        update_post_meta($post_id, '_sos_pg_discount_amount', sanitize_text_field(wp_unslash($_POST['sos_pg_discount_amount'] ?? '')));
        update_post_meta($post_id, '_sos_pg_initial_status', sanitize_text_field(wp_unslash($_POST['sos_pg_initial_status'] ?? '')));
        update_post_meta($post_id, '_sos_pg_location_label', sanitize_text_field(wp_unslash($_POST['sos_pg_location_label'] ?? '')));
    }

    private function find_partner_page_by_partner_id($partner_id) {
        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => 1,
            'meta_query' => [
                ['key' => '_sos_pg_partner_enabled', 'value' => 1, 'compare' => '='],
                ['key' => '_sos_pg_partner_id', 'value' => $partner_id, 'compare' => '='],
            ],
        ]);

        return !empty($pages) ? $pages[0] : null;
    }

    private function get_redirect_url_for_page($page_id) {
        $custom = (string) get_post_meta($page_id, '_sos_pg_redirect_path', true);
        if ($custom !== '') {
            return home_url('/' . ltrim($custom, '/'));
        }
        return get_permalink($page_id);
    }

    public function protect_partner_pages() {
        if (is_admin() || wp_doing_ajax() || !is_page()) {
            return;
        }

        $post_id = get_queried_object_id();
        if (!$post_id) {
            return;
        }

        if (!(int) get_post_meta($post_id, '_sos_pg_partner_enabled', true)) {
            return;
        }

        $required_partner   = (string) get_post_meta($post_id, '_sos_pg_partner_id', true);
        $current_partner_id = $this->get_current_partner_id(); // checks WP user meta + cookie fallback

        error_log('SOS_PG HANDOFF PROTECT page=' . (int) $post_id . ' required_partner=' . $required_partner . ' current_partner=' . $current_partner_id);

        // No valid partner in session or cookie → redirect to login endpoint.
        // The login endpoint is handled at init (exits before template_redirect) → no loop.
        if (!$this->is_valid_partner_id($current_partner_id)) {
            $login_url = home_url($this->current_endpoint_path() . '/');

            // Courtesy-page fallback only if it is not itself a partner-protected page (anti-loop).
            $courtesy_page_id = (int) $this->get_settings()['courtesy_page_id'];
            $fallback_url = ($courtesy_page_id && !$this->is_partner_enabled_page_id($courtesy_page_id))
                ? get_permalink($courtesy_page_id)
                : home_url('/');

            // Prefer the login endpoint; use fallback if the slug somehow resolves to the same page.
            $redirect = (untrailingslashit($login_url) !== untrailingslashit((string) get_permalink($post_id)))
                ? $login_url
                : $fallback_url;

            $fallback_reason = (untrailingslashit($login_url) !== untrailingslashit((string) get_permalink($post_id)))
                ? 'missing_partner_session_redirect_to_login_endpoint'
                : 'missing_partner_session_login_equals_page_use_fallback';
            error_log('SOS_PG HANDOFF REDIRECT FALLBACK reason=' . $fallback_reason);
            error_log('SOS_PG HANDOFF REDIRECT FINAL url=' . $redirect);

            wp_safe_redirect($redirect);
            exit;
        }

        // Valid partner in session, but wrong partner for this specific page → hard block.
        if ($required_partner !== '' && $current_partner_id !== $required_partner) {
            $this->log_event('WARN', 'PAGE_BLOCKED_PARTNER_MISMATCH', [
                'partner_id' => $required_partner,
                'email'      => is_user_logged_in() ? wp_get_current_user()->user_email : '',
                'ip'         => $this->get_ip(),
                'reason'     => 'Utente con partner_id diverso',
            ]);
            wp_die('Accesso non consentito a questa pagina partner.', 'Accesso negato', ['response' => 403]);
        }

        // Access granted. Refresh the partner cookie so LatePoint pricing works in the wizard.
        $cookie_pid = sanitize_text_field((string) ($_COOKIE['sos_pg_partner_id'] ?? ''));
        if ($cookie_pid !== $current_partner_id) {
            setcookie('sos_pg_partner_id', $current_partner_id, time() + 4 * HOUR_IN_SECONDS, '/', '', is_ssl(), true);
        }
    }

    private function get_partner_identity_context_key($partner_id, $email) {
        $partner_key = $this->normalize_partner_id_key((string) $partner_id);
        $email = sanitize_email((string) $email);

        if ($partner_key === '' || $email === '') {
            return '';
        }

        return 'sos_pg_partner_identity_' . md5($partner_key . '|' . strtolower($email));
    }

    public function stash_partner_identity_context($partner_id, array $identity = []) {
        $partner_id = sanitize_text_field((string) $partner_id);
        $email = sanitize_email((string) ($identity['email'] ?? ''));
        $cache_key = $this->get_partner_identity_context_key($partner_id, $email);

        if ($cache_key === '') {
            return false;
        }

        $first_name = sanitize_text_field((string) ($identity['first_name'] ?? ''));
        $last_name = sanitize_text_field((string) ($identity['last_name'] ?? ''));
        $phone = sanitize_text_field((string) ($identity['phone'] ?? ''));
        $customer_name = sanitize_text_field((string) ($identity['customer_name'] ?? trim($first_name . ' ' . $last_name)));

        set_transient($cache_key, [
            'email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'phone' => $phone,
            'customer_name' => $customer_name,
        ], 2 * HOUR_IN_SECONDS);

        $this->log_event('INFO', 'PARTNER_IDENTITY_PROPAGATION', [
            'partner_id' => $partner_id,
            'context' => [
                'stage' => 'embedded_create',
                'email_present' => $email !== '',
                'first_name_present' => $first_name !== '',
                'last_name_present' => $last_name !== '',
                'phone_present' => $phone !== '',
            ],
        ]);

        return true;
    }

    private function load_partner_identity_context($partner_id, $email, $delete_after_read = false) {
        $cache_key = $this->get_partner_identity_context_key($partner_id, $email);
        if ($cache_key === '') {
            return [];
        }

        $stored = get_transient($cache_key);
        if ($delete_after_read) {
            delete_transient($cache_key);
        }

        return is_array($stored) ? $stored : [];
    }

    private function apply_partner_identity_context_to_user($user_id, $partner_id, $email) {
        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return [];
        }

        $stored = $this->load_partner_identity_context($partner_id, $email, true);

        $existing_first_name = (string) get_user_meta($user_id, 'first_name', true);
        $existing_last_name = (string) get_user_meta($user_id, 'last_name', true);
        $existing_phone = (string) get_user_meta($user_id, 'billing_phone', true);
        if ($existing_phone === '') {
            $existing_phone = (string) get_user_meta($user_id, 'phone', true);
        }
        $existing_customer_name = (string) get_user_meta($user_id, 'sos_pg_partner_customer_name', true);

        $first_name = $this->prefer_non_empty_value($stored['first_name'] ?? '', $existing_first_name);
        $last_name = $this->prefer_non_empty_value($stored['last_name'] ?? '', $existing_last_name);
        $phone = $this->prefer_non_empty_value($stored['phone'] ?? '', $existing_phone);
        $customer_name = $this->prefer_non_empty_value($stored['customer_name'] ?? trim($first_name . ' ' . $last_name), $existing_customer_name);

        if ($first_name !== '') {
            update_user_meta($user_id, 'first_name', $first_name);
            update_user_meta($user_id, 'sos_pg_partner_first_name', $first_name);
        }
        if ($last_name !== '') {
            update_user_meta($user_id, 'last_name', $last_name);
            update_user_meta($user_id, 'sos_pg_partner_last_name', $last_name);
        }
        if ($phone !== '') {
            update_user_meta($user_id, 'billing_phone', $phone);
            update_user_meta($user_id, 'phone', $phone);
            update_user_meta($user_id, 'sos_pg_partner_phone', $phone);
        }
        if ($customer_name !== '') {
            update_user_meta($user_id, 'sos_pg_partner_customer_name', $customer_name);
        }

        $context_flags = [
            'email_present' => $email !== '',
            'first_name_present' => $first_name !== '',
            'last_name_present' => $last_name !== '',
            'phone_present' => $phone !== '',
        ];

        $this->log_event('INFO', 'PARTNER_IDENTITY_PROPAGATION', [
            'partner_id' => $partner_id,
            'context' => array_merge(['stage' => 'handoff_login'], $context_flags),
        ]);

        $this->log_event('INFO', 'PARTNER_IDENTITY_PROPAGATION', [
            'partner_id' => $partner_id,
            'context' => array_merge(['stage' => 'partner_prefill'], $context_flags),
        ]);

        return [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'phone' => $phone,
            'customer_name' => $customer_name,
        ];
    }

    private function register_fail($reason, $partner_id = '', $email = '') {
        $settings = $this->get_settings();
        $ip = $this->get_ip();
        $ua = sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));

        $short_key = $this->fail_short_key($ip);
        $long_key = $this->fail_long_key($ip);
        $ban_key = $this->ban_key($ip);

        $short = (int) get_transient($short_key) + 1;
        $long = (int) get_transient($long_key) + 1;

        set_transient($short_key, $short, max(1, (int) $settings['window_short_minutes']) * MINUTE_IN_SECONDS);
        set_transient($long_key, $long, max(1, (int) $settings['window_long_minutes']) * MINUTE_IN_SECONDS);

        $this->log_event('WARN', 'PARTNER_LOGIN_FAIL', [
            'partner_id' => $partner_id,
            'email' => $email,
            'ip' => $ip,
            'reason' => $reason,
            'user_agent' => $ua,
            'context' => [
                'short_fails' => $short,
                'long_fails' => $long,
            ],
        ]);

        if ($short >= (int) $settings['max_fail_short']) {
            set_transient($ban_key, 1, max(1, (int) $settings['ban_short_minutes']) * MINUTE_IN_SECONDS);
            $this->log_event('WARN', 'PARTNER_LOGIN_BAN', [
                'partner_id' => $partner_id,
                'email' => $email,
                'ip' => $ip,
                'reason' => $reason . ' | durata: ' . (int) $settings['ban_short_minutes'] . ' minuti',
                'user_agent' => $ua,
            ]);
        } elseif ($long >= (int) $settings['max_fail_long']) {
            set_transient($ban_key, 1, max(1, (int) $settings['ban_long_minutes']) * MINUTE_IN_SECONDS);
            $this->log_event('WARN', 'PARTNER_LOGIN_BAN', [
                'partner_id' => $partner_id,
                'email' => $email,
                'ip' => $ip,
                'reason' => $reason . ' | durata: ' . (int) $settings['ban_long_minutes'] . ' minuti',
                'user_agent' => $ua,
            ]);
        }
    }

    public function handle_partner_login() {
        $endpoint = $this->current_endpoint_path();
        $request_path = $this->current_request_path();
        $timestamp_window = 120;

        if ($request_path !== $endpoint && $request_path !== $endpoint . '/') {
            return;
        }

        if (!$this->is_partner_context('partner_login')) {
            status_header(403);
            exit('Contesto partner non valido');
        }

        $incoming_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $incoming_query_partner = sanitize_text_field((string) ($_GET['partner_id'] ?? ''));
        error_log('SOS_PG HANDOFF ENTRY method=' . $incoming_method . ' url=' . (string) ($_SERVER['REQUEST_URI'] ?? '') . ' query_partner=' . $incoming_query_partner);

        $ip = $this->get_ip();
        $ua = sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));

        if (get_transient($this->ban_key($ip))) {
            $this->log_event('WARN', 'PARTNER_LOGIN_BLOCKED', [
                'ip' => $ip,
                'reason' => 'IP in ban temporaneo',
                'user_agent' => $ua,
            ]);
            status_header(429);
            exit('Troppi tentativi. Riprova più tardi.');
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->register_fail('Metodo non consentito');
            status_header(405);
            exit('Metodo non consentito');
        }

        $partner_id = sanitize_text_field(wp_unslash($_POST['partner_id'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['payload'] ?? ''));
        $timestamp = (int) wp_unslash($_POST['timestamp'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        $partner_return_url = $this->sanitize_partner_return_url((string) wp_unslash($_POST['return_url'] ?? ''));
        $opener_origin = $this->sanitize_opener_origin((string) wp_unslash($_POST['opener_origin'] ?? ''));
        $flow_context = sanitize_key((string) wp_unslash($_POST['sos_pg_flow_context'] ?? ''));
        if ($flow_context !== 'partner_wordpress_popup') {
            $flow_context = '';
        }
        $signature_b64 = (string) wp_unslash($_POST['signature'] ?? '');
        $signature = base64_decode($signature_b64, true);

        error_log('SOS_PG HANDOFF LOGIN INPUT partner=' . $partner_id . ' email=' . $email);
        error_log('[SOS SSO] handle_partner_login partner_id=' . $partner_id . ' requested_return_url=' . $partner_return_url);

        if ($partner_id === '') {
            $this->register_fail('missing_partner_id');
            status_header(400);
            exit('Partner non valido');
        }

        if ($email === '' || !is_email($email)) {
            $this->register_fail('invalid_email', $partner_id, $email);
            status_header(400);
            exit('Email non valida');
        }

        if (!$timestamp || abs(time() - $timestamp) > $timestamp_window) {
            $server_timestamp = time();
            $this->log_event('WARN', 'PARTNER_LOGIN_TIMESTAMP_INVALID', [
                'partner_id' => $partner_id,
                'email' => $email,
                'reason' => 'timestamp_expired',
                'context' => [
                    'incoming_timestamp' => (int) $timestamp,
                    'server_timestamp' => $server_timestamp,
                    'skew_seconds' => abs($server_timestamp - (int) $timestamp),
                    'allowed_window_seconds' => $timestamp_window,
                ],
            ]);
            $this->register_fail('timestamp_expired', $partner_id, $email);
            status_header(403);
            exit('Richiesta scaduta');
        }

        if ($nonce === '') {
            $this->register_fail('replay_nonce', $partner_id, $email);
            status_header(400);
            exit('Nonce mancante');
        }

        if ($signature === false || empty($signature)) {
            $this->register_fail('invalid_signature', $partner_id, $email);
            status_header(400);
            exit('Firma non valida');
        }

        $partner_cfg = $this->partner_registry ? $this->partner_registry->get_partner_config($partner_id) : null;
        $has_partner_key = is_array($partner_cfg) && !empty($partner_cfg['public_key_pem']);

        $public_key = $this->public_key_resource($partner_id);
        if (!$public_key) {
            $this->log_event('ERROR', 'PARTNER_LOGIN_KEY_ERROR', [
                'partner_id' => $partner_id,
                'email' => $email,
                'ip' => $ip,
                'reason' => $has_partner_key ? 'public_key_invalid' : 'public_key_missing',
                'user_agent' => $ua,
            ]);
            status_header(500);
            exit('Chiave pubblica non valida');
        }

        $message = $partner_id . '|' . $email . '|' . $timestamp . '|' . $nonce;

        $ok = openssl_verify($message, $signature, $public_key, OPENSSL_ALGO_SHA256);

        if ($ok !== 1) {
            $this->register_fail('invalid_signature', $partner_id, $email);
            status_header(403);
            exit('Firma non valida');
        }

        error_log('SOS_PG HANDOFF LOGIN OK partner=' . $partner_id);

        $used_nonce_key = 'sos_pg_used_nonce_' . md5($partner_id . '|' . $nonce);
        if (get_transient($used_nonce_key)) {
            $this->register_fail('replay_nonce', $partner_id, $email);
            status_header(403);
            exit('Firma non valida');
        }
        set_transient($used_nonce_key, 1, $timestamp_window);

        $page = $this->find_partner_page_by_partner_id($partner_id);
        $routes = $this->get_partner_routes();
        $route = $routes[$partner_id] ?? '';
        if ($route === '') {
            $route = $routes[strtolower($partner_id)] ?? ($routes[strtoupper($partner_id)] ?? '');
        }

        if ($route !== '') {
            error_log('SOS_PG HANDOFF REDIRECT route_found=' . $route);
        } else {
            error_log('SOS_PG HANDOFF REDIRECT route_found=none');
        }

        if ($page) {
            error_log('SOS_PG HANDOFF REDIRECT page_found=' . (int) $page->ID . ' url=' . $this->get_redirect_url_for_page($page->ID));
        } else {
            error_log('SOS_PG HANDOFF REDIRECT page_found=none partner=' . $partner_id);
        }

        if ($route) {
            $redirect_url = (strpos($route, 'http://') === 0 || strpos($route, 'https://') === 0)
                ? $route
                : home_url($route);
            error_log('SOS_PG HANDOFF REDIRECT FALLBACK reason=route_match');
        } elseif ($page) {
            $redirect_url = $this->get_redirect_url_for_page($page->ID);
            error_log('SOS_PG HANDOFF REDIRECT FALLBACK reason=partner_page_match');
        } else {
            error_log('SOS_PG HANDOFF REDIRECT FALLBACK reason=missing_partner_route_and_page');
            $this->register_fail('pagina partner non configurata', $partner_id, $email);
            status_header(404);
            exit('Pagina partner non configurata');
        }

        error_log('SOS_PG HANDOFF REDIRECT FINAL url=' . $redirect_url);
        error_log('SOS_PG HANDOFF REDIRECT PROPAGATION partner=' . $partner_id . ' target=' . $redirect_url);
        error_log('[SOS SSO] handle_partner_login resolved_login_target partner_id=' . $partner_id . ' login_target=' . $redirect_url . ' return_target=' . ($partner_return_url !== '' ? $partner_return_url : ''));

        $user = get_user_by('email', $email);
        $is_new_user = false;

        if (!$user) {
            $user_id = wp_create_user($email, wp_generate_password(20, true, true), $email);

            if (is_wp_error($user_id)) {
                // The username (email) may already exist even if the email lookup failed.
                // Try to recover the existing user by login before treating this as a fatal error.
                $user = get_user_by('login', $email);

                if (!$user) {
                    $this->log_event('ERROR', 'PARTNER_LOGIN_CREATE_USER_ERROR', [
                        'partner_id' => $partner_id,
                        'email' => $email,
                        'ip' => $ip,
                        'reason' => $user_id->get_error_message(),
                        'user_agent' => $ua,
                    ]);
                    status_header(500);
                    exit('Errore creazione utente');
                }
            } else {
                $user = get_user_by('id', $user_id);
                $is_new_user = true;
            }
        }

        update_user_meta($user->ID, 'sos_pg_partner_id', $partner_id);
        update_user_meta($user->ID, 'sos_pg_partner_last_login', time());
        update_user_meta($user->ID, 'sos_pg_partner_target_page', $redirect_url);
        $identity_context = $this->apply_partner_identity_context_to_user($user->ID, $partner_id, $email);
        $first_name = sanitize_text_field((string) ($identity_context['first_name'] ?? ''));
        $last_name = sanitize_text_field((string) ($identity_context['last_name'] ?? ''));
        $phone = sanitize_text_field((string) ($identity_context['phone'] ?? ''));
        if ($partner_return_url !== '') {
            update_user_meta($user->ID, 'sos_pg_partner_return_url', $partner_return_url);
        } else {
            delete_user_meta($user->ID, 'sos_pg_partner_return_url');
        }
        if ($opener_origin !== '') {
            update_user_meta($user->ID, 'sos_pg_partner_opener_origin', $opener_origin);
        } else {
            delete_user_meta($user->ID, 'sos_pg_partner_opener_origin');
        }
        if ($flow_context !== '') {
            update_user_meta($user->ID, 'sos_pg_partner_flow_context', $flow_context);
        } else {
            delete_user_meta($user->ID, 'sos_pg_partner_flow_context');
        }

        wp_clear_auth_cookie();
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        do_action('wp_login', $user->user_login, $user);

        delete_transient($this->fail_short_key($ip));
        delete_transient($this->fail_long_key($ip));
        delete_transient($this->ban_key($ip));

        $ok_log_key = $this->partner_login_ok_throttle_key($email, $ip);
        if (!get_transient($ok_log_key)) {
            $this->log_event('INFO', 'PARTNER_LOGIN_OK', [
                'partner_id' => $partner_id,
                'email' => $email,
                'ip' => $ip,
                'reason' => $is_new_user ? 'new_user' : 'existing_user',
                'user_agent' => $ua,
                'context' => [
                    'timestamp' => $timestamp,
                    'redirect' => $redirect_url,
                ],
            ]);
            set_transient($ok_log_key, 1, 5 * MINUTE_IN_SECONDS);
        }

        // Cookie di cortesia per frontend LatePoint se la sessione WP viene persa.
        setcookie('sos_pg_partner_id', $partner_id, time() + 4 * HOUR_IN_SECONDS, '/', '', is_ssl(), true);

        error_log('[SOS SSO] handle_partner_login redirect_final partner_id=' . $partner_id . ' login_target=' . $redirect_url);
        wp_safe_redirect($redirect_url);
        exit;
    }

    private function get_logs($limit = 300, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_logs} ORDER BY id DESC LIMIT %d OFFSET %d",
                max(1, min(1000, (int) $limit)),
                max(0, (int) $offset)
            )
        );
    }

    private function get_logs_count() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_logs}" );
    }

    private function resolve_partner_route_target($partner_id) {
        $partner_id = trim((string) $partner_id);
        if ($partner_id === '') {
            return '';
        }

        $routes = $this->get_partner_routes();
        $route = $routes[$partner_id] ?? '';
        if ($route === '') {
            $route = $routes[strtolower($partner_id)] ?? ($routes[strtoupper($partner_id)] ?? '');
        }

        if ($route === '') {
            return '';
        }

        return (strpos($route, 'http://') === 0 || strpos($route, 'https://') === 0)
            ? $route
            : home_url($route);
    }

    private function resolve_partner_page_target($partner_id) {
        $page = $this->find_partner_page_by_partner_id($partner_id);
        return $page ? $this->get_redirect_url_for_page($page->ID) : '';
    }

    private function validate_completion_return_url($url) {
        $url = esc_url_raw((string) $url);
        if ($url === '' || !wp_http_validate_url($url)) {
            return '';
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        return $url;
    }

    private function resolve_partner_configured_return_url($partner_id = '') {
        $partner_id = trim((string) $partner_id);
        if ($partner_id !== '') {
            $partner_cfg = $this->partner_registry ? $this->partner_registry->get_partner_config($partner_id) : null;
            $completion_return_url = is_array($partner_cfg)
                ? $this->validate_completion_return_url((string) ($partner_cfg['completion_return_url'] ?? ''))
                : '';
            if ($completion_return_url !== '') {
                error_log('[SOS SSO] completion final return source=completion_return_url target=' . $completion_return_url);
                return $completion_return_url;
            }

            $route_target = $this->resolve_partner_route_target($partner_id);
            if ($route_target !== '' && wp_http_validate_url($route_target)) {
                error_log('[SOS SSO] completion final return source=config_route target=' . $route_target);
                return $route_target;
            }

            $page_target = $this->resolve_partner_page_target($partner_id);
            if ($page_target !== '' && wp_http_validate_url($page_target)) {
                error_log('[SOS SSO] completion final return source=config_page target=' . $page_target);
                return $page_target;
            }

            error_log('[SOS SSO] completion final return source=login_endpoint target=' . $this->get_login_endpoint_url());
            return $this->get_login_endpoint_url();
        }

        error_log('[SOS SSO] completion final return source=home target=' . home_url('/'));
        return home_url('/');
    }

    private function resolve_partner_return_url($partner_id = '') {
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            $stored = trim((string) get_user_meta($user_id, 'sos_pg_partner_return_url', true));
            if ($stored !== '' && wp_http_validate_url($stored)) {
                error_log('[SOS SSO] completion return target source=saved_return_url target=' . $stored);
                return $stored;
            }
        }

        $partner_id = trim((string) $partner_id);
        if ($partner_id !== '') {
            $route_target = $this->resolve_partner_route_target($partner_id);
            if ($route_target !== '' && wp_http_validate_url($route_target)) {
                error_log('[SOS SSO] completion return target source=route target=' . $route_target);
                return $route_target;
            }

            $page_target = $this->resolve_partner_page_target($partner_id);
            if ($page_target !== '' && wp_http_validate_url($page_target)) {
                error_log('[SOS SSO] completion return target source=page target=' . $page_target);
                return $page_target;
            }

            error_log('[SOS SSO] completion return target source=login_endpoint target=' . $this->get_login_endpoint_url());
            return $this->get_login_endpoint_url();
        }

        error_log('[SOS SSO] completion return target source=home target=' . home_url('/'));
        return home_url('/');
    }

    private function resolve_partner_completion_final_url($partner_id = '') {
        $partner_id = trim((string) $partner_id);
        if ($partner_id !== '') {
            $partner_cfg = $this->partner_registry ? $this->partner_registry->get_partner_config($partner_id) : null;
            $completion_return_url = is_array($partner_cfg)
                ? $this->validate_completion_return_url((string) ($partner_cfg['completion_return_url'] ?? ''))
                : '';
            if ($completion_return_url !== '') {
                error_log('[SOS SSO] completion final target source=completion_return_url target=' . $completion_return_url);
                return $completion_return_url;
            }
        }

        return $this->resolve_partner_return_url($partner_id);
    }

    private function should_use_partner_payment_completion_return($partner_id, $record = null) {
        $partner_id = trim((string) $partner_id);
        if ($partner_id === '') {
            return false;
        }

        if (strtolower($partner_id) !== 'caf') {
            return false;
        }

        if ($record !== null && isset($record->partner_charge) && (float) $record->partner_charge > 0) {
            return true;
        }

        $discount_config = $this->get_partner_discount_config($partner_id);
        return !empty($discount_config['pay_on_partner']);
    }

    private function get_booking_row($booking_id) {
        $booking_id = absint($booking_id);
        if ($booking_id <= 0) {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->booking_table} WHERE id = %d LIMIT 1",
                $booking_id
            )
        );

        return $row ?: null;
    }

    private function get_booking_customer_email($booking = null) {
        $email = sanitize_email((string) $this->safe_get($booking, 'customer_email'));
        if ($email !== '') {
            return $email;
        }

        $email = sanitize_email((string) $this->safe_get_nested($booking, ['customer', 'email']));
        if ($email !== '') {
            return $email;
        }

        $customer_id = absint($this->safe_get($booking, 'customer_id'));
        if ($customer_id <= 0) {
            return '';
        }

        global $wpdb;
        $customer_table = $wpdb->prefix . 'latepoint_customers';
        $email = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT email FROM {$customer_table} WHERE id = %d LIMIT 1",
                $customer_id
            )
        );

        return is_scalar($email) ? sanitize_email((string) $email) : '';
    }

    private function prefer_non_empty_value($incoming, $existing) {
        $incoming = is_scalar($incoming) ? trim(sanitize_text_field((string) $incoming)) : '';
        $existing = is_scalar($existing) ? trim(sanitize_text_field((string) $existing)) : '';

        if ($incoming !== '') {
            return $incoming;
        }

        if ($existing !== '') {
            return $existing;
        }

        return '';
    }

    private function split_full_name_fallback($full_name) {
        $full_name = is_scalar($full_name) ? trim(sanitize_text_field((string) $full_name)) : '';
        if ($full_name === '') {
            return [
                'first_name' => '',
                'last_name' => '',
            ];
        }

        $parts = preg_split('/\s+/', $full_name);
        if (!is_array($parts) || empty($parts)) {
            return [
                'first_name' => $full_name,
                'last_name' => '',
            ];
        }

        $first_name = trim((string) array_shift($parts));
        $last_name = trim(implode(' ', $parts));

        return [
            'first_name' => $first_name,
            'last_name' => $last_name,
        ];
    }

    private function resolve_customer_identity_fields($payload, $user_id = 0, $context = array()) {
        $normalize = static function($value) {
            if (!is_scalar($value)) {
                return '';
            }

            $value = trim(sanitize_text_field((string) $value));
            return $value === '' ? '' : $value;
        };

        $first_non_empty = static function(array $candidates) use ($normalize) {
            foreach ($candidates as $candidate) {
                $value = $normalize($candidate);
                if ($value !== '') {
                    return $value;
                }
            }
            return '';
        };

        $booking = (is_array($context) && array_key_exists('booking', $context)) ? $context['booking'] : null;
        $user = $user_id > 0 ? get_userdata($user_id) : false;

        $resolved = [
            'first_name' => '',
            'last_name' => '',
            'phone' => '',
            'sources' => [
                'first_name' => 'missing',
                'last_name' => 'missing',
                'phone' => 'missing',
            ],
        ];

        $payload_name = $first_non_empty([
            $this->safe_get_nested($payload, ['customer', 'full_name']),
            $this->safe_get($payload, 'customer_name'),
            $this->safe_get($payload, 'full_name'),
            $this->safe_get($payload, 'name'),
            $this->safe_get($payload, 'display_name'),
        ]);
        $payload_name_parts = $this->split_full_name_fallback($payload_name);

        $payload_first_name = $first_non_empty([
            $this->safe_get_nested($payload, ['customer', 'first_name']),
            $this->safe_get($payload, 'customer_first_name'),
            $this->safe_get($payload, 'first_name'),
            $this->safe_get($payload, 'firstname'),
        ]);
        if ($payload_first_name !== '') {
            $resolved['first_name'] = $payload_first_name;
            $resolved['sources']['first_name'] = 'partner_payload';
        } elseif ($payload_name_parts['first_name'] !== '') {
            $resolved['first_name'] = $payload_name_parts['first_name'];
            $resolved['sources']['first_name'] = 'partner_payload_name';
        }

        $payload_last_name = $first_non_empty([
            $this->safe_get_nested($payload, ['customer', 'last_name']),
            $this->safe_get($payload, 'customer_last_name'),
            $this->safe_get($payload, 'last_name'),
            $this->safe_get($payload, 'lastname'),
        ]);
        if ($payload_last_name !== '') {
            $resolved['last_name'] = $payload_last_name;
            $resolved['sources']['last_name'] = 'partner_payload';
        } elseif ($payload_name_parts['last_name'] !== '') {
            $resolved['last_name'] = $payload_name_parts['last_name'];
            $resolved['sources']['last_name'] = 'partner_payload_name';
        }

        $payload_phone = $first_non_empty([
            $this->safe_get_nested($payload, ['customer', 'phone']),
            $this->safe_get($payload, 'customer_phone'),
            $this->safe_get($payload, 'phone'),
        ]);
        if ($payload_phone !== '') {
            $resolved['phone'] = $payload_phone;
            $resolved['sources']['phone'] = 'partner_payload';
        }

        if ($user instanceof WP_User) {
            $user_display_name_parts = $this->split_full_name_fallback($user->display_name);

            if ($resolved['first_name'] === '') {
                $user_first_name = $first_non_empty([
                    $user->first_name,
                    get_user_meta($user_id, 'first_name', true),
                ]);
                if ($user_first_name !== '') {
                    $resolved['first_name'] = $user_first_name;
                    $resolved['sources']['first_name'] = 'wp_user';
                } elseif ($user_display_name_parts['first_name'] !== '') {
                    $resolved['first_name'] = $user_display_name_parts['first_name'];
                    $resolved['sources']['first_name'] = 'wp_user_display_name';
                }
            }

            if ($resolved['last_name'] === '') {
                $user_last_name = $first_non_empty([
                    $user->last_name,
                    get_user_meta($user_id, 'last_name', true),
                ]);
                if ($user_last_name !== '') {
                    $resolved['last_name'] = $user_last_name;
                    $resolved['sources']['last_name'] = 'wp_user';
                } elseif ($user_display_name_parts['last_name'] !== '') {
                    $resolved['last_name'] = $user_display_name_parts['last_name'];
                    $resolved['sources']['last_name'] = 'wp_user_display_name';
                }
            }

            if ($resolved['phone'] === '') {
                $user_phone = $first_non_empty([
                    get_user_meta($user_id, 'billing_phone', true),
                    get_user_meta($user_id, 'phone', true),
                    get_user_meta($user_id, 'mobile_phone', true),
                ]);
                if ($user_phone !== '') {
                    $resolved['phone'] = $user_phone;
                    $resolved['sources']['phone'] = 'wp_user';
                }
            }
        }

        $booking_name = $first_non_empty([
            $this->safe_get_nested($booking, ['customer', 'full_name']),
            $this->safe_get($booking, 'customer_name'),
            $this->safe_get($booking, 'full_name'),
            $this->safe_get($booking, 'name'),
            $this->safe_get($booking, 'display_name'),
        ]);
        $booking_name_parts = $this->split_full_name_fallback($booking_name);

        if ($resolved['first_name'] === '') {
            $booking_first_name = $first_non_empty([
                $this->safe_get_nested($booking, ['customer', 'first_name']),
                $this->safe_get($booking, 'customer_first_name'),
                $this->safe_get($booking, 'first_name'),
            ]);
            if ($booking_first_name !== '') {
                $resolved['first_name'] = $booking_first_name;
                $resolved['sources']['first_name'] = 'booking_meta';
            } elseif ($booking_name_parts['first_name'] !== '') {
                $resolved['first_name'] = $booking_name_parts['first_name'];
                $resolved['sources']['first_name'] = 'booking_name_fallback';
            }
        }

        if ($resolved['last_name'] === '') {
            $booking_last_name = $first_non_empty([
                $this->safe_get_nested($booking, ['customer', 'last_name']),
                $this->safe_get($booking, 'customer_last_name'),
                $this->safe_get($booking, 'last_name'),
            ]);
            if ($booking_last_name !== '') {
                $resolved['last_name'] = $booking_last_name;
                $resolved['sources']['last_name'] = 'booking_meta';
            } elseif ($booking_name_parts['last_name'] !== '') {
                $resolved['last_name'] = $booking_name_parts['last_name'];
                $resolved['sources']['last_name'] = 'booking_name_fallback';
            }
        }

        if ($resolved['phone'] === '') {
            $booking_phone = $first_non_empty([
                $this->safe_get_nested($booking, ['customer', 'phone']),
                $this->safe_get($booking, 'customer_phone'),
                $this->safe_get($booking, 'phone'),
            ]);
            if ($booking_phone !== '') {
                $resolved['phone'] = $booking_phone;
                $resolved['sources']['phone'] = 'booking_meta';
            }
        }

        return $resolved;
    }

    private function build_partner_payment_completion_return_url($base_url, $partner_id, $booking_id, $record = null) {
        $base_url = $this->validate_completion_return_url($base_url);
        $partner_id = sanitize_text_field((string) $partner_id);
        $booking_id = absint($booking_id);
        $incoming_requested_booking_id = $booking_id;
        $is_caf_partner = ($this->normalize_partner_id_key($partner_id) === 'caf');

        if ($base_url === '' || $partner_id === '' || $booking_id <= 0) {
            return $base_url;
        }

        if (!$this->should_use_partner_payment_completion_return($partner_id, $record)) {
            return $base_url;
        }

        $latepoint_booking_id = $booking_id;
        if ($is_caf_partner) {
            $latepoint_booking_id = ($record !== null && isset($record->lp_booking_id)) ? absint($record->lp_booking_id) : 0;
            if ($latepoint_booking_id <= 0) {
                error_log('[SOS SSO] FAKE_PAYMENT_COMPLETION_URL_ABORT_NO_VERIFIED_RECORD ' . wp_json_encode([
                    'incoming_requested_booking_id' => $incoming_requested_booking_id,
                    'partner_record_id' => absint($this->safe_get($record, 'id')),
                    'partner_record_lp_booking_id' => absint($this->safe_get($record, 'lp_booking_id')),
                    'reason' => $record === null ? 'missing_record' : 'missing_lp_booking_id',
                    'base_url' => $base_url,
                ]));
                return $base_url;
            }
        } elseif ($record !== null && isset($record->lp_booking_id) && absint($record->lp_booking_id) > 0) {
            $latepoint_booking_id = absint($record->lp_booking_id);
        }

        $booking = $this->get_booking_row($latepoint_booking_id);
        $booking_id_real = absint($this->safe_get($booking, 'id'));
        if ($booking_id_real <= 0) {
            $booking_id_real = $latepoint_booking_id;
        }
        $email = $this->get_booking_customer_email($booking);
        $service_name = $this->get_booking_service_name($this->safe_get($booking, 'service_id'), $booking);
        $booking_datetime = $this->build_booking_datetime_value(
            $this->safe_get($booking, 'start_date'),
            $this->safe_get($booking, 'start_time')
        );
        $external_reference = $this->resolve_booking_external_reference($partner_id, $booking_id_real, $booking, $email);

        $amount = null;
        if ($record !== null && isset($record->partner_charge) && $record->partner_charge !== null && (float) $record->partner_charge > 0) {
            $amount = (float) $record->partner_charge;
        } else {
            $booking_total = $this->safe_get($booking, 'total');
            if ($booking_total !== '') {
                $amount = (float) $booking_total;
            }
        }

        $query_args = [
            'fake_payment' => '1',
            'booking_id' => $booking_id_real,
            'partner_id' => $partner_id,
        ];

        if ($email !== '') {
            $query_args['email'] = $email;
        }
        if ($service_name !== '') {
            $query_args['service'] = $service_name;
        }
        if ($booking_datetime !== '') {
            $query_args['datetime'] = $booking_datetime;
        }
        if ($amount !== null) {
            $query_args['amount'] = $amount;
        }
        if ($external_reference !== '') {
            $query_args['external_reference'] = $external_reference;
        }

        $dynamic_url = add_query_arg($query_args, $base_url);
        $dynamic_url = $this->validate_completion_return_url($dynamic_url);

        if ($dynamic_url !== '') {
            error_log('[SOS SSO] FAKE_PAYMENT_COMPLETION_URL ' . wp_json_encode([
                'incoming_requested_booking_id' => $incoming_requested_booking_id,
                'incoming_external_reference' => $this->safe_get($record, 'payment_external_ref'),
                'partner_record_id' => absint($this->safe_get($record, 'id')),
                'partner_record_lp_booking_id' => absint($this->safe_get($record, 'lp_booking_id')),
                'final_booking_id_used' => $booking_id_real,
                'final_external_reference_used' => $external_reference,
                'final_fake_gateway_url' => $dynamic_url,
            ]));
            if ($this->should_use_partner_payment_completion_return($partner_id, $record) && absint($this->safe_get($record, 'lp_booking_id')) <= 0) {
                error_log('[SOS SSO] FAKE_PAYMENT_COMPLETION_URL_WARN missing_lp_booking_id ' . wp_json_encode([
                    'incoming_requested_booking_id' => $incoming_requested_booking_id,
                    'incoming_external_reference' => $this->safe_get($record, 'payment_external_ref'),
                    'partner_record_id' => absint($this->safe_get($record, 'id')),
                    'partner_record_lp_booking_id' => absint($this->safe_get($record, 'lp_booking_id')),
                    'final_booking_id_used' => $booking_id_real,
                    'final_external_reference_used' => $external_reference,
                    'final_fake_gateway_url' => $dynamic_url,
                ]));
            }
            error_log('[SOS SSO] completion final target source=completion_return_url_dynamic target=' . $dynamic_url);
            return $dynamic_url;
        }

        return $base_url;
    }

    public function enqueue_partner_completion_monitor_script() {
        $request_path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $is_partner_caf_path = strpos($request_path, '/partner-caf') !== false;
        if ($is_partner_caf_path) {
            error_log('[SOS SSO] ENQUEUE FUNCTION ENTERED');
        }
        $queried_post_id = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
        $is_page_request = is_page();
        $partner_enabled_meta = $queried_post_id > 0 ? (string) get_post_meta($queried_post_id, '_sos_pg_partner_enabled', true) : '';
        $resolved_partner_id = $this->get_current_partner_id();

        if ($is_partner_caf_path) {
            error_log('[SOS SSO] completion monitor runtime path=' . $request_path
                . ' is_admin=' . (is_admin() ? '1' : '0')
                . ' wp_doing_ajax=' . (wp_doing_ajax() ? '1' : '0')
                . ' is_page=' . ($is_page_request ? '1' : '0')
                . ' queried_object_id=' . $queried_post_id
                . ' partner_enabled_meta=' . ($partner_enabled_meta !== '' ? $partner_enabled_meta : '(empty)')
                . ' resolved_partner_id=' . ($resolved_partner_id !== '' ? $resolved_partner_id : '(empty)'));
        }

        if (is_admin()) {
            if ($is_partner_caf_path) {
                error_log('[SOS SSO] completion monitor skip reason=is_admin path=' . $request_path);
            }
            return;
        }

        if (wp_doing_ajax()) {
            if ($is_partner_caf_path) {
                error_log('[SOS SSO] completion monitor skip reason=wp_doing_ajax path=' . $request_path);
            }
            return;
        }

        if (!$is_page_request) {
            if ($is_partner_caf_path) {
                error_log('[SOS SSO] completion monitor skip reason=not_is_page path=' . $request_path . ' queried_object_id=' . $queried_post_id);
            }
            return;
        }

        $post_id = $queried_post_id;
        if (!$post_id) {
            if ($is_partner_caf_path) {
                error_log('[SOS SSO] completion monitor skip reason=empty_post_id path=' . $request_path);
            }
            return;
        }

        if (!$this->is_partner_enabled_page_id($post_id)) {
            if ($is_partner_caf_path) {
                error_log('[SOS SSO] completion monitor skip reason=partner_page_not_enabled path=' . $request_path . ' post_id=' . $post_id . ' partner_enabled_meta=' . ($partner_enabled_meta !== '' ? $partner_enabled_meta : '(empty)'));
            }
            return;
        }

        $partner_id = $resolved_partner_id;
        if (!$this->is_valid_partner_id($partner_id)) {
            if ($is_partner_caf_path) {
                error_log('[SOS SSO] completion monitor skip reason=invalid_partner_id path=' . $request_path . ' post_id=' . $post_id . ' resolved_partner_id=' . ($partner_id !== '' ? $partner_id : '(empty)'));
            }
            return;
        }

        $script_relative_path = 'assets/js/partner-completion-monitor.js';
        $script_path = SOS_PG_DIR . $script_relative_path;
        if (!is_readable($script_path)) {
            if ($is_partner_caf_path) {
                error_log('[SOS SSO] completion monitor skip reason=asset_not_readable path=' . $request_path . ' script_path=' . $script_path);
            }
            return;
        }

        if ($is_partner_caf_path) {
            error_log('[SOS SSO] completion monitor enqueue path=' . $request_path . ' post_id=' . $post_id . ' partner_id=' . $partner_id . ' script_path=' . $script_path);
        }

        $script_url = plugin_dir_url(SOS_PG_FILE) . $script_relative_path;
        $script_version = '2026-04-14-2';
        wp_enqueue_script('sos-pg-partner-completion-monitor', $script_url, [], $script_version, true);
        $current_user_id = get_current_user_id();
        $flow_context = $current_user_id > 0
            ? sanitize_key((string) get_user_meta($current_user_id, 'sos_pg_partner_flow_context', true))
            : '';
        $opener_origin = $current_user_id > 0
            ? $this->sanitize_opener_origin((string) get_user_meta($current_user_id, 'sos_pg_partner_opener_origin', true))
            : '';
        $popup_partner_wordpress_flow = ($flow_context === 'partner_wordpress_popup');

        if ($current_user_id > 0) {
            // These values are single-flow hints for the current frontend monitor and must not leak into later flows.
            delete_user_meta($current_user_id, 'sos_pg_partner_flow_context');
            delete_user_meta($current_user_id, 'sos_pg_partner_opener_origin');
        }

        wp_add_inline_script(
            'sos-pg-partner-completion-monitor',
            'window.SOSPGCompletionMonitor=' . wp_json_encode([
                'completionUrlEndpoint' => add_query_arg('sos_pg_completion_url', '1', home_url('/')),
                'completionPageUrl' => home_url($this->current_completion_path() . '/'),
                'completionReturnUrl' => $this->resolve_partner_configured_return_url($partner_id),
                'partnerReturnUrl' => $this->resolve_partner_return_url($partner_id),
                'openerOrigin' => $opener_origin,
                'partnerId' => $partner_id,
                // Popup partner WordPress must return through /partner-completion first.
                'popupPartnerWordpressFlow' => $popup_partner_wordpress_flow,
                'flowContext' => $flow_context,
                'patchMarker' => 'completion-return-patch-hard-20260407-2',
                'scriptVersion' => $script_version,
                'source' => 'latepoint_success_monitor',
            ]) . ';',
            'before'
        );
    }

    private function get_partner_completion_view_model($state_key, $return_url, array $args = []) {
        $map = [
            'booking_completed' => [
                'title' => 'Prenotazione completata',
                'message' => 'La prenotazione e stata completata correttamente.',
            ],
            'payment_pending' => [
                'title' => 'Prenotazione creata, pagamento in attesa conferma partner',
                'message' => 'La prenotazione e stata creata. Il pagamento risulta ancora in attesa di conferma dal partner.',
            ],
            'payment_recorded' => [
                'title' => 'Pagamento registrato',
                'message' => 'Il pagamento risulta registrato correttamente.',
            ],
            'unavailable' => [
                'title' => 'Stato non disponibile',
                'message' => 'Non e stato possibile determinare lo stato finale del flusso partner.',
            ],
        ];

        $state = $map[$state_key] ?? $map['unavailable'];

        return [
            'state' => $state_key,
            'title' => $state['title'],
            'message' => $state['message'],
            'return_url' => $return_url,
            'button_label' => 'Torna al sito',
            'booking_id' => (int) ($args['booking_id'] ?? 0),
            'partner_id' => (string) ($args['partner_id'] ?? ''),
            'phase' => (string) ($args['phase'] ?? ''),
            'source' => (string) ($args['source'] ?? ''),
            'opener_origin' => (string) ($args['opener_origin'] ?? ''),
        ];
    }

    private function render_partner_completion_page(array $view) {
        $payload = [
            'type' => 'sos_partner_login_complete',
            'legacyType' => 'sos_pg_completion',
            'state' => (string) ($view['state'] ?? 'unavailable'),
            'bookingId' => (int) ($view['booking_id'] ?? 0),
            'partnerId' => (string) ($view['partner_id'] ?? ''),
            'phase' => (string) ($view['phase'] ?? ''),
            'source' => (string) ($view['source'] ?? ''),
            'returnUrl' => (string) ($view['return_url'] ?? ''),
        ];

        nocache_headers();
        status_header(200);
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));

        $title = 'Prenotazione completata';
        $message = 'Stiamo tornando al sito del partner...';
        $button_label = esc_html((string) ($view['button_label'] ?? 'Torna al sito'));
        $return_url = esc_url((string) ($view['return_url'] ?? home_url('/')));
        $state_class = esc_attr((string) ($view['state'] ?? 'unavailable'));
        $payload_json = wp_json_encode($payload);
        $return_origin = $this->sanitize_opener_origin((string) ($view['return_url'] ?? ''));
        $requested_opener_origin = $this->sanitize_opener_origin((string) ($view['opener_origin'] ?? ''));
        $current_site_origin = $this->sanitize_opener_origin(home_url('/'));
        // Prefer the explicit opener origin transported through the popup flow.
        $target_origin = $requested_opener_origin !== '' ? $requested_opener_origin : $return_origin;
        if ($target_origin === $current_site_origin && $requested_opener_origin === '' && $return_origin !== '') {
            $target_origin = $return_origin;
        }
        $origin_json = wp_json_encode($target_origin);
        $requested_origin_json = wp_json_encode($requested_opener_origin);
        $return_origin_json = wp_json_encode($return_origin);

        echo '<!doctype html><html lang="it"><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . $title . '</title>';
        echo '<style>body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:#f4f6f8;color:#1f2933}main{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}.sos-pg-completion{max-width:560px;width:100%;background:#fff;border:1px solid #d8dee4;border-radius:14px;padding:32px;box-shadow:0 10px 30px rgba(15,23,42,.08)}h1{margin:0 0 12px;font-size:28px;line-height:1.2}.sos-pg-state{display:inline-block;margin-bottom:16px;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:600;background:#eef2f7;color:#334155;text-transform:uppercase;letter-spacing:.04em}p{margin:0 0 20px;font-size:16px;line-height:1.6}.sos-pg-actions{display:flex;gap:12px;flex-wrap:wrap}.sos-pg-button{display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:10px;background:#0f766e;color:#fff;text-decoration:none;font-weight:600}.sos-pg-note{margin-top:16px;color:#52606d;font-size:14px}.payment_pending .sos-pg-state{background:#fff7ed;color:#9a3412}.payment_recorded .sos-pg-state{background:#ecfdf3;color:#166534}.booking_completed .sos-pg-state{background:#eff6ff;color:#1d4ed8}.unavailable .sos-pg-state{background:#fef2f2;color:#b91c1c}</style>';
        echo '</head><body><main><section class="sos-pg-completion ' . $state_class . '"><div class="sos-pg-state">Flusso partner</div><h1>' . esc_html($title) . '</h1><p>' . esc_html($message) . '</p><div class="sos-pg-actions"><a class="sos-pg-button" href="' . $return_url . '">' . $button_label . '</a></div><p id="sos-pg-completion-note" class="sos-pg-note">Se il ritorno automatico non parte, usa il pulsante per tornare al sito del partner.</p></section></main>';
        echo '<script>(function(){var payload=' . $payload_json . ';var origin=' . $origin_json . ';var requestedOpenerOrigin=' . $requested_origin_json . ';var returnOrigin=' . $return_origin_json . ';var note=document.getElementById("sos-pg-completion-note");var returnUrl=String(payload.returnUrl||"");var redirected=false;function logMessage(message,details){if(window.console&&typeof window.console.log==="function"){if(typeof details!=="undefined"){window.console.log("[SOS SSO] "+message,details);return;}window.console.log("[SOS SSO] "+message);}}function redirectToPartner(trigger){if(redirected||!returnUrl){return;}redirected=true;logMessage("completion auto redirect",{trigger:trigger,returnUrl:returnUrl});window.location.assign(returnUrl);}logMessage("popup targetOrigin used",{targetOrigin:origin,requestedOpenerOrigin:requestedOpenerOrigin,returnOrigin:returnOrigin,returnUrl:returnUrl});if(window.opener&&origin){try{window.opener.postMessage(payload,origin);logMessage("completion postMessage sent",{targetOrigin:origin,returnUrl:returnUrl});}catch(error){logMessage("completion postMessage failed",String(error&&error.message?error.message:error));}}try{window.close();}catch(error){}window.setTimeout(function(){try{window.close();}catch(error){}},400);window.setTimeout(function(){if(note){note.textContent="Stiamo tornando al sito del partner...";}redirectToPartner("completion_fallback");},1200);}());</script>';
        echo '</body></html>';
        exit;
    }

    public function handle_partner_completion() {
        $request_path = $this->normalize_local_path($this->current_request_path());
        if ($request_path !== $this->normalize_local_path($this->current_completion_path())) {
            return;
        }

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            $this->render_partner_completion_page($this->get_partner_completion_view_model('unavailable', home_url('/'), [
                'booking_id' => 0,
                'partner_id' => '',
                'phase' => '',
                'source' => '',
                'opener_origin' => '',
            ]));
        }

        $booking_id = absint($_GET['booking_id'] ?? 0);
        $requested_booking_id = $booking_id;
        $completion_token = sanitize_text_field((string) ($_GET['completion_token'] ?? ''));
        $partner_id_query = sanitize_text_field((string) ($_GET['partner_id'] ?? ''));
        $phase = sanitize_key((string) ($_GET['phase'] ?? ''));
        $external_reference = sanitize_text_field((string) ($_GET['external_reference'] ?? ''));
        $opener_origin = $this->sanitize_opener_origin((string) ($_GET['opener_origin'] ?? ''));
        $source = sanitize_key((string) ($_GET['source'] ?? ''));

        if (!$this->is_valid_partner_id($partner_id_query)) {
            $partner_id_query = '';
        }

        $state_key = 'unavailable';
        $record_partner_id = $partner_id_query;
        $record = null;
        $final_completion_booking_id = $booking_id;
        $is_caf_completion = ($this->normalize_partner_id_key($partner_id_query) === 'caf');

        if ($is_caf_completion) {
            error_log('[SOS SSO] COMPLETION_CAF_REQUEST ' . wp_json_encode([
                'requested_booking_id' => $requested_booking_id,
                'external_reference' => $external_reference,
            ]));
        }

        if ($is_caf_completion && $external_reference !== '') {
            $record = $this->get_partner_booking_record_by_external_reference($partner_id_query, $external_reference);
            if ($record) {
                error_log('[SOS SSO] COMPLETION_CAF_RECORD_FOUND ' . wp_json_encode([
                    'requested_booking_id' => $requested_booking_id,
                    'partner_record_id' => isset($record->id) ? absint($record->id) : 0,
                    'lp_booking_id' => isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0,
                    'external_reference' => $external_reference,
                ]));
                if ($booking_id <= 0 && isset($record->lp_booking_id) && absint($record->lp_booking_id) > 0) {
                    $booking_id = absint($record->lp_booking_id);
                    $final_completion_booking_id = $booking_id;
                    error_log('[SOS SSO] COMPLETION_CAF_RESOLVED ' . wp_json_encode([
                        'requested_booking_id' => $requested_booking_id,
                        'partner_record_id' => isset($record->id) ? absint($record->id) : 0,
                        'lp_booking_id' => isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0,
                        'external_reference' => $external_reference,
                        'final_booking_id_used' => $booking_id,
                    ]));
                }
            }
        }

        if ($is_caf_completion && !$record && $booking_id > 0) {
            $record = $this->get_partner_booking_record($booking_id);
            if ($record) {
                $record_partner_id = sanitize_text_field((string) ($record->partner_id ?? $record_partner_id));
                error_log('[SOS SSO] COMPLETION_CAF_RECORD_FOUND ' . wp_json_encode([
                    'requested_booking_id' => $requested_booking_id,
                    'partner_record_id' => isset($record->id) ? absint($record->id) : 0,
                    'lp_booking_id' => isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0,
                    'external_reference' => $external_reference,
                ]));
            }
        }

        if ($booking_id > 0 && $completion_token !== '' && $this->verify_completion_token($completion_token, $booking_id, $partner_id_query)) {
            if (!$record) {
                $record = $this->get_partner_booking_record($booking_id);
            }

            if ($record) {
                $record_partner_id = sanitize_text_field((string) ($record->partner_id ?? ''));
                $partner_query_key = $this->normalize_partner_id_key($partner_id_query);
                $record_partner_key = $this->normalize_partner_id_key($record_partner_id);
                $session_partner_key = $this->normalize_partner_id_key($this->get_current_partner_id());
                $record_external_reference = sanitize_text_field((string) ($record->payment_external_ref ?? ''));
                $is_valid = true;

                if (!$this->verify_completion_token($completion_token, $booking_id, $record_partner_id)) {
                    $is_valid = false;
                }

                if ($partner_query_key !== '' && $record_partner_key !== '' && $partner_query_key !== $record_partner_key) {
                    $is_valid = false;
                }

                if ($session_partner_key !== '' && $record_partner_key !== '' && $session_partner_key !== $record_partner_key) {
                    $is_valid = false;
                }

                if ($external_reference !== '' && $record_external_reference !== '' && !hash_equals($record_external_reference, $external_reference)) {
                    $is_valid = false;
                }

                if ($is_valid) {
                    if ($this->should_use_partner_payment_completion_return($record_partner_id, $record)) {
                        if (isset($record->lp_booking_id) && absint($record->lp_booking_id) > 0) {
                            $final_completion_booking_id = absint($record->lp_booking_id);
                            if ($this->normalize_partner_id_key($record_partner_id) === 'caf') {
                                error_log('[SOS SSO] COMPLETION_CAF_RESOLVED ' . wp_json_encode([
                                    'requested_booking_id' => $requested_booking_id,
                                    'partner_record_id' => isset($record->id) ? absint($record->id) : 0,
                                    'lp_booking_id' => isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0,
                                    'external_reference' => $external_reference,
                                    'final_booking_id_used' => $final_completion_booking_id,
                                ]));
                            }
                        } else {
                            if ($this->normalize_partner_id_key($record_partner_id) === 'caf') {
                                error_log('[SOS SSO] COMPLETION_CAF_MISSING_BOOKING ' . wp_json_encode([
                                    'requested_booking_id' => $requested_booking_id,
                                    'partner_record_id' => isset($record->id) ? absint($record->id) : 0,
                                    'lp_booking_id' => isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0,
                                    'external_reference' => $external_reference,
                                    'final_booking_id_used' => $final_completion_booking_id,
                                ]));
                            }
                            error_log('[SOS SSO] FAKE_PAYMENT_COMPLETION_URL_WARN missing_lp_booking_id ' . wp_json_encode([
                                'incoming_requested_booking_id' => $booking_id,
                                'incoming_external_reference' => $external_reference,
                                'partner_record_id' => isset($record->id) ? absint($record->id) : 0,
                                'partner_record_lp_booking_id' => isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0,
                                'final_booking_id_used' => $final_completion_booking_id,
                                'final_external_reference_used' => $record_external_reference,
                            ]));
                        }
                    }

                    $partner_charge = isset($record->partner_charge) ? (float) $record->partner_charge : 0.0;
                    $payment_status = sanitize_text_field((string) ($record->payment_status ?? ''));
                    $confirmed_at = trim((string) ($record->confirmed_at ?? ''));

                    if ($payment_status === 'paid' || $confirmed_at !== '') {
                        $state_key = 'payment_recorded';
                    } elseif ($partner_charge > 0) {
                        $state_key = 'payment_pending';
                    } else {
                        $state_key = 'booking_completed';
                    }
                }
            }
        }

        if ($is_caf_completion) {
            $verified_lp_booking_id = ($record && isset($record->lp_booking_id)) ? absint($record->lp_booking_id) : 0;
            if (!$record || $verified_lp_booking_id <= 0) {
                error_log('[SOS SSO] COMPLETION_CAF_ABORT_NO_VERIFIED_RECORD ' . wp_json_encode([
                    'requested_booking_id' => $requested_booking_id,
                    'external_reference' => $external_reference,
                    'partner_record_id' => $record && isset($record->id) ? absint($record->id) : 0,
                    'lp_booking_id' => $verified_lp_booking_id,
                    'reason' => !$record ? 'missing_record' : 'missing_lp_booking_id',
                ]));
                $abort_partner_id = $record_partner_id !== '' ? $record_partner_id : $partner_id_query;
                $abort_url = $this->resolve_partner_completion_final_url($abort_partner_id);
                $this->render_partner_completion_page($this->get_partner_completion_view_model('unavailable', $abort_url, [
                    'booking_id' => 0,
                    'partner_id' => $abort_partner_id,
                    'phase' => $phase,
                    'source' => $source,
                    'opener_origin' => $opener_origin,
                ]));
                return;
            }
            $record_partner_id = sanitize_text_field((string) ($record->partner_id ?? $record_partner_id));
            $final_completion_booking_id = $verified_lp_booking_id;
        }

        $return_url = $this->resolve_partner_completion_final_url($record_partner_id);
        $return_url = $this->build_partner_payment_completion_return_url($return_url, $record_partner_id, $final_completion_booking_id, $record);
        if ($this->normalize_partner_id_key($record_partner_id) === 'caf') {
            error_log('[SOS SSO] COMPLETION_CAF_FINAL_URL ' . wp_json_encode([
                'requested_booking_id' => $requested_booking_id,
                'partner_record_id' => isset($record->id) ? absint($record->id) : 0,
                'lp_booking_id' => isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0,
                'external_reference' => $external_reference,
                'final_booking_id_used' => $final_completion_booking_id,
                'final_url' => $return_url,
            ]));
            // TEMP DEBUG CAF
            error_log('[SOS SSO] COMPLETION_CAF_FINAL_URL_DEBUG ' . wp_json_encode([
                'requested_booking_id' => $requested_booking_id,
                'final_booking_id_used' => $final_completion_booking_id,
                'external_reference' => $external_reference,
                'partner_record_id' => isset($record->id) ? absint($record->id) : 0,
                'lp_booking_id' => isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0,
                'final_url' => $return_url,
            ]));
        }
        error_log('[SOS SSO] completion return target final booking_id=' . $booking_id . ' partner_id=' . $record_partner_id . ' target=' . $return_url);

        $this->render_partner_completion_page($this->get_partner_completion_view_model($state_key, $return_url, [
            'booking_id' => $final_completion_booking_id,
            'partner_id' => $record_partner_id,
            'phase' => $phase,
            'source' => $source,
            'opener_origin' => $opener_origin,
        ]));
    }

    public function handle_partner_completion_url_request() {
        if (!isset($_GET['sos_pg_completion_url'])) {
            return;
        }

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            wp_send_json(['success' => false, 'message' => 'Metodo non consentito'], 405);
        }

        $booking_id = absint($_GET['booking_id'] ?? 0);
        $requested_booking_id = $booking_id;

        $partner_id_query = sanitize_text_field((string) ($_GET['partner_id'] ?? ''));
        $external_reference = sanitize_text_field((string) ($_GET['external_reference'] ?? ''));
        $phase = sanitize_key((string) ($_GET['phase'] ?? ''));
        $source = sanitize_key((string) ($_GET['source'] ?? 'frontend'));
        $opener_origin = $this->sanitize_opener_origin((string) ($_GET['opener_origin'] ?? ''));
        $is_caf_completion = ($this->normalize_partner_id_key($partner_id_query) === 'caf');

        if ($partner_id_query !== '' && !$this->is_valid_partner_id($partner_id_query)) {
            wp_send_json(['success' => false, 'message' => 'partner_id non valido'], 400);
        }

        $record = null;
        if ($is_caf_completion) {
            error_log('[SOS SSO] COMPLETION_CAF_REQUEST ' . wp_json_encode([
                'requested_booking_id' => $requested_booking_id,
                'external_reference' => $external_reference,
            ]));
        }
        if ($is_caf_completion && $external_reference !== '') {
            $record = $this->get_partner_booking_record_by_external_reference($partner_id_query, $external_reference);
            if ($record) {
                error_log('[SOS SSO] COMPLETION_CAF_RECORD_FOUND ' . wp_json_encode([
                    'requested_booking_id' => $requested_booking_id,
                    'partner_record_id' => isset($record->id) ? absint($record->id) : 0,
                    'lp_booking_id' => isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0,
                    'external_reference' => $external_reference,
                ]));
                if ($booking_id <= 0 && isset($record->lp_booking_id) && absint($record->lp_booking_id) > 0) {
                    $booking_id = absint($record->lp_booking_id);
                }
            }
        }
        if ($is_caf_completion && !$record && $booking_id > 0) {
            $record = $this->get_partner_booking_record($booking_id);
            if ($record) {
                error_log('[SOS SSO] COMPLETION_CAF_RECORD_FOUND ' . wp_json_encode([
                    'requested_booking_id' => $requested_booking_id,
                    'partner_record_id' => isset($record->id) ? absint($record->id) : 0,
                    'lp_booking_id' => isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0,
                    'external_reference' => $external_reference,
                ]));
            }
        }
        if ($is_caf_completion) {
            $verified_lp_booking_id = ($record && isset($record->lp_booking_id)) ? absint($record->lp_booking_id) : 0;
            if (!$record || $verified_lp_booking_id <= 0) {
                error_log('[SOS SSO] COMPLETION_CAF_ABORT_NO_VERIFIED_RECORD ' . wp_json_encode([
                    'requested_booking_id' => $requested_booking_id,
                    'external_reference' => $external_reference,
                    'partner_record_id' => $record && isset($record->id) ? absint($record->id) : 0,
                    'lp_booking_id' => $verified_lp_booking_id,
                    'reason' => !$record ? 'missing_record' : 'missing_lp_booking_id',
                ]));
                wp_send_json(['success' => false, 'message' => 'record partner CAF non verificato'], 409);
            }
            $booking_id = $verified_lp_booking_id;
        }
        if ($booking_id <= 0) {
            if ($this->normalize_partner_id_key($partner_id_query) === 'caf') {
                error_log('[SOS SSO] COMPLETION_CAF_MISSING_BOOKING ' . wp_json_encode([
                    'requested_booking_id' => $requested_booking_id,
                    'partner_record_id' => isset($record->id) ? absint($record->id) : 0,
                    'lp_booking_id' => isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0,
                    'external_reference' => $external_reference,
                    'final_booking_id_used' => $booking_id,
                ]));
            }
            wp_send_json(['success' => false, 'message' => 'booking_id mancante'], 400);
        }
        if (!$record) {
            $record = $this->get_partner_booking_record($booking_id);
        }
        if (!$record) {
            if ($this->normalize_partner_id_key($partner_id_query) === 'caf') {
                error_log('[SOS SSO] COMPLETION_CAF_MISSING_BOOKING ' . wp_json_encode([
                    'requested_booking_id' => $requested_booking_id,
                    'partner_record_id' => 0,
                    'lp_booking_id' => 0,
                    'external_reference' => $external_reference,
                    'final_booking_id_used' => $booking_id,
                ]));
            }
            wp_send_json(['success' => false, 'message' => 'record non trovato'], 404);
        }

        $record_partner_id = sanitize_text_field((string) ($record->partner_id ?? ''));
        $record_partner_key = $this->normalize_partner_id_key($record_partner_id);
        $partner_query_key = $this->normalize_partner_id_key($partner_id_query);
        $session_partner_key = $this->normalize_partner_id_key($this->get_current_partner_id());
        $record_external_reference = sanitize_text_field((string) ($record->payment_external_ref ?? ''));

        if ($record_partner_key === '') {
            wp_send_json(['success' => false, 'message' => 'partner record non valido'], 409);
        }

        if ($partner_query_key !== '' && $partner_query_key !== $record_partner_key) {
            wp_send_json(['success' => false, 'message' => 'partner_id mismatch'], 403);
        }

        if ($session_partner_key === '' || $session_partner_key !== $record_partner_key) {
            wp_send_json(['success' => false, 'message' => 'sessione partner non coerente'], 403);
        }

        if ($external_reference !== '' && $record_external_reference !== '' && !hash_equals($record_external_reference, $external_reference)) {
            wp_send_json(['success' => false, 'message' => 'external_reference mismatch'], 409);
        }

        $resolved_booking_id = $booking_id;
        if ($this->normalize_partner_id_key($record_partner_id) === 'caf') {
            $resolved_booking_id = isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0;
            if ($resolved_booking_id <= 0) {
                error_log('[SOS SSO] COMPLETION_CAF_ABORT_NO_VERIFIED_RECORD ' . wp_json_encode([
                    'requested_booking_id' => $requested_booking_id,
                    'external_reference' => $external_reference,
                    'partner_record_id' => isset($record->id) ? absint($record->id) : 0,
                    'lp_booking_id' => isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0,
                    'reason' => 'missing_lp_booking_id_after_record_validation',
                ]));
                wp_send_json(['success' => false, 'message' => 'record partner CAF non verificato'], 409);
            }
        } elseif ($this->should_use_partner_payment_completion_return($record_partner_id, $record)
            && isset($record->lp_booking_id)
            && absint($record->lp_booking_id) > 0
        ) {
            $resolved_booking_id = absint($record->lp_booking_id);
        }
        if ($this->normalize_partner_id_key($record_partner_id) === 'caf') {
            error_log('[SOS SSO] COMPLETION_CAF_RESOLVED ' . wp_json_encode([
                'requested_booking_id' => $requested_booking_id,
                'partner_record_id' => isset($record->id) ? absint($record->id) : 0,
                'lp_booking_id' => isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0,
                'external_reference' => $external_reference,
                'final_booking_id_used' => $resolved_booking_id,
            ]));
        }

        $completion_url = $this->get_partner_completion_url($resolved_booking_id, [
            'partner_id' => $record_partner_id,
            'phase' => $phase,
            'external_reference' => $external_reference,
            'opener_origin' => $opener_origin,
            'source' => $source,
        ]);
        if ($this->normalize_partner_id_key($record_partner_id) === 'caf') {
            error_log('[SOS SSO] COMPLETION_CAF_FINAL_URL ' . wp_json_encode([
                'requested_booking_id' => $requested_booking_id,
                'partner_record_id' => isset($record->id) ? absint($record->id) : 0,
                'lp_booking_id' => isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0,
                'external_reference' => $external_reference,
                'final_booking_id_used' => $resolved_booking_id,
                'final_url' => $completion_url,
            ]));
            // TEMP DEBUG CAF
            error_log('[SOS SSO] COMPLETION_CAF_FINAL_URL_DEBUG ' . wp_json_encode([
                'requested_booking_id' => $requested_booking_id,
                'final_booking_id_used' => $resolved_booking_id,
                'external_reference' => $external_reference,
                'partner_record_id' => isset($record->id) ? absint($record->id) : 0,
                'lp_booking_id' => isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0,
                'final_url' => $completion_url,
            ]));
        }

        if ($this->should_use_partner_payment_completion_return($record_partner_id, $record)) {
            error_log('[SOS SSO] FAKE_PAYMENT_COMPLETION_REQUEST ' . wp_json_encode([
                'incoming_requested_booking_id' => $booking_id,
                'incoming_external_reference' => $external_reference,
                'partner_record_id' => isset($record->id) ? absint($record->id) : 0,
                'partner_record_lp_booking_id' => isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0,
                'final_booking_id_used' => $resolved_booking_id,
                'final_external_reference_used' => $external_reference,
                'final_fake_gateway_url' => $completion_url,
            ]));
            if (!isset($record->lp_booking_id) || absint($record->lp_booking_id) <= 0) {
                error_log('[SOS SSO] FAKE_PAYMENT_COMPLETION_REQUEST_WARN missing_lp_booking_id ' . wp_json_encode([
                    'incoming_requested_booking_id' => $booking_id,
                    'incoming_external_reference' => $external_reference,
                    'partner_record_id' => isset($record->id) ? absint($record->id) : 0,
                    'partner_record_lp_booking_id' => isset($record->lp_booking_id) ? absint($record->lp_booking_id) : 0,
                    'final_booking_id_used' => $resolved_booking_id,
                    'final_external_reference_used' => $external_reference,
                    'final_fake_gateway_url' => $completion_url,
                ]));
            }
        }

        if ($completion_url === '') {
            wp_send_json(['success' => false, 'message' => 'completion url non disponibile'], 500);
        }

        wp_send_json_success([
            'booking_id' => $resolved_booking_id,
            'partner_id' => $record_partner_id,
            'completion_url' => $completion_url,
        ]);
    }

    private function get_partner_pages() {
        return get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'draft', 'private'],
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_key' => '_sos_pg_partner_enabled',
            'meta_value' => 1,
        ]);
    }

    private function notice() {
        $msg = sanitize_text_field(wp_unslash($_GET['msg'] ?? ''));
        $map = [
            'saved'           => 'Impostazioni salvate.',
            'blocked'         => 'IP bloccato correttamente.',
            'unlocked'        => 'IP sbloccato correttamente.',
            'ip_missing'      => 'IP mancante.',
            'cleared'         => 'Log svuotati.',
            'discount_saved'  => 'Sconti partner salvati.',
            'routes_saved'    => 'Routing partner salvato.',
            'webhooks_saved'  => 'Webhook partner salvati.',
        ];

        if (isset($map[$msg])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($map[$msg]) . '</p></div>';
        }
    }

    public function admin_menu() {

        if ($this->is_partner_mode()) {
            add_menu_page('SOS Partner Gateway', 'SOS Partner Gateway', 'manage_options', 'sos-partner-gateway', [$this, 'render_settings_page'], 'dashicons-shield', 58);
            add_submenu_page('sos-partner-gateway', 'Impostazioni', 'Impostazioni', 'manage_options', 'sos-partner-gateway', [$this, 'render_settings_page']);
            add_submenu_page('sos-partner-gateway', 'Tester', 'Tester', 'manage_options', 'sos-partner-gateway-tester', [$this, 'render_tester_page']);
        } else {
            add_menu_page('SOS Partner Gateway', 'SOS Partner Gateway', 'manage_options', 'sos-partner-gateway', [$this, 'render_logs_page'], 'dashicons-shield', 58);
            add_submenu_page('sos-partner-gateway', 'Log', 'Log', 'manage_options', 'sos-partner-gateway', [$this, 'render_logs_page']);
            add_submenu_page('sos-partner-gateway', 'Impostazioni', 'Impostazioni', 'manage_options', 'sos-partner-gateway-settings', [$this, 'render_settings_page']);
            add_submenu_page('sos-partner-gateway', 'Pagine Partner', 'Pagine Partner', 'manage_options', 'sos-partner-gateway-pages', [$this, 'render_pages_page']);
            add_submenu_page('sos-partner-gateway', 'Partner multipli (beta)', 'Partner multipli (beta)', 'manage_options', 'sos-partner-gateway-partners', [$this, 'render_partner_configs_page']);
            add_submenu_page('sos-partner-gateway', 'Test pagamento', 'Test pagamento', 'manage_options', 'sos-partner-gateway-payment-test', [$this, 'render_test_payment_page']);
            add_submenu_page('sos-partner-gateway', 'Tester embedded booking', 'Tester embedded booking', 'manage_options', 'sos-partner-gateway-embedded-tester', [$this, 'render_embedded_booking_tester_page']);
        }

    }

    private function event_badge($event_type) {
        $code = strtoupper((string) $event_type);
        if (preg_match('/OK|SENT|SUMMARY/', $code)) {
            $color = '#1b5e20'; $bg = '#e8f5e9';
        } elseif (preg_match('/FAIL|INVALID|MISMATCH/', $code)) {
            $color = '#b71c1c'; $bg = '#ffebee';
        } elseif (preg_match('/WARN|RECEIVED|SKIP/', $code)) {
            $color = '#e65100'; $bg = '#fff3e0';
        } else {
            $color = '#37474f'; $bg = '#f5f5f5';
        }
        return '<span style="display:inline-block;padding:2px 7px;border-radius:3px;font-size:11px;font-weight:600;letter-spacing:.3px;color:'
            . $color . ';background:' . $bg . ';border:1px solid ' . $color . '33;">'
            . esc_html($event_type) . '</span>';
    }

    private function render_pagination($view, $page_num, $total_pages) {
        $base = ['page' => 'sos-partner-gateway', 'view' => $view];
        echo '<div style="margin:10px 0;display:flex;align-items:center;gap:12px;">';
        if ($page_num > 1) {
            $prev = add_query_arg(array_merge($base, ['page_num' => $page_num - 1]), admin_url('admin.php'));
            echo '<a class="button" href="' . esc_url($prev) . '">&laquo; Prev</a>';
        }
        echo '<span>Pagina ' . (int) $page_num . ' / ' . (int) $total_pages . '</span>';
        if ($page_num < $total_pages) {
            $next = add_query_arg(array_merge($base, ['page_num' => $page_num + 1]), admin_url('admin.php'));
            echo '<a class="button" href="' . esc_url($next) . '">Next &raquo;</a>';
        }
        echo '</div>';
    }

    public function render_logs_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        $settings    = $this->get_settings();
        $view        = sanitize_key((string) ($_GET['view'] ?? 'raw'));
        if (!in_array($view, ['raw', 'summary'], true)) {
            $view = 'raw';
        }
        $per_page    = 50;
        $page_num    = max(1, (int) ($_GET['page_num'] ?? 1));
        $raw_url     = add_query_arg(['page' => 'sos-partner-gateway', 'view' => 'raw'],     admin_url('admin.php'));
        $summary_url = add_query_arg(['page' => 'sos-partner-gateway', 'view' => 'summary'], admin_url('admin.php'));
        if ($view === 'raw') {
            $total_rows  = $this->get_logs_count();
            $total_pages = max(1, (int) ceil($total_rows / $per_page));
            $page_num    = min($page_num, $total_pages);
            $logs        = $this->get_logs($per_page, ($page_num - 1) * $per_page);
        } else {
            // Summary: load broader set for grouping, paginate groups in PHP
            $logs        = $this->get_logs(2000, 0);
        }

        echo '<div class="wrap"><h1>SOS Partner Gateway — Log</h1>';
        $this->notice();
        echo '<p><strong>Endpoint:</strong> <code>' . esc_html(home_url($this->current_endpoint_path() . '/')) . '</code></p>';
        echo '<p><strong>Debug logs:</strong> ' . (!empty($settings['debug_logging_enabled']) ? 'attivi' : 'disattivati') . '</p>';
        echo '<p><a class="button ' . ($view === 'raw' ? 'button-primary' : '') . '" href="' . esc_url($raw_url) . '">Raw</a> ';
        echo '<a class="button ' . ($view === 'summary' ? 'button-primary' : '') . '" href="' . esc_url($summary_url) . '">Riepilogo</a></p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:16px 0;">';
        wp_nonce_field('sos_pg_clear_logs');
        echo '<input type="hidden" name="action" value="sos_pg_clear_logs"><button class="button">Svuota log</button></form>';

        if ($view === 'summary') {
            $groups = [];

            foreach ((array) $logs as $log) {
                $context_raw = (string) $log->context;
                $context_data = json_decode($context_raw, true);
                $email_norm = strtolower(trim((string) $log->email));
                $group_key = '';

                if (is_array($context_data) && !empty($context_data['group_key']) && is_scalar($context_data['group_key'])) {
                    $candidate = (string) $context_data['group_key'];
                    if (strpos($candidate, 'booking_') === 0) {
                        $group_key = $candidate;
                    }
                }
                if ($group_key === '' && $email_norm !== '') {
                    $group_key = 'email:' . $email_norm;
                }
                if ($group_key === '') {
                    $group_key = 'fallback:' . (string) $log->event_type . '|' . (string) $log->partner_id;
                }

                if (!isset($groups[$group_key])) {
                    $groups[$group_key] = [
                        'last_date' => (string) $log->created_at,
                        'partner_id' => (string) $log->partner_id,
                        'email' => (string) $log->email,
                        'group' => $group_key,
                        'count' => 0,
                        'first_event' => (string) $log->event_type,
                        'last_event' => (string) $log->event_type,
                        'final_status' => '',
                        'last_reason' => '',
                        'events' => [],
                    ];
                }

                $g = &$groups[$group_key];
                $g['count']++;
                $g['events'][] = [
                    'created_at' => (string) $log->created_at,
                    'event_type' => (string) $log->event_type,
                    'reason' => (string) $log->reason,
                ];

                if ((string) $log->created_at >= $g['last_date']) {
                    $g['last_date'] = (string) $log->created_at;
                    $g['last_event'] = (string) $log->event_type;
                    if ((string) $log->partner_id !== '') {
                        $g['partner_id'] = (string) $log->partner_id;
                    }
                    if ((string) $log->email !== '') {
                        $g['email'] = (string) $log->email;
                    }
                    if (is_array($context_data)) {
                        if (isset($context_data['final_status']) && is_scalar($context_data['final_status'])) {
                            $g['final_status'] = (string) $context_data['final_status'];
                        } elseif (isset($context_data['status']) && is_scalar($context_data['status'])) {
                            $g['final_status'] = (string) $context_data['status'];
                        }
                    }
                    if ((string) $log->reason !== '') {
                        $g['last_reason'] = (string) $log->reason;
                    }
                }

                if ((string) $log->created_at <= ($g['first_date'] ?? (string) $log->created_at)) {
                    $g['first_date'] = (string) $log->created_at;
                    $g['first_event'] = (string) $log->event_type;
                }

                if ($g['final_status'] === '' && is_array($context_data)) {
                    if (isset($context_data['final_status']) && is_scalar($context_data['final_status'])) {
                        $g['final_status'] = (string) $context_data['final_status'];
                    } elseif (isset($context_data['status']) && is_scalar($context_data['status'])) {
                        $g['final_status'] = (string) $context_data['status'];
                    }
                }
                if ($g['last_reason'] === '' && (string) $log->reason !== '') {
                    $g['last_reason'] = (string) $log->reason;
                }
                unset($g);
            }

            usort($groups, function($a, $b) {
                return strcmp((string) $b['last_date'], (string) $a['last_date']);
            });

            $total_groups = count($groups);
            $total_pages  = max(1, (int) ceil($total_groups / $per_page));
            $page_num     = min($page_num, $total_pages);
            $groups       = array_slice($groups, ($page_num - 1) * $per_page, $per_page);

            $this->render_pagination($view, $page_num, $total_pages);
            echo '<table class="widefat striped"><thead><tr><th>Ultima data</th><th>Partner</th><th>Email</th><th>Group / Booking</th><th>Count</th><th>Primo evento</th><th>Ultimo evento</th><th>Stato finale</th><th>Ultimo motivo</th><th>Dettagli</th></tr></thead><tbody>';
            if (empty($groups)) {
                echo '<tr><td colspan="10">Nessun log.</td></tr>';
            }

            foreach ($groups as $g) {
                echo '<tr>';
                echo '<td>' . esc_html($g['last_date']) . '</td>';
                echo '<td>' . esc_html($g['partner_id']) . '</td>';
                echo '<td>' . esc_html($g['email']) . '</td>';
                echo '<td>' . esc_html($g['group']) . '</td>';
                echo '<td>' . esc_html((string) $g['count']) . '</td>';
                echo '<td>' . $this->event_badge($g['first_event']) . '</td>';
                echo '<td>' . $this->event_badge($g['last_event']) . '</td>';
                echo '<td>' . esc_html($g['final_status']) . '</td>';
                echo '<td>' . esc_html($g['last_reason']) . '</td>';
                echo '<td>';
                echo '<details><summary>Eventi gruppo</summary><ul style="margin:8px 0 0 16px;">';
                foreach ((array) $g['events'] as $ev) {
                    $line = (string) $ev['created_at'] . ' | ' . (string) $ev['event_type'];
                    if ((string) $ev['reason'] !== '') {
                        $line .= ' | ' . (string) $ev['reason'];
                    }
                    echo '<li>' . esc_html($line) . '</li>';
                }
                echo '</ul></details>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            $this->render_pagination($view, $page_num, $total_pages);
            echo '</div>';
            return;
        }

        $this->render_pagination($view, $page_num, $total_pages);
        echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Evento</th><th>Partner</th><th>Email</th><th>IP</th><th>Group</th><th>Motivo</th><th>Context</th><th>Azione</th></tr></thead><tbody>';

        if (!$logs) {
            echo '<tr><td colspan="9">Nessun log.</td></tr>';
        }

        foreach ((array) $logs as $log) {
            $context_raw = (string) $log->context;
            $context_data = json_decode($context_raw, true);
            $compact_keys = ['booking_id', 'group_key', 'status', 'transaction_id', 'external_reference', 'redirect'];
            $compact_parts = [];

            if (is_array($context_data)) {
                foreach ($compact_keys as $ck) {
                    if (!array_key_exists($ck, $context_data)) {
                        continue;
                    }
                    $cv = $context_data[$ck];
                    if (is_scalar($cv) || is_null($cv)) {
                        $compact_parts[] = $ck . '=' . (string) $cv;
                    } else {
                        $compact_parts[] = $ck . '=' . (string) wp_json_encode($cv);
                    }
                }
            }

            $context_compact = !empty($compact_parts) ? implode(' | ', $compact_parts) : '—';
            $context_group = (is_array($context_data) && isset($context_data['group_key']) && (is_scalar($context_data['group_key']) || is_null($context_data['group_key'])))
                ? (string) $context_data['group_key']
                : '—';
            if (is_array($context_data)) {
                $context_full = wp_json_encode($context_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($context_full === false) {
                    $context_full = $context_raw;
                }
            } else {
                $context_full = $context_raw;
            }

            echo '<tr>';
            echo '<td>' . esc_html($log->created_at) . '</td>';
            echo '<td>' . $this->event_badge($log->event_type) . '</td>';
            echo '<td>' . esc_html($log->partner_id) . '</td>';
            echo '<td>' . esc_html($log->email) . '</td>';
            echo '<td>' . esc_html($log->ip) . '</td>';
            echo '<td>' . esc_html($context_group) . '</td>';
            echo '<td>' . esc_html($log->reason) . '</td>';
            echo '<td style="max-width:360px;word-break:break-word;">';
            echo '<div>' . esc_html($context_compact) . '</div>';
            if ($context_full !== '') {
                echo '<details style="margin-top:4px;"><summary>JSON completo</summary>';
                echo '<pre style="white-space:pre-wrap;margin:6px 0 0;">' . esc_html($context_full) . '</pre>';
                echo '</details>';
            }
            echo '</td>';
            echo '<td>';
            if (!empty($log->ip)) {
                echo '<div style="display:flex;flex-direction:column;gap:6px;">';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('sos_pg_block_ip');
                echo '<input type="hidden" name="action" value="sos_pg_block_ip">';
                echo '<input type="hidden" name="ip" value="' . esc_attr($log->ip) . '">';
                echo '<button class="button button-small">Blocca IP</button>';
                echo '</form>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('sos_pg_unlock_ip');
                echo '<input type="hidden" name="action" value="sos_pg_unlock_ip">';
                echo '<input type="hidden" name="ip" value="' . esc_attr($log->ip) . '">';
                echo '<button class="button button-small">Sblocca IP</button>';
                echo '</form>';
                echo '</div>';
            } else {
                echo '—';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        $this->render_pagination($view, $page_num, $total_pages);
        echo '</div>';
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        $settings = $this->get_settings();
        $is_partner = $this->is_partner_mode();

        $title = $is_partner
            ? 'SOS Partner Gateway &mdash; Impostazioni (Modalit&agrave; Partner)'
            : 'SOS Partner Gateway &mdash; Impostazioni';

        echo '<div class="wrap"><h1>' . $title . '</h1>';
        $this->notice();
        $this->render_environment_warning_panel('settings');

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('sos_pg_save_settings');
        echo '<input type="hidden" name="action" value="sos_pg_save_settings">';
        echo '<table class="form-table">';

        // --- Selettore ruolo: sempre visibile in cima ---
        echo '<tr><th>Ruolo sito</th><td>';
        echo '<select name="site_role">';
        echo '<option value="main" ' . selected($settings['site_role'], 'main', false) . '>Sito principale (gateway)</option>';
        echo '<option value="partner" ' . selected($settings['site_role'], 'partner', false) . '>Sito partner</option>';
        echo '</select>';
        if ($is_partner) {
            echo '<p class="description"><em>Modalit&agrave; attiva: <strong>Sito partner</strong>.</em> Cambia in &quot;Sito principale&quot; solo se questo sito deve gestire direttamente le prenotazioni.<br>';
            echo '<em>Dopo aver cambiato il ruolo, clicca &quot;Salva impostazioni&quot;.</em></p>';
        } else {
            echo '<p class="description"><strong>Sito principale</strong>: riceve i login firmati, gestisce le prenotazioni, invia webhook ai partner.<br>';
            echo '<strong>Sito partner</strong>: firma e invia le richieste di login al sito principale tramite shortcode o tester.<br>';
            echo '<em>Dopo aver cambiato il ruolo, clicca &quot;Salva impostazioni&quot; per applicare la modalit&agrave;.</em></p>';
        }
        echo '</td></tr>';

        if ($is_partner) {
            // --- Modalità partner: solo campi rilevanti ---
            echo '<tr><th colspan="2"><hr style="margin:4px 0;"><strong>Configurazione sito partner</strong>';
            echo '<p class="description" style="font-weight:normal;">Questi sono gli unici dati necessari sul sito partner. Il sito principale gestisce tutto il resto.</p></th></tr>';

            echo '<tr><th>Partner ID</th><td>';
            echo '<input type="text" class="regular-text" name="self_login_partner_id" value="' . esc_attr($settings['self_login_partner_id']) . '" placeholder="es. hf">';
            echo '<p class="description">L\'ID che identifica questo partner sul sito principale.</p>';
            echo '</td></tr>';

            echo '<tr><th>URL endpoint login (sito principale)</th><td>';
            echo '<input type="url" class="regular-text" name="self_login_endpoint_url" value="' . esc_attr($settings['self_login_endpoint_url']) . '" placeholder="https://videoconsulto.sospediatra.org/partner-login/">';
            echo '<p class="description">URL completo dell\'endpoint di login sul sito principale.</p>';
            echo '</td></tr>';

            echo '<tr><th>Chiave privata ECC (PEM)</th><td>';
            echo '<textarea class="large-text code" rows="10" name="self_login_private_key_pem" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>';
            echo '<p class="description">Per sicurezza il valore salvato non viene mostrato. Inserisci una nuova chiave solo se vuoi aggiornarla; lasciando il campo vuoto il valore attuale resta invariato.</p>';
            echo '</td></tr>';

            echo '<tr><th colspan="2"><hr style="margin:4px 0;"><strong>Webhook in entrata (dal sito principale)</strong>';
            echo '<p class="description" style="font-weight:normal;">Il sito principale invier&agrave; notifiche di nuove prenotazioni a questo URL. Inserisci il secret condiviso per verificare la firma.</p></th></tr>';

            $tester_webhook_url = home_url('/?sos_pg_webhook=1');
            echo '<tr><th>URL webhook (da configurare sul principale)</th><td>';
            echo '<code>' . esc_html($tester_webhook_url) . '</code>';
            echo '<p class="description">Copia questo URL nella configurazione webhook del sito principale per il tuo partner ID.</p>';
            echo '</td></tr>';

            echo '<tr><th>Secret webhook in entrata (HMAC)</th><td>';
            echo '<input type="text" class="regular-text" name="partner_webhook_secret" value="' . esc_attr($settings['partner_webhook_secret']) . '" placeholder="secret condiviso con il sito principale">';
            echo '<p class="description">Lo stesso secret configurato sul sito principale per questo partner.</p>';
            echo '</td></tr>';

            echo '<tr><th colspan="2"><hr style="margin:4px 0;"><strong>Callback pagamento (verso il sito principale)</strong>';
            echo '<p class="description" style="font-weight:normal;">Dopo che il cliente paga sul tuo portale, usa il Tester per inviare la conferma al sito principale.</p></th></tr>';

            echo '<tr><th>URL callback pagamento (sito principale)</th><td>';
            echo '<input type="url" class="regular-text" name="partner_callback_url" value="' . esc_attr($settings['partner_callback_url']) . '" placeholder="https://videoconsulto.sospediatra.org/partner-payment-callback/">';
            echo '<p class="description">URL dell\'endpoint di conferma pagamento sul sito principale.</p>';
            echo '</td></tr>';

            echo '<tr><th>Secret callback pagamento</th><td>';
            echo '<input type="text" class="regular-text" name="partner_callback_secret" value="' . esc_attr($settings['partner_callback_secret']) . '" placeholder="secret condiviso con il sito principale">';
            echo '<p class="description">Secret per firmare le richieste di conferma pagamento inviate al sito principale.</p>';
            echo '</td></tr>';
        } else {
            // --- Modalità sito principale: impostazioni complete ---
            echo '<tr><th>Slug endpoint login</th><td><input type="text" class="regular-text" name="endpoint_slug" value="' . esc_attr($settings['endpoint_slug']) . '"></td></tr>';

            echo '<tr><th>Pagina di cortesia</th><td><select name="courtesy_page_id"><option value="0">&mdash; Nessuna &mdash;</option>';
            $pages = get_pages(['sort_column' => 'post_title']);
            foreach ($pages as $p) {
                echo '<option value="' . esc_attr($p->ID) . '" ' . selected((int) $settings['courtesy_page_id'], (int) $p->ID, false) . '>' . esc_html($p->post_title) . ' (#' . $p->ID . ')</option>';
            }
            echo '</select></td></tr>';

            echo '<tr><th>Log debug/sviluppo</th><td><label><input type="checkbox" name="debug_logging_enabled" value="1" ' . checked(!empty($settings['debug_logging_enabled']), true, false) . '> Attiva</label><p class="description">Disattiva solo i log rumorosi di debug/sviluppo. I log critici di sicurezza e operativi restano attivi.</p></td></tr>';

            echo '<tr><th>Rate limit breve</th><td><input type="number" name="max_fail_short" value="' . esc_attr($settings['max_fail_short']) . '" min="1"> errori in <input type="number" name="window_short_minutes" value="' . esc_attr($settings['window_short_minutes']) . '" min="1"> minuti &rarr; ban <input type="number" name="ban_short_minutes" value="' . esc_attr($settings['ban_short_minutes']) . '" min="1"> minuti</td></tr>';

            echo '<tr><th>Rate limit lungo</th><td><input type="number" name="max_fail_long" value="' . esc_attr($settings['max_fail_long']) . '" min="1"> errori in <input type="number" name="window_long_minutes" value="' . esc_attr($settings['window_long_minutes']) . '" min="1"> minuti &rarr; ban <input type="number" name="ban_long_minutes" value="' . esc_attr($settings['ban_long_minutes']) . '" min="1"> minuti</td></tr>';

            echo '<tr><th>Chiave pubblica PEM</th><td><textarea class="large-text code" rows="12" name="public_key_pem">' . esc_textarea($settings['public_key_pem']) . '</textarea></td></tr>';
            echo '<tr><th>Slug callback pagamento partner</th><td><input type="text" class="regular-text" name="payment_callback_slug" value="' . esc_attr($settings['payment_callback_slug']) . '" placeholder="partner-payment-callback"><p class="description">Percorso chiamato dal partner per confermare il pagamento.</p></td></tr>';
            echo '<tr><th>Secret callback pagamento</th><td><input type="text" class="regular-text" name="payment_callback_secret" value="' . esc_attr($settings['payment_callback_secret']) . '" placeholder="secret condiviso"></td></tr>';
            echo '<tr><th>Stato di successo pagamento</th><td><input type="text" class="regular-text" name="payment_success_status" value="' . esc_attr($settings['payment_success_status']) . '" placeholder="attesa_partner"><p class="description">Slug dello stato da impostare quando il partner conferma il pagamento (es. attesa_partner).</p></td></tr>';
            echo '<tr><th colspan="2"><hr style="margin:4px 0;"><strong>Shortcode [sos_partner_prenota] &mdash; uso self-service</strong><p class="description" style="font-weight:normal;">Usato quando vuoi inserire un pulsante &quot;Prenota&quot; direttamente su una pagina di questo sito senza un portale partner esterno. La chiave privata qui sotto firma la richiesta di login.</p></th></tr>';
            echo '<tr><th>Partner ID self-use</th><td><input type="text" class="regular-text" name="self_login_partner_id" value="' . esc_attr($settings['self_login_partner_id']) . '" placeholder="hf"><p class="description">Partner ID di default per lo shortcode quando non specificato nell\'attributo.</p></td></tr>';
            echo '<tr><th>URL endpoint login</th><td><input type="url" class="regular-text" name="self_login_endpoint_url" value="' . esc_attr($settings['self_login_endpoint_url']) . '" placeholder="https://videoconsulto.sospediatra.org/partner-login/"><p class="description">Lascia vuoto per usare l\'endpoint locale (<code>' . esc_html(home_url($this->current_endpoint_path() . '/')) . '</code>). Compila con l\'URL completo del sito principale se il plugin &egrave; installato su un sito partner separato.</p></td></tr>';
            echo '<tr><th>Chiave privata self-use (PEM)</th><td><textarea class="large-text code" rows="10" name="self_login_private_key_pem" placeholder="-----BEGIN PRIVATE KEY-----"></textarea><p class="description">Per sicurezza il valore salvato non viene mostrato. Inserisci una nuova chiave solo se vuoi aggiornarla; lasciando il campo vuoto il valore attuale resta invariato. <strong>La chiave deve corrispondere alla chiave pubblica configurata sull\'endpoint di login.</strong></p></td></tr>';
        }

        echo '</table>';
        submit_button('Salva impostazioni');
        echo '</form></div>';

        if (!$is_partner) {
            // Sconti partner (solo modalita principale)
            $discounts = $this->get_partner_discounts();
            echo '<div class="wrap" style="margin-top:24px;"><h2>Sconti / Pagamento Partner</h2>';
            echo '<p>Configura per ogni partner lo sconto e la modalit&agrave; di pagamento.</p>';
            echo '<ul style="margin-left:1.5em;font-size:.9em;">';
            echo '<li><strong>Sconto fisso (&euro;)</strong>: sottrae l\'importo indicato dal totale mostrato al cliente sul sito principale.</li>';
            echo '<li><strong>Sconto % </strong>: applica una percentuale di sconto sul totale.</li>';
            echo '<li><strong>Pagamento su sito partner</strong>: il cliente non paga sul sito principale (totale = 0), il partner incassa sulla propria piattaforma e invia il callback di conferma. Il webhook include <code>partner_charge</code> con l\'importo originale da incassare.</li>';
            echo '</ul>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('sos_pg_save_discounts');
            echo '<input type="hidden" name="action" value="sos_pg_save_discounts">';
            echo '<table class="widefat striped"><thead><tr><th>Partner ID</th><th>Sconto</th><th>Tipo</th><th>Pagamento su sito partner</th></tr></thead><tbody>';
            $rows_raw = $discounts;
            $rows_raw[''] = [];
            foreach ($rows_raw as $pid => $cfg) {
                // Backward compat: old format was plain float
                if (!is_array($cfg)) {
                    $cfg = ['amount' => (float) $cfg, 'type' => 'fixed', 'pay_on_partner' => false];
                }
                $d_amount       = isset($cfg['amount']) ? esc_attr((string) $cfg['amount']) : '';
                $d_type         = isset($cfg['type']) ? $cfg['type'] : 'fixed';
                $d_pop          = !empty($cfg['pay_on_partner']);
                echo '<tr>';
                echo '<td><input type="text" name="discounts[partner_id][]" value="' . esc_attr($pid) . '" class="regular-text" placeholder="es. hf"></td>';
                echo '<td><input type="number" step="0.01" min="0" name="discounts[amount][]" value="' . $d_amount . '" class="regular-text" style="width:90px;" placeholder="0"></td>';
                echo '<td><select name="discounts[type][]" style="min-width:90px;">';
                echo '<option value="fixed" ' . selected($d_type, 'fixed', false) . '>&euro; fisso</option>';
                echo '<option value="percent" ' . selected($d_type, 'percent', false) . '>% sconto</option>';
                echo '</select></td>';
                echo '<td style="text-align:center;"><label><input type="checkbox" name="discounts[pay_on_partner][]" value="' . esc_attr($pid === '' ? '__new__' : $pid) . '" ' . ($d_pop ? 'checked' : '') . '> Abilitato</label></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '<p class="description" style="margin-top:6px;">Lascia Sconto = 0 se non si applica nessuno sconto. Il flag &quot;Pagamento su sito partner&quot; &egrave; indipendente dallo sconto.</p>';
            echo '<p><button class="button button-primary" type="submit">Salva configurazione partner</button></p>';
            echo '</form></div>';
        }
    }

    public function render_partner_configs_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        $configs = $this->settings_helper->get_partner_configs_option();
        if (!is_array($configs)) {
            $configs = [];
        }

        $user_id = get_current_user_id();
        $save_notice = get_transient('sos_pg_partner_configs_notice_' . $user_id);
        if ($save_notice) {
            delete_transient('sos_pg_partner_configs_notice_' . $user_id);
        }

        echo '<div class="wrap">';
        echo '<h1>Partner multipli (beta)</h1>';

        $global_notice_partner_id = is_array($save_notice) ? (string) ($save_notice['partner_id'] ?? '') : '';
        if (!empty($save_notice['messages']) && is_array($save_notice['messages']) && $global_notice_partner_id === '') {
            $global_notice_class = ($save_notice['type'] ?? '') === 'success' ? 'notice-success' : 'notice-error';
            foreach ($save_notice['messages'] as $message) {
                echo '<div class="notice ' . esc_attr($global_notice_class) . ' is-dismissible"><p>' . esc_html((string) $message) . '</p></div>';
            }
        }

        echo '<p>Configurazione per-partner. Espandi un blocco per modificarlo. Il blocco <em>Nuovo partner</em> è sempre aperto per inserimento rapido.</p>';
        $this->render_environment_warning_panel('partner_configs', $configs);

        echo '<style>
            .sos-pg-partner-card { border: 1px solid #ccd0d4; background: #fff; margin-bottom: 12px; }
            .sos-pg-partner-card > summary { padding: 9px 14px; cursor: pointer; font-weight: 600; font-size: 13px; background: #f6f7f7; list-style: none; display: flex; align-items: center; gap: 8px; }
            .sos-pg-partner-card > summary:hover { background: #e8e8e8; }
            .sos-pg-partner-card > summary::before { content: "▶"; font-size: 10px; transition: transform .15s; }
            .sos-pg-partner-card[open] > summary::before { transform: rotate(90deg); }
            .sos-pg-card-body { padding: 12px 18px 16px; }
            .sos-pg-card-body table { width: 100%; border-collapse: collapse; }
            .sos-pg-card-body th { width: 230px; text-align: left; padding: 6px 10px 6px 0; vertical-align: top; font-weight: 400; color: #3c434a; }
            .sos-pg-card-body td { padding: 3px 0 8px 0; }
            .sos-pg-card-body input.regular-text,
            .sos-pg-card-body textarea.regular-text { width: 100%; max-width: 500px; box-sizing: border-box; }
            .sos-field-note { color: #777; font-size: 11px; margin: 2px 0 0; }
            .sos-nr-badge { display: none; background: #f0f0f1; border: 1px solid #c3c4c7; color: #888; font-size: 10px; padding: 1px 5px; border-radius: 8px; margin-left: 4px; font-weight: 400; }
            .sos-field-not-relevant { opacity: 0.45; }
            .sos-field-not-relevant .sos-nr-badge { display: inline; }
            .sos-legacy-key-warn { background: #fff8e5; border-left: 3px solid #dba617; padding: 5px 9px; margin-top: 4px; font-size: 12px; }
            .sos-pg-inline-notice { margin: 0 0 12px 0; padding: 10px 12px; border-left: 4px solid #d63638; background: #fff; }
            .sos-pg-inline-notice.success { border-left-color: #00a32a; }
            .sos-pg-path-status { display: inline-block; padding: 4px 8px; border-radius: 999px; background: #f0f6fc; color: #0f5132; font-size: 11px; font-weight: 600; }
            .sos-pg-path-status.is-warning { background: #fff8e5; color: #8a5a00; }
            .sos-pg-path-field { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
            .sos-pg-path-editor { width: 100%; }
            .sos-pg-card-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding-top: 10px; border-top: 1px solid #f0f0f1; margin-top: 8px; }
        </style>';

        echo '<script>
        (function(){
            var relevance = {
                api_base_url:              ["external_api"],
                api_key:                   ["external_api"],
                public_key_pem:            ["embedded_booking"],
                private_key_path:          ["embedded_booking"],
                webhook_secret:            ["wordpress","external_api"],
                callback_secret:           ["external_api","embedded_booking"],
                validation_token_strategy: ["embedded_booking"],
                external_ref_mapping:      ["embedded_booking"]
            };
            window.sosPgUpdateType = function(sel, idx) {
                var type  = sel.value;
                var card  = document.getElementById("sos-pg-card-" + idx);
                if (!card) return;
                card.querySelectorAll("tr[data-field]").forEach(function(row) {
                    var field = row.getAttribute("data-field");
                    var rel   = relevance[field];
                    if (!rel || rel.indexOf(type) >= 0) {
                        row.classList.remove("sos-field-not-relevant");
                    } else {
                        row.classList.add("sos-field-not-relevant");
                    }
                });
            };
            window.sosPgEnablePathEdit = function(button) {
                var wrapper = button.closest(".sos-pg-path-field");
                if (!wrapper) return;
                var status = wrapper.querySelector(".sos-pg-path-readonly");
                var editor = wrapper.querySelector(".sos-pg-path-editor");
                var retain = wrapper.querySelector("input[type=\"hidden\"][name=\"partner[retain_private_key_path]\"]");
                if (status) status.style.display = "none";
                if (editor) editor.style.display = "block";
                if (retain) retain.value = "0";
                button.style.display = "none";
                if (editor) {
                    var input = editor.querySelector("input");
                    if (input) input.focus();
                }
            };
        })();
        </script>';

        // Mappa di rilevanza per classe CSS iniziale (calcolata server-side).
        $relevance_map = [
            'api_base_url'              => ['external_api'],
            'api_key'                   => ['external_api'],
            'public_key_pem'            => ['embedded_booking'],
            'private_key_path'          => ['embedded_booking'],
            'webhook_secret'            => ['wordpress', 'external_api'],
            'callback_secret'           => ['external_api', 'embedded_booking'],
            'validation_token_strategy' => ['embedded_booking'],
            'external_ref_mapping'      => ['embedded_booking'],
        ];

        $nr_class = function($field, $type) use ($relevance_map) {
            if (!isset($relevance_map[$field])) return '';
            return in_array($type, $relevance_map[$field], true) ? '' : ' sos-field-not-relevant';
        };

        // Aggiungi riga vuota per nuovo inserimento.
        $all_configs = $configs;
        $all_configs[''] = [
            'partner_id' => '', 'enabled' => true, 'type' => 'wordpress',
            'integration_mode' => '', 'completion_return_url' => '', 'api_base_url' => '', 'api_key' => '',
            'public_key_pem' => '', 'private_key_path' => '', 'private_key_pem' => '',
            'webhook_url' => '', 'webhook_secret' => '', 'callback_secret' => '',
            'no_upfront_cost' => false, 'validation_token_strategy' => '',
            'external_ref_mapping' => '', 'notes' => '', 'flags' => [], 'metadata' => [],
        ];

        $index = 0;
        foreach ($all_configs as $pid => $cfg) {
            $is_new    = ($pid === '');
            $pid_val   = $is_new ? '' : (string) $pid;
            $enabled   = !empty($cfg['enabled']);
            $type      = sanitize_text_field((string) ($cfg['type'] ?? 'wordpress'));
            $int_mode  = (string) ($cfg['integration_mode'] ?? '');
            $completion_return_url = (string) ($cfg['completion_return_url'] ?? '');
            $api_url   = (string) ($cfg['api_base_url'] ?? '');
            $api_key   = (string) ($cfg['api_key'] ?? '');
            $pub_pem   = (string) ($cfg['public_key_pem'] ?? '');
            $pk_path   = (string) ($cfg['private_key_path'] ?? '');
            $has_leg   = trim((string) ($cfg['private_key_pem'] ?? '')) !== '';
            $wh_url    = (string) ($cfg['webhook_url'] ?? '');
            $wh_sec    = (string) ($cfg['webhook_secret'] ?? '');
            $cb_sec    = (string) ($cfg['callback_secret'] ?? '');
            $no_up     = !empty($cfg['no_upfront_cost']);
            $vts       = (string) ($cfg['validation_token_strategy'] ?? '');
            $ext_ref   = (string) ($cfg['external_ref_mapping'] ?? '');
            $notes     = (string) ($cfg['notes'] ?? '');
            $flags     = isset($cfg['flags']) && is_array($cfg['flags']) ? $cfg['flags'] : [];
            $metadata  = isset($cfg['metadata']) && is_array($cfg['metadata']) ? $cfg['metadata'] : [];
            $flags_j   = wp_json_encode($flags);
            $meta_j    = wp_json_encode($metadata);

            $i      = esc_attr($index);
            $i_js   = esc_js((string) $index);
            $open   = $is_new ? 'open' : '';
            $title  = $is_new
                ? '+ Nuovo partner'
                : esc_html($pid_val) . ' &nbsp;<span style="font-weight:400;color:#666;">[' . esc_html($type) . ']' . ($enabled ? '' : ' — disabilitato') . '</span>';

            $notice_partner_id = is_array($save_notice) ? (string) ($save_notice['partner_id'] ?? '') : '';
            $show_inline_notice = !empty($save_notice['messages'])
                && is_array($save_notice['messages'])
                && ($notice_partner_id === $pid_val || ($is_new && $notice_partner_id === '__new__'));

            $pk_label = '';
            $pk_status_class = 'sos-pg-path-status';
            if ($pk_path !== '') {
                $pk_basename = basename(str_replace('\\', '/', $pk_path));
                $pk_is_valid = false;
                $pk_real = realpath($pk_path);
                if ($pk_real !== false && is_file($pk_real) && is_readable($pk_real)) {
                    $pk_is_valid = true;
                }
                $pk_label = $pk_is_valid
                    ? 'File chiave configurato · Percorso valido · ' . $pk_basename
                    : 'File chiave configurato · Percorso da verificare · ' . $pk_basename;
                if (!$pk_is_valid) {
                    $pk_status_class .= ' is-warning';
                }
            }

            echo '<details class="sos-pg-partner-card" id="sos-pg-card-' . $i . '" ' . $open . '>';
            echo '<summary>' . $title . '</summary>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('sos_pg_save_partner_configs');
            echo '<input type="hidden" name="action" value="sos_pg_save_partner_configs" />';
            echo '<input type="hidden" name="original_partner_id" value="' . esc_attr($pid_val) . '" />';
            echo '<div class="sos-pg-card-body"><table>';

            if ($show_inline_notice) {
                $inline_notice_class = ($save_notice['type'] ?? '') === 'success' ? 'sos-pg-inline-notice success' : 'sos-pg-inline-notice';
                echo '<tr><td colspan="2"><div class="' . esc_attr($inline_notice_class) . '">';
                foreach ($save_notice['messages'] as $message) {
                    echo '<div>' . esc_html((string) $message) . '</div>';
                }
                echo '</div></td></tr>';
            }

            // Partner ID
            echo '<tr data-field="partner_id"><th><label>Partner ID</label></th><td>';
            if ($is_new) {
                echo '<input type="text" name="partner[partner_id]" value="" class="regular-text" placeholder="es. partner-abc" />';
                echo '<p class="sos-field-note">Identificatore univoco. Non modificabile dopo il primo salvataggio.</p>';
            } else {
                echo '<input type="text" name="partner[partner_id]" value="' . esc_attr($pid_val) . '" class="regular-text" readonly />';
            }
            echo '</td></tr>';

            // Abilitato + Tipo
            echo '<tr data-field="enabled_type"><th>Abilitato / Tipo</th><td>';
            echo '<label><input type="checkbox" name="partner[enabled]" value="1" ' . checked($enabled, true, false) . ' /> abilitato</label> &nbsp;';
            echo '<select name="partner[type]" onchange="sosPgUpdateType(this, \'' . $i_js . '\')">';
            foreach (['wordpress', 'external_api', 'embedded_booking'] as $opt) {
                echo '<option value="' . esc_attr($opt) . '" ' . selected($type, $opt, false) . '>' . esc_html($opt) . '</option>';
            }
            echo '</select>';
            echo '<p class="sos-field-note"><b>wordpress</b>: login WP + webhook. &nbsp;<b>external_api</b>: REST API esterna. &nbsp;<b>embedded_booking</b>: widget booking con firma ECC.</p>';
            echo '</td></tr>';

            // Integration mode
            echo '<tr data-field="integration_mode"><th>Integration mode</th><td>';
            echo '<input type="text" name="partner[integration_mode]" value="' . esc_attr($int_mode) . '" class="regular-text" placeholder="es. login_redirect" />';
            echo '</td></tr>';

            echo '<tr data-field="completion_return_url"><th>Completion return URL</th><td>';
            echo '<input type="text" name="partner[completion_return_url]" value="' . esc_attr($completion_return_url) . '" class="regular-text" placeholder="https://partner.example.it/ritorno-finale" />';
            echo '<p class="sos-field-note">URL assoluta usata come destinazione primaria del ritorno finale dalla completion page.</p>';
            echo '</td></tr>';

            // API base URL
            echo '<tr data-field="api_base_url" class="' . $nr_class('api_base_url', $type) . '"><th>';
            echo 'API base URL <span class="sos-nr-badge">non richiesto per questo tipo</span></th><td>';
            echo '<input type="text" name="partner[api_base_url]" value="' . esc_attr($api_url) . '" class="regular-text" placeholder="https://..." />';
            echo '<p class="sos-field-note">Solo external_api: URL base delle API del partner.</p>';
            echo '</td></tr>';

            // API key
            echo '<tr data-field="api_key" class="' . $nr_class('api_key', $type) . '"><th>';
            echo 'API key <span class="sos-nr-badge">non richiesto per questo tipo</span></th><td>';
            echo '<input type="text" name="partner[api_key]" value="' . esc_attr($api_key) . '" class="regular-text" />';
            echo '</td></tr>';

            // Public key PEM
            echo '<tr data-field="public_key_pem" class="' . $nr_class('public_key_pem', $type) . '"><th>';
            echo 'Public key PEM <span class="sos-nr-badge">non richiesto per questo tipo</span></th><td>';
            echo '<textarea name="partner[public_key_pem]" rows="3" class="regular-text">' . esc_textarea($pub_pem) . '</textarea>';
            echo '<p class="sos-field-note">Chiave pubblica ECC del partner (formato PEM). Usata per verificare le firme embedded_booking.</p>';
            echo '</td></tr>';

            // Private key path
            echo '<tr data-field="private_key_path" class="' . $nr_class('private_key_path', $type) . '"><th>';
            echo 'Private key path <span class="sos-nr-badge">non richiesto per questo tipo</span></th><td>';
            echo '<div class="sos-pg-path-field">';
            if (!$is_new && $pk_path !== '') {
                echo '<input type="hidden" name="partner[retain_private_key_path]" value="1" />';
                echo '<div class="sos-pg-path-readonly">';
                echo '<span class="' . esc_attr($pk_status_class) . '">' . esc_html($pk_label) . '</span>';
                echo ' <button type="button" class="button button-secondary button-small" onclick="sosPgEnablePathEdit(this)">Modifica percorso</button>';
                echo '</div>';
                echo '<div class="sos-pg-path-editor" style="display:none;">';
                echo '<input type="text" name="partner[private_key_path]" value="" class="regular-text" placeholder="/percorso/assoluto/private.pem" />';
                echo '</div>';
            } else {
                echo '<input type="hidden" name="partner[retain_private_key_path]" value="0" />';
                echo '<div class="sos-pg-path-editor">';
                echo '<input type="text" name="partner[private_key_path]" value="" class="regular-text" placeholder="/percorso/assoluto/private.pem" />';
                echo '</div>';
            }
            echo '</div>';
            echo '<p class="sos-field-note">Percorso assoluto al file PEM della chiave privata sul filesystem del server. Dopo il salvataggio il path completo non viene più mostrato in chiaro.</p>';
            if ($has_leg) {
                echo '<div class="sos-legacy-key-warn">&#9888; Config legacy: chiave privata ancora salvata nel database. Imposta <em>Private key path</em> e salva per migrare alla lettura da file.</div>';
            }
            echo '</td></tr>';

            // Webhook URL
            echo '<tr data-field="webhook_url"><th>Webhook URL</th><td>';
            echo '<input type="text" name="partner[webhook_url]" value="' . esc_attr($wh_url) . '" class="regular-text" placeholder="https://..." />';
            echo '<p class="sos-field-note">URL dove il plugin invia le notifiche evento al sistema del partner.</p>';
            echo '</td></tr>';

            // Webhook secret
            echo '<tr data-field="webhook_secret" class="' . $nr_class('webhook_secret', $type) . '"><th>';
            echo 'Webhook secret <span class="sos-nr-badge">non richiesto per questo tipo</span></th><td>';
            echo '<input type="text" name="partner[webhook_secret]" value="' . esc_attr($wh_sec) . '" class="regular-text" />';
            echo '<p class="sos-field-note">Secret HMAC condiviso per firmare le notifiche webhook in uscita.</p>';
            echo '</td></tr>';

            // Callback secret
            echo '<tr data-field="callback_secret" class="' . $nr_class('callback_secret', $type) . '"><th>';
            echo 'Callback secret <span class="sos-nr-badge">non richiesto per questo tipo</span></th><td>';
            echo '<input type="text" name="partner[callback_secret]" value="' . esc_attr($cb_sec) . '" class="regular-text" />';
            echo '<p class="sos-field-note">Secret usato dal partner per firmare le callback di pagamento in arrivo.</p>';
            echo '</td></tr>';

            // No upfront cost
            echo '<tr data-field="no_upfront_cost"><th>No upfront cost</th><td>';
            echo '<label><input type="checkbox" name="partner[no_upfront_cost]" value="1" ' . checked($no_up, true, false) . ' /> pagamento gestito dal partner</label>';
            echo '<p class="sos-field-note">Alias compatibile del flag storico <strong>no_upfront_cost</strong>: quando attivo il gateway non incassa upfront e il pagamento resta a carico del partner.</p>';
            echo '</td></tr>';

            // Validation token strategy
            echo '<tr data-field="validation_token_strategy" class="' . $nr_class('validation_token_strategy', $type) . '"><th>';
            echo 'Validation token strategy <span class="sos-nr-badge">non richiesto per questo tipo</span></th><td>';
            echo '<input type="text" name="partner[validation_token_strategy]" value="' . esc_attr($vts) . '" class="regular-text" placeholder="es. bearer_token" />';
            echo '<p class="sos-field-note">Solo embedded_booking: modalità di validazione del token nella richiesta.</p>';
            echo '</td></tr>';

            // External ref mapping
            echo '<tr data-field="external_ref_mapping" class="' . $nr_class('external_ref_mapping', $type) . '"><th>';
            echo 'External ref mapping <span class="sos-nr-badge">non richiesto per questo tipo</span></th><td>';
            echo '<input type="text" name="partner[external_ref_mapping]" value="' . esc_attr($ext_ref) . '" class="regular-text" placeholder="es. booking_id" />';
            echo '<p class="sos-field-note">Chiave usata per collegare e riconciliare callback pagamento, booking interni e riferimenti esterni del partner.</p>';
            echo '</td></tr>';

            // Note
            echo '<tr data-field="notes"><th>Note</th><td>';
            echo '<textarea name="partner[notes]" rows="2" class="regular-text">' . esc_textarea($notes) . '</textarea>';
            echo '</td></tr>';

            // Flags
            echo '<tr data-field="flags"><th>Flags (JSON)</th><td>';
            echo '<textarea name="partner[flags]" rows="2" class="regular-text" placeholder="[]">' . esc_textarea($flags_j) . '</textarea>';
            echo '<p class="sos-field-note">Array JSON di flag o opzioni partner-specifiche usate per attivare comportamenti configurabili senza cambiare la semantica del partner.</p>';
            echo '</td></tr>';

            // Metadata
            echo '<tr data-field="metadata"><th>Metadata (JSON)</th><td>';
            echo '<textarea name="partner[metadata]" rows="2" class="regular-text" placeholder="{}">' . esc_textarea($meta_j) . '</textarea>';
            echo '<p class="sos-field-note">Oggetto JSON con dati extra strutturati del partner, utile per memorizzare attributi aggiuntivi senza introdurre nuovi campi dedicati.</p>';
            echo '</td></tr>';

            echo '</table>';
            echo '<div class="sos-pg-card-actions">';
            echo '<button type="submit" name="partner_action" value="save" class="button button-primary">Salva partner</button>';
            if (!$is_new) {
                echo '<button type="submit" name="partner_action" value="delete" class="button button-secondary" onclick="return window.confirm(\'Eliminare il partner ' . esc_js($pid_val) . '? Questa azione rimuove solo questa configurazione.\')">Elimina partner</button>';
            }
            echo '</div>';
            echo '</div>';
            echo '</form>';
            echo '</details>';

            $index++;
        }
        echo '</div>';
    }

    public function handle_save_partner_configs() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        check_admin_referer('sos_pg_save_partner_configs');

        $existing_configs = $this->settings_helper->get_partner_configs_option();
        if (!is_array($existing_configs)) {
            $existing_configs = [];
        }

        $entry = isset($_POST['partner']) && is_array($_POST['partner']) ? $_POST['partner'] : [];
        $original_partner_id = sanitize_text_field((string) ($_POST['original_partner_id'] ?? ''));
        $partner_action = sanitize_key((string) ($_POST['partner_action'] ?? 'save'));

        if ($partner_action === 'delete') {
            if ($original_partner_id === '' || !isset($existing_configs[$original_partner_id])) {
                $this->set_partner_configs_notice('error', ['Partner non trovato: eliminazione non eseguita.']);
                wp_safe_redirect(admin_url('admin.php?page=sos-partner-gateway-partners'));
                exit;
            }

            unset($existing_configs[$original_partner_id]);
            update_option($this->settings_helper->get_partner_configs_key(), $existing_configs, false);
            $this->set_partner_configs_notice('success', ['Partner "' . $original_partner_id . '" eliminato.']);
            wp_safe_redirect(admin_url('admin.php?page=sos-partner-gateway-partners'));
            exit;
        }

        $target_partner_id = $original_partner_id !== '' ? $original_partner_id : sanitize_text_field((string) ($entry['partner_id'] ?? ''));
        $existing_cfg = isset($existing_configs[$target_partner_id]) && is_array($existing_configs[$target_partner_id])
            ? $existing_configs[$target_partner_id]
            : [];

        $build = $this->build_partner_config_from_entry($entry, $existing_cfg, $target_partner_id);
        if (!empty($build['errors'])) {
            $notice_partner_id = $build['partner_id'] !== '' ? $build['partner_id'] : '__new__';
            $this->set_partner_configs_notice('error', $build['errors'], $notice_partner_id);
            wp_safe_redirect(admin_url('admin.php?page=sos-partner-gateway-partners'));
            exit;
        }

        $existing_configs[$build['partner_id']] = $build['config'];
        update_option($this->settings_helper->get_partner_configs_key(), $existing_configs, false);

        $this->set_partner_configs_notice('success', ['Partner "' . $build['partner_id'] . '" salvato.'], $build['partner_id']);
        wp_safe_redirect(admin_url('admin.php?page=sos-partner-gateway-partners'));
        exit;
    }

    private function set_partner_configs_notice($type, array $messages, $partner_id = '') {
        $user_id = get_current_user_id();
        set_transient('sos_pg_partner_configs_notice_' . $user_id, [
            'type' => $type === 'success' ? 'success' : 'error',
            'messages' => array_values($messages),
            'partner_id' => (string) $partner_id,
        ], 60);
    }

    private function build_partner_config_from_entry(array $entry, array $existing_cfg = [], $forced_partner_id = '') {
        $partner_id = $forced_partner_id !== ''
            ? $forced_partner_id
            : sanitize_text_field((string) ($entry['partner_id'] ?? ''));
        $errors = [];

        if ($partner_id === '') {
            return [
                'partner_id' => '',
                'config' => [],
                'errors' => ['Partner ID mancante.'],
            ];
        }

        $type = sanitize_text_field((string) ($entry['type'] ?? 'wordpress'));
        if (!in_array($type, ['wordpress', 'external_api', 'embedded_booking'], true)) {
            $type = 'wordpress';
        }

        $existing_pem = trim((string) ($existing_cfg['private_key_pem'] ?? ''));
        $existing_path = trim((string) ($existing_cfg['private_key_path'] ?? ''));
        $retain_private_key_path = !empty($entry['retain_private_key_path']);
        $private_key_path = '';
        $private_key_path_input = sanitize_text_field((string) ($entry['private_key_path'] ?? ''));

        if ($private_key_path_input !== '') {
            $real = realpath($private_key_path_input);
            $path_error_reason = '';
            if ($real === false) {
                $path_error_reason = 'file non trovato';
            } elseif (!is_file($real)) {
                $path_error_reason = 'il percorso non punta a un file';
            } elseif (!is_readable($real)) {
                $path_error_reason = 'file non leggibile';
            }

            if ($path_error_reason !== '') {
                $errors[] = 'Partner "' . $partner_id . '": campo private_key_path non valido (' . $path_error_reason . ').';
            } else {
                $private_key_path = $real;
            }
        } elseif ($retain_private_key_path && $existing_path !== '') {
            $private_key_path = $existing_path;
        } elseif ($type === 'embedded_booking' && $existing_pem === '') {
            $errors[] = 'Partner "' . $partner_id . '": campo private_key_path obbligatorio per embedded_booking.';
        }

        if (!empty($errors)) {
            return [
                'partner_id' => $partner_id,
                'config' => [],
                'errors' => $errors,
            ];
        }

        $completion_return_url = $this->validate_completion_return_url((string) ($entry['completion_return_url'] ?? ''));
        if ((string) ($entry['completion_return_url'] ?? '') !== '' && $completion_return_url === '') {
            return [
                'partner_id' => $partner_id,
                'config' => [],
                'errors' => ['Partner "' . $partner_id . '": campo completion_return_url non valido. Inserisci una URL assoluta http/https.'],
            ];
        }

        $cfg = [
            'partner_id'                => $partner_id,
            'enabled'                   => !empty($entry['enabled']),
            'type'                      => $type,
            'integration_mode'          => sanitize_text_field((string) ($entry['integration_mode'] ?? '')),
            'completion_return_url'     => $completion_return_url,
            'api_base_url'              => esc_url_raw((string) ($entry['api_base_url'] ?? '')),
            'api_key'                   => (string) ($entry['api_key'] ?? ''),
            'public_key_pem'            => (string) ($entry['public_key_pem'] ?? ''),
            'private_key_path'          => $private_key_path,
            'webhook_url'               => esc_url_raw((string) ($entry['webhook_url'] ?? '')),
            'webhook_secret'            => (string) ($entry['webhook_secret'] ?? ''),
            'callback_secret'           => (string) ($entry['callback_secret'] ?? ''),
            'validation_token_strategy' => sanitize_text_field((string) ($entry['validation_token_strategy'] ?? '')),
            'external_ref_mapping'      => sanitize_text_field((string) ($entry['external_ref_mapping'] ?? '')),
            'no_upfront_cost'           => !empty($entry['no_upfront_cost']),
            'notes'                     => sanitize_textarea_field((string) ($entry['notes'] ?? '')),
        ];

        if ($existing_pem !== '') {
            $cfg['private_key_pem'] = $existing_pem;
        }

        $flags_raw = isset($entry['flags']) ? (string) $entry['flags'] : '';
        $metadata_raw = isset($entry['metadata']) ? (string) $entry['metadata'] : '';
        $cfg['flags'] = $this->parse_json_array_or_csv_list($flags_raw);
        $cfg['metadata'] = $this->parse_json_struct_or_empty($metadata_raw);

        return [
            'partner_id' => $partner_id,
            'config' => $cfg,
            'errors' => [],
        ];
    }

    private function parse_json_array_or_csv_list($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fallback: split by comma for quick entry.
        $parts = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
        return array_values($parts);
    }

    private function parse_json_struct_or_empty($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return [];
    }
    public function render_pages_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        $pages = $this->get_partner_pages();
        $routes = $this->get_partner_routes();

        echo '<div class="wrap"><h1>SOS Partner Gateway — Pagine Partner</h1>';
        echo '<p>Configura il box <strong>SOS Partner Gateway</strong> dentro l’editor pagina.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Pagina</th><th>Partner ID</th><th>Redirect</th><th>Sconto</th><th>Stato</th><th>Location</th></tr></thead><tbody>';

        if (!$pages) {
            echo '<tr><td colspan="6">Nessuna pagina partner configurata.</td></tr>';
        }

        foreach ((array) $pages as $page) {
            echo '<tr>';
            echo '<td><a href="' . esc_url(get_edit_post_link($page->ID)) . '">' . esc_html($page->post_title) . '</a></td>';
            echo '<td>' . esc_html(get_post_meta($page->ID, '_sos_pg_partner_id', true)) . '</td>';
            echo '<td>' . esc_html(get_post_meta($page->ID, '_sos_pg_redirect_path', true) ?: get_permalink($page->ID)) . '</td>';
            echo '<td>' . esc_html(get_post_meta($page->ID, '_sos_pg_discount_amount', true)) . '</td>';
            echo '<td>' . esc_html(get_post_meta($page->ID, '_sos_pg_initial_status', true)) . '</td>';
            echo '<td>' . esc_html(get_post_meta($page->ID, '_sos_pg_location_label', true)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        echo '<h2 style="margin-top:24px;">Routing personalizzato (senza modificare la pagina)</h2>';
        echo '<p>Imposta un percorso/URL di atterraggio per ciascun partner senza aprire l’editor pagina.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
        wp_nonce_field('sos_pg_save_routes');
        echo '<input type="hidden" name="action" value="sos_pg_save_routes">';
        echo '<table class="widefat striped"><thead><tr><th>Partner ID</th><th>Percorso/URL</th></tr></thead><tbody>';

        // Mostra righe esistenti + una riga vuota per aggiungerne una nuova.
        $rows = $routes;
        $rows[''] = '';
        foreach ($rows as $pid => $path) {
            echo '<tr>';
            echo '<td><input type="text" name="routes[partner_id][]" value="' . esc_attr($pid) . '" class="regular-text" placeholder="es. hf"></td>';
            echo '<td><input type="text" name="routes[path][]" value="' . esc_attr($path) . '" class="regular-text" placeholder="es. /partner-hf/ o https://example.com/pagina"></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p><button class="button button-primary" type="submit">Salva routing partner</button></p>';
        echo '</form>';

        echo '<h2 style="margin-top:24px;">Webhook partner (booking_created)</h2>';
        echo '<p>Configura URL e secret per ogni partner. Il payload inviato include booking_id, status, total, orario, customer_email.</p>';
        echo '<p><em>Location ID LatePoint</em>: inserisci il numero (ID) della posizione LatePoint associata al partner. Questo consente di inviare il webhook anche quando il partner non è rilevabile dalla sessione WordPress.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
        wp_nonce_field('sos_pg_save_webhooks');
        echo '<input type="hidden" name="action" value="sos_pg_save_webhooks">';
        $webhooks = $this->get_partner_webhooks();
        $webhooks[''] = ['url' => '', 'secret' => '', 'location_id' => ''];
        echo '<table class="widefat striped"><thead><tr><th>Partner ID</th><th>Webhook URL</th><th>Secret (HMAC)</th><th>Location ID LatePoint</th></tr></thead><tbody>';
        foreach ($webhooks as $pid => $cfg) {
            $url = is_array($cfg) ? ($cfg['url'] ?? '') : '';
            $secret = is_array($cfg) ? ($cfg['secret'] ?? '') : '';
            $loc_id = is_array($cfg) ? ($cfg['location_id'] ?? '') : '';
            echo '<tr>';
            echo '<td><input type="text" name="webhooks[partner_id][]" value="' . esc_attr($pid) . '" class="regular-text" placeholder="es. hf"></td>';
            echo '<td><input type="url" name="webhooks[url][]" value="' . esc_attr($url) . '" class="regular-text" placeholder="https://partner.example.com/webhook"></td>';
            echo '<td><input type="text" name="webhooks[secret][]" value="' . esc_attr($secret) . '" class="regular-text" placeholder="secret condiviso"></td>';
            echo '<td><input type="number" min="1" name="webhooks[location_id][]" value="' . esc_attr($loc_id) . '" class="small-text" placeholder="es. 3"></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p><button class="button button-primary" type="submit">Salva webhook partner</button></p>';
        echo '</form>';
    }

    public function render_test_payment_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        $settings = $this->get_settings();
        $callback_url = home_url($this->current_payment_callback_path());
        $msg = sanitize_text_field(wp_unslash($_GET['msg'] ?? ''));
        $detail = sanitize_text_field(wp_unslash($_GET['detail'] ?? ''));

        echo '<div class="wrap"><h1>SOS Partner Gateway — Test callback pagamento</h1>';

        if ($msg === 'ok') {
            echo '<div class="notice notice-success is-dismissible"><p>Callback inviata con successo ' . ($detail !== '' ? esc_html('(' . $detail . ')') : '') . '.</p></div>';
        } elseif ($msg === 'fail') {
            echo '<div class="notice notice-error is-dismissible"><p>Errore invio callback: ' . esc_html($detail !== '' ? $detail : 'verifica log') . '.</p></div>';
        }

        $this->render_environment_warning_panel('payment_test');

        echo '<p>Invia una richiesta firmata al callback pagamento per testare la chiusura della prenotazione.</p>';
        echo '<p><strong>URL callback:</strong> <code>' . esc_html($callback_url) . '</code></p>';
        echo '<p><strong>Secret:</strong> ' . ($settings['payment_callback_secret'] ? '<code>configurato</code>' : '<span style="color:#d63638;">manca</span>') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px;">';
        wp_nonce_field('sos_pg_send_payment_test');
        echo '<input type="hidden" name="action" value="sos_pg_send_payment_test">';
        echo '<table class="form-table">';
        echo '<tr><th>Booking ID</th><td><input type="number" name="booking_id" required min="1" class="regular-text"> <span class="description">ID prenotazione LatePoint</span></td></tr>';
        echo '<tr><th>Partner ID</th><td><input type="text" name="partner_id" class="regular-text" placeholder="hf"> <span class="description">Facoltativo</span></td></tr>';
        echo '<tr><th>Status da inviare</th><td><input type="text" name="status" class="regular-text" placeholder="pending" value="' . esc_attr($settings['payment_success_status']) . '"> <span class="description">Lascia vuoto per usare il valore di default</span></td></tr>';
        echo '<tr><th>Transaction ID</th><td><input type="text" name="transaction_id" class="regular-text" value="TEST-' . esc_attr(time()) . '"></td></tr>';
        echo '</table>';
        submit_button('Invia callback di test');
        echo '</form></div>';
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        check_admin_referer('sos_pg_save_settings');

        $settings = $this->get_settings();

        // Ruolo sito — determinato per primo per condizionare l'aggiornamento degli altri campi.
        $new_role = sanitize_key(wp_unslash($_POST['site_role'] ?? 'main'));
        $settings['site_role'] = in_array($new_role, ['main', 'partner'], true) ? $new_role : 'main';

        // Campi comuni a entrambe le modalità.
        $incoming_self_login_pem = trim((string) wp_unslash($_POST['self_login_private_key_pem'] ?? ''));
        if ($incoming_self_login_pem !== '') {
            $settings['self_login_private_key_pem'] = $incoming_self_login_pem;
        }
        $settings['self_login_partner_id'] = sanitize_text_field(wp_unslash($_POST['self_login_partner_id'] ?? ''));
        $settings['self_login_endpoint_url'] = esc_url_raw(trim((string) wp_unslash($_POST['self_login_endpoint_url'] ?? '')));

        if ($settings['site_role'] === 'partner') {
            // Campi presenti solo nel form del sito partner.
            // Si usa isset() per preservare i valori già salvati quando il POST proviene
            // dal form del sito principale (scenario: l'admin cambia il selettore ruolo
            // da "main" a "partner" e salva — il form visualizzato era quello del sito
            // principale e non include questi campi specifici del partner).
            // Nota: se la chiave è presente nel POST ma con valore vuoto (cancellazione
            // esplicita da parte dell'utente), il valore vuoto viene comunque scritto nel
            // database, rispettando così l'intenzione esplicita dell'admin.
            if (isset($_POST['partner_webhook_secret'])) {
                $settings['partner_webhook_secret'] = sanitize_text_field(wp_unslash($_POST['partner_webhook_secret']));
            }
            if (isset($_POST['partner_callback_url'])) {
                $settings['partner_callback_url'] = esc_url_raw(trim((string) wp_unslash($_POST['partner_callback_url'])));
            }
            if (isset($_POST['partner_callback_secret'])) {
                $settings['partner_callback_secret'] = sanitize_text_field(wp_unslash($_POST['partner_callback_secret']));
            }
        } else {
            // Campi presenti solo nel form del sito principale.
            $settings['debug_logging_enabled'] = !empty($_POST['debug_logging_enabled']) ? 1 : 0;
            $settings['endpoint_slug'] = sanitize_title(wp_unslash($_POST['endpoint_slug'] ?? 'partner-login'));
            $settings['courtesy_page_id'] = absint($_POST['courtesy_page_id'] ?? 0);
            $settings['max_fail_short'] = max(1, absint($_POST['max_fail_short'] ?? 10));
            $settings['max_fail_long'] = max(1, absint($_POST['max_fail_long'] ?? 25));
            $settings['ban_short_minutes'] = max(1, absint($_POST['ban_short_minutes'] ?? 60));
            $settings['ban_long_minutes'] = max(1, absint($_POST['ban_long_minutes'] ?? 1440));
            $settings['window_short_minutes'] = max(1, absint($_POST['window_short_minutes'] ?? 10));
            $settings['window_long_minutes'] = max(1, absint($_POST['window_long_minutes'] ?? 1440));
            $settings['public_key_pem'] = trim((string) wp_unslash($_POST['public_key_pem'] ?? ''));
            $settings['payment_callback_slug'] = sanitize_title(wp_unslash($_POST['payment_callback_slug'] ?? 'partner-payment-callback'));
            $settings['payment_callback_secret'] = sanitize_text_field(wp_unslash($_POST['payment_callback_secret'] ?? ''));
            $settings['payment_success_status'] = sanitize_text_field(wp_unslash($_POST['payment_success_status'] ?? 'pending')) ?: 'pending';
        }

        update_option($this->settings_key, $settings);

        // Bug fix: redirect to the correct admin page based on the active role.
        // In partner mode the settings page slug is 'sos-partner-gateway'; in main mode it is
        // 'sos-partner-gateway-settings'. Redirecting to the wrong slug shows "Non autorizzato".
        $redirect_page = $settings['site_role'] === 'partner' ? 'sos-partner-gateway' : 'sos-partner-gateway-settings';
        wp_safe_redirect(add_query_arg(['page' => $redirect_page, 'msg' => 'saved'], admin_url('admin.php')));
        exit;
    }

    public function handle_block_ip() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        check_admin_referer('sos_pg_block_ip');

        $ip = sanitize_text_field(wp_unslash($_POST['ip'] ?? ''));
        if ($ip === '') {
            wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway', 'msg' => 'ip_missing'], admin_url('admin.php')));
            exit;
        }

        $settings = $this->get_settings();
        $ban_minutes = max(1, (int) ($settings['ban_long_minutes'] ?? 1440));
        set_transient($this->ban_key($ip), 1, $ban_minutes * MINUTE_IN_SECONDS);

        $this->log_event('WARN', 'PARTNER_LOGIN_MANUAL_BLOCK', [
            'ip' => $ip,
            'reason' => 'Blocco manuale da admin | durata: ' . $ban_minutes . ' minuti',
        ]);

        wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway', 'msg' => 'blocked'], admin_url('admin.php')));
        exit;
    }

    public function handle_unlock_ip() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        check_admin_referer('sos_pg_unlock_ip');

        $ip = sanitize_text_field(wp_unslash($_POST['ip'] ?? ''));
        if ($ip === '') {
            wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway', 'msg' => 'ip_missing'], admin_url('admin.php')));
            exit;
        }

        delete_transient($this->ban_key($ip));
        delete_transient($this->fail_short_key($ip));
        delete_transient($this->fail_long_key($ip));

        $this->log_event('INFO', 'PARTNER_LOGIN_MANUAL_UNLOCK', [
            'ip' => $ip,
            'reason' => 'Sblocco manuale da admin',
        ]);

        wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway', 'msg' => 'unlocked'], admin_url('admin.php')));
        exit;
    }

    public function handle_clear_logs() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        check_admin_referer('sos_pg_clear_logs');

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_logs}");

        wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway', 'msg' => 'cleared'], admin_url('admin.php')));
        exit;
    }

    public function handle_save_discounts() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        check_admin_referer('sos_pg_save_discounts');

        $partner_ids    = $_POST['discounts']['partner_id']      ?? [];
        $amounts        = $_POST['discounts']['amount']           ?? [];
        $types          = $_POST['discounts']['type']             ?? [];
        $pop_values     = (array) ($_POST['discounts']['pay_on_partner'] ?? []);

        // pay_on_partner è inviato come array di partner_id checked.
        $pop_set = [];
        foreach ($pop_values as $v) {
            $pop_set[sanitize_text_field(wp_unslash($v))] = true;
        }

        $map = [];
        if (is_array($partner_ids)) {
            foreach ($partner_ids as $idx => $pid_raw) {
                $pid    = sanitize_text_field(wp_unslash($pid_raw));
                $amount = isset($amounts[$idx]) ? round((float) wp_unslash($amounts[$idx]), 4) : 0.0;
                $type   = isset($types[$idx]) && wp_unslash($types[$idx]) === 'percent' ? 'percent' : 'fixed';
                $pop    = isset($pop_set[$pid]) || ($pid === '' && isset($pop_set['__new__']));

                // Nuova riga: se il partner_id è stato compilato e il checkbox usava __new__, abilita pay_on_partner.
                if (!$pop && $pid !== '' && isset($pop_set['__new__'])) {
                    $pop = true;
                }

                if ($pid === '') {
                    continue;
                }

                // Salva solo se c'è uno sconto oppure il flag pay_on_partner è attivo.
                if ($amount <= 0 && !$pop) {
                    continue;
                }

                $map[$pid] = [
                    'amount'         => $amount,
                    'type'           => $type,
                    'pay_on_partner' => $pop,
                ];
            }
        }

        update_option($this->discounts_key, $map);

        wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway-settings', 'msg' => 'discount_saved'], admin_url('admin.php')));
        exit;
    }

    public function handle_save_webhooks() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        check_admin_referer('sos_pg_save_webhooks');

        $partner_ids = $_POST['webhooks']['partner_id'] ?? [];
        $urls = $_POST['webhooks']['url'] ?? [];
        $secrets = $_POST['webhooks']['secret'] ?? [];
        $location_ids = $_POST['webhooks']['location_id'] ?? [];

        $map = [];
        if (is_array($partner_ids) && is_array($urls) && is_array($secrets)) {
            foreach ($partner_ids as $idx => $pid_raw) {
                $pid = sanitize_text_field(wp_unslash($pid_raw));
                $url = isset($urls[$idx]) ? esc_url_raw(trim((string) wp_unslash($urls[$idx]))) : '';
                $secret = isset($secrets[$idx]) ? sanitize_text_field(wp_unslash($secrets[$idx])) : '';
                $loc_id = isset($location_ids[$idx]) ? trim(sanitize_text_field(wp_unslash((string) $location_ids[$idx]))) : '';
                // Accetta solo location_id numerici positivi (IDs LatePoint).
                if ($loc_id !== '' && (!ctype_digit($loc_id) || (int) $loc_id < 1)) {
                    $loc_id = '';
                }

                if ($pid === '' || $url === '') {
                    continue;
                }

                $map[$pid] = [
                    'url' => $url,
                    'secret' => $secret,
                    'location_id' => $loc_id,
                ];
            }
        }

        update_option($this->webhooks_key, $map);

        wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway-pages', 'msg' => 'webhooks_saved'], admin_url('admin.php')));
        exit;
    }

    public function handle_send_payment_test() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        check_admin_referer('sos_pg_send_payment_test');

        $booking_id = absint($_POST['booking_id'] ?? 0);
        $partner_id = sanitize_text_field(wp_unslash($_POST['partner_id'] ?? ''));
        $status = sanitize_text_field(wp_unslash($_POST['status'] ?? ''));
        $transaction_id = sanitize_text_field(wp_unslash($_POST['transaction_id'] ?? ''));

        $settings = $this->get_settings();

        if (!$booking_id) {
            wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway-payment-test', 'msg' => 'fail', 'detail' => rawurlencode('Booking ID mancante')], admin_url('admin.php')));
            exit;
        }

        if ($settings['payment_callback_secret'] === '') {
            wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway-payment-test', 'msg' => 'fail', 'detail' => rawurlencode('Secret non configurato')], admin_url('admin.php')));
            exit;
        }

        $callback_url = home_url($this->current_payment_callback_path());
        $payload = [
            'booking_id' => $booking_id,
        ];

        if ($partner_id !== '') {
            $payload['partner_id'] = $partner_id;
        }

        if ($status !== '') {
            $payload['status'] = $status;
        }

        $payload['transaction_id'] = $transaction_id !== '' ? $transaction_id : 'TEST-' . time();

        $body = wp_json_encode($payload);
        $headers = [
            'Content-Type' => 'application/json',
            'X-SOSPG-Signature' => hash_hmac('sha256', (string) $body, (string) $settings['payment_callback_secret']),
        ];

        $resp = wp_remote_post($callback_url, [
            'headers' => $headers,
            'body' => $body,
            'timeout' => 10,
        ]);

        if (is_wp_error($resp)) {
            $this->log_event('ERROR', 'PAYMENT_CALLBACK_TEST_FAIL', [
                'reason' => $resp->get_error_message(),
                'context' => ['booking_id' => $booking_id, 'partner_id' => $partner_id],
            ]);
            wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway-payment-test', 'msg' => 'fail', 'detail' => rawurlencode($resp->get_error_message())], admin_url('admin.php')));
            exit;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);

        if ($code < 200 || $code >= 300) {
            $body_preview = substr((string) wp_remote_retrieve_body($resp), 0, 300);
            $this->log_event('WARN', 'PAYMENT_CALLBACK_TEST_FAIL', [
                'partner_id' => $partner_id,
                'reason' => 'HTTP ' . $code . ($body_preview !== '' ? ' — ' . $body_preview : ''),
                'context' => ['booking_id' => $booking_id, 'http_code' => $code],
            ]);
            wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway-payment-test', 'msg' => 'fail', 'detail' => rawurlencode('HTTP ' . $code . ($body_preview !== '' ? ' — ' . $body_preview : ''))], admin_url('admin.php')));
            exit;
        }

        $this->log_event('INFO', 'PAYMENT_CALLBACK_TEST_OK', [
            'partner_id' => $partner_id,
            'context' => [
                'booking_id' => $booking_id,
                'http_code' => $code,
            ],
        ]);

        $detail = 'HTTP ' . $code;
        wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway-payment-test', 'msg' => 'ok', 'detail' => rawurlencode($detail)], admin_url('admin.php')));
        exit;
    }

    public function apply_partner_discount($amount, $booking = null, $apply_coupons = null) {

        // LatePoint deve restare neutro fuori dal contesto partner.
        // In admin e nelle richieste non originate da una pagina partner, il filtro non fa nulla.
        if (!$this->is_partner_context('pricing')) {
            return $amount;
        }

        $active_partner_id = $this->get_current_partner_id();
        if ($active_partner_id === '') {
            return $amount;
        }

        $result = null;

        $config = $this->get_partner_discount_config($active_partner_id);

        if ($config['pay_on_partner']) {
            // Forza il totale a 0 sul sito principale: il pagamento avviene sul portale del partner.
            // Cattura l'importo originale per includerlo nel webhook (partner_charge).
            if (in_array(current_filter(), ['latepoint_full_amount', 'latepoint_full_amount_for_service'], true)) {
                $this->partner_original_total = max(0.0, (float) $amount);
            }
            $result = 0.0;
        } elseif ($config['amount'] <= 0) {
            $result = $amount;
        } elseif ($config['type'] === 'percent') {
            $discount = (float) $amount * ($config['amount'] / 100.0);
            $result = max(0.0, (float) $amount - $discount);
        } else {
            // Sconto fisso in euro.
            $result = max(0.0, (float) $amount - $config['amount']);
        }


        return $result;
    }

    public function handle_save_routes() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        check_admin_referer('sos_pg_save_routes');

        $partner_ids = $_POST['routes']['partner_id'] ?? [];
        $paths = $_POST['routes']['path'] ?? [];

        $routes = [];
        if (is_array($partner_ids) && is_array($paths)) {
            foreach ($partner_ids as $idx => $pid_raw) {
                $pid = sanitize_text_field(wp_unslash($pid_raw));
                $path = isset($paths[$idx]) ? trim((string) wp_unslash($paths[$idx])) : '';

                if ($pid === '' || $path === '') {
                    continue;
                }

                if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
                    $routes[$pid] = esc_url_raw($path);
                } else {
                    $routes[$pid] = '/' . ltrim($path, '/');
                }
            }
        }

        update_option($this->routes_key, $routes);

        wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway-pages', 'msg' => 'routes_saved'], admin_url('admin.php')));
        exit;
    }

    public function handle_book_now_request() {
        if (!isset($_GET['sos_pg_book_now'])) {
            return;
        }

        if (!$this->is_partner_context('book_now')) {
            status_header(403);
            exit('Contesto partner non valido');
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            status_header(405);
            exit('Metodo non consentito');
        }

        // Verifica nonce prima di qualsiasi elaborazione per prevenire CSRF.
        check_admin_referer('sos_pg_book_now');

        $settings = $this->get_settings();
        $pem = trim((string) ($settings['self_login_private_key_pem'] ?? ''));
        $default_partner_id = sanitize_text_field((string) ($settings['self_login_partner_id'] ?? ''));

        $email = sanitize_email(wp_unslash($_POST['sos_pg_email'] ?? ''));
        $partner_id = sanitize_text_field(wp_unslash($_POST['sos_pg_partner_id'] ?? ''));
        if ($partner_id === '') {
            $partner_id = $default_partner_id;
        }

        if ($partner_id !== '') {
            $partner_pem = $this->partner_registry ? $this->partner_registry->get_partner_private_key($partner_id) : '';
            if ($partner_pem !== '') {
                $pem = $partner_pem;
            }
        }

        // Fallback: se l'utente è già loggato usa la sua email.
        if ($email === '' && is_user_logged_in()) {
            $email = wp_get_current_user()->user_email;
        }

        if ($email === '' || !is_email($email)) {
            wp_safe_redirect(add_query_arg('sos_pg_err', 'email', wp_get_referer() ?: home_url('/')));
            exit;
        }

        if ($partner_id === '') {
            wp_safe_redirect(add_query_arg('sos_pg_err', 'partner', wp_get_referer() ?: home_url('/')));
            exit;
        }

        if ($pem === '') {
            wp_safe_redirect(add_query_arg('sos_pg_err', 'key', wp_get_referer() ?: home_url('/')));
            exit;
        }

        $private_key = openssl_pkey_get_private($pem);
        if (!$private_key) {
            wp_safe_redirect(add_query_arg('sos_pg_err', 'key', wp_get_referer() ?: home_url('/')));
            exit;
        }

        $timestamp = time();
        $nonce = wp_generate_password(12, false, false);
        $message = $partner_id . '|' . $email . '|' . $timestamp . '|' . $nonce;

        $signature_raw = '';
        $ok = openssl_sign($message, $signature_raw, $private_key, OPENSSL_ALGO_SHA256);
        openssl_free_key($private_key);

        if (!$ok) {
            wp_safe_redirect(add_query_arg('sos_pg_err', 'sign', wp_get_referer() ?: home_url('/')));
            exit;
        }

        $signature_b64 = base64_encode($signature_raw);

        // Usa l'URL endpoint configurato; se vuoto usa quello locale (stesso sito).
        $configured_endpoint = (string) ($settings['self_login_endpoint_url'] ?? '');
        $endpoint = $configured_endpoint !== '' ? $configured_endpoint : home_url($this->current_endpoint_path() . '/');
        // Assicura la slash finale.
        $endpoint = rtrim($endpoint, '/') . '/';

        // Generiamo una pagina HTML che fa auto-POST al partner-login (stesso pattern del tester).
        status_header(200);
        echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Prenota...</title></head><body>';
        echo '<form id="sosPgBookNowForm" action="' . esc_url($endpoint) . '" method="POST">';
        echo '<input type="hidden" name="partner_id" value="' . esc_attr($partner_id) . '">';
        echo '<input type="hidden" name="payload" value="' . esc_attr($email) . '">';
        echo '<input type="hidden" name="timestamp" value="' . esc_attr((string) $timestamp) . '">';
        echo '<input type="hidden" name="nonce" value="' . esc_attr($nonce) . '">';
        echo '<input type="hidden" name="signature" value="' . esc_attr($signature_b64) . '">';
        echo '</form>';
        echo '<script>document.getElementById("sosPgBookNowForm").submit();</script>';
        echo '</body></html>';

        exit;
    }

    /**
     * Shortcode [sos_partner_prenota] per inserire un pulsante "Prenota" su qualsiasi pagina WP.
     *
     * Attributi:
     *   partner_id  — ID partner; se omesso usa self_login_partner_id dalle impostazioni
     *   label       — testo pulsante (default: "Prenota")
     *   email_field — "yes" mostra un campo email; "no" usa direttamente l'email dell'utente loggato (default: "yes" se non loggato, "no" se loggato)
     *   class       — classi CSS aggiuntive sul form
     *
     * Esempio: [sos_partner_prenota partner_id="hf" label="Prenota ora"]
     */
    public function shortcode_partner_prenota($atts) {
        $settings = $this->get_settings();
        $default_partner_id = sanitize_text_field((string) ($settings['self_login_partner_id'] ?? ''));

        $atts = shortcode_atts([
            'partner_id'  => $default_partner_id,
            'label'       => 'Prenota',
            'email_field' => is_user_logged_in() ? 'no' : 'yes',
            'class'       => '',
        ], $atts, 'sos_partner_prenota');

        $partner_id = sanitize_text_field((string) $atts['partner_id']);
        $label      = esc_html(sanitize_text_field((string) $atts['label']));
        $show_email = strtolower(trim((string) $atts['email_field'])) === 'yes';
        $extra_class = esc_attr(sanitize_text_field((string) $atts['class']));

        $pem_configured = trim((string) ($settings['self_login_private_key_pem'] ?? '')) !== '';

        if (!$pem_configured) {
            if (current_user_can('manage_options')) {
                return '<p style="color:#d63638;font-size:.85em;">[sos_partner_prenota] — Chiave privata self-use non configurata nelle impostazioni del gateway.</p>';
            }
            return '';
        }

        if ($partner_id === '') {
            if (current_user_can('manage_options')) {
                return '<p style="color:#d63638;font-size:.85em;">[sos_partner_prenota] — Partner ID non specificato.</p>';
            }
            return '';
        }

        $action_url = add_query_arg('sos_pg_book_now', '1', home_url('/'));

        $uid = 'sos_pg_prenota_' . wp_generate_password(6, false, false);

        $err_map = [
            'email'   => 'Inserisci un indirizzo email valido.',
            'partner' => 'Configurazione partner mancante. Contatta l\'amministratore.',
            'key'     => 'Errore di configurazione chiave. Contatta l\'amministratore.',
            'sign'    => 'Errore di firma. Contatta l\'amministratore.',
        ];
        $err_code = sanitize_text_field((string) ($_GET['sos_pg_err'] ?? ''));
        $err_msg = $err_code !== '' ? ($err_map[$err_code] ?? 'Errore imprevisto.') : '';

        ob_start();
        ?>
<form id="<?php echo esc_attr($uid); ?>" class="sos-partner-prenota<?php echo $extra_class !== '' ? ' ' . $extra_class : ''; ?>" method="post" action="<?php echo esc_url($action_url); ?>">
    <?php wp_nonce_field('sos_pg_book_now'); ?>
    <input type="hidden" name="sos_pg_partner_id" value="<?php echo esc_attr($partner_id); ?>">
    <?php if ($show_email): ?>
    <div class="sos-prenota-email-row" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="email" name="sos_pg_email" placeholder="La tua email" required
               style="padding:8px 12px;border:1px solid #ccc;border-radius:4px;font-size:1em;min-width:220px;">
        <button type="submit"
                style="padding:8px 20px;background:#0073aa;color:#fff;border:none;border-radius:4px;font-size:1em;cursor:pointer;"><?php echo $label; ?></button>
    </div>
    <?php else: ?>
        <?php if (is_user_logged_in()): ?>
        <input type="hidden" name="sos_pg_email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>">
        <?php endif; ?>
        <button type="submit"
                style="padding:8px 20px;background:#0073aa;color:#fff;border:none;border-radius:4px;font-size:1em;cursor:pointer;"><?php echo $label; ?></button>
    <?php endif; ?>
    <?php if ($err_msg !== ''): ?>
    <p style="color:#d63638;font-size:.85em;margin-top:4px;"><?php echo esc_html($err_msg); ?></p>
    <?php endif; ?>
</form>
        <?php
        return ob_get_clean();
    }

    public function handle_booking_created($booking, $cart = null) {
        static $sent_booking_ids = [];

        $booking_id_early = (string) $this->safe_get($booking, 'id');
        if ($booking_id_early !== '' && isset($sent_booking_ids[$booking_id_early])) {
            // Deduplicazione: evita l'invio doppio del webhook quando entrambi gli hook
            // LatePoint (latepoint_after_create_booking e latepoint_booking_created) scattano
            // per lo stesso booking nella stessa request.
            return;
        }

        // Recupera location_id subito: serve sia per il payload sia come fallback partner.
        $location_id = $this->safe_get($booking, 'location_id');

        if (!$this->is_partner_context('booking_created', [
            'allow_location_lookup' => true,
            'location_id' => $location_id,
        ])) {
            return;
        }

        $partner_id = $this->get_current_partner_id();

        if ($partner_id === '') {
            // Fallback: individua il partner dal location_id LatePoint configurato nel webhook.
            $partner_id = $this->get_partner_id_by_location($location_id);
        }

        if ($partner_id === '') {
            // Costruisce un hint diagnostico: se location_id è presente ma non configurato in nessun
            // webhook, suggerisce all'amministratore di impostarlo nella pagina "Pagine Partner".
            $hint = '';
            if ((string) $location_id !== '') {
                $configured_locations = array_filter(array_column($this->get_partner_webhooks(), 'location_id'));
                if (empty($configured_locations)) {
                    $hint = 'Nessun location_id configurato nei webhook partner. Imposta location_id=' . $location_id . ' per il partner corretto in "Pagine Partner > Webhook".';
                } else {
                    $hint = 'location_id=' . $location_id . ' non corrisponde a nessun partner configurato. Location_id configurati: ' . implode(', ', $configured_locations) . '.';
                }
            }
            $this->log_event('INFO', 'BOOKING_WEBHOOK_SKIP_NO_PARTNER', [
                'context' => ['booking_id' => $booking_id_early, 'location_id' => $location_id],
                'reason' => $hint ?: null,
            ]);
            return;
        }

        $booking_id = $this->safe_get($booking, 'id');
        $status = $this->safe_get($booking, 'status');
        $service_id = $this->safe_get($booking, 'service_id');
        $agent_id = $this->safe_get($booking, 'agent_id');
        $start_date = $this->safe_get($booking, 'start_date');
        $start_time = $this->safe_get($booking, 'start_time');
        $end_time = $this->safe_get($booking, 'end_time');
        $total = (float) $this->safe_get($booking, 'total', 0);

        // Rileva se il pagamento avviene sul portale partner (pay_on_partner).
        $discount_config  = $this->get_partner_discount_config($partner_id);
        $partner_charge   = null;
        if ($discount_config['pay_on_partner'] && $this->partner_original_total !== null) {
            $partner_charge = $this->partner_original_total;
            // Reset dopo l'uso: il dedup $sent_booking_ids garantisce che il secondo hook per
            // lo stesso booking non raggiunga mai questo punto, quindi il valore è utilizzato
            // una sola volta. Il reset evita il data bleed verso booking successivi.
            $this->partner_original_total = null;
        }
        $customer_email = $this->safe_get_nested($booking, ['customer', 'email']);
        if ($customer_email === '') {
            $customer_email = $this->safe_get($booking, 'customer_email');
        }

        $identity = $this->resolve_customer_identity_fields($cart, get_current_user_id(), [
            'booking' => $booking,
        ]);

        $incoming_customer_phone = (string) ($identity['phone'] ?? '');
        $incoming_customer_first_name = (string) ($identity['first_name'] ?? '');
        $incoming_customer_last_name = (string) ($identity['last_name'] ?? '');
        $incoming_customer_name = trim($incoming_customer_first_name . ' ' . $incoming_customer_last_name);

        $existing_customer_phone = trim((string) $this->safe_get_nested($booking, ['customer', 'phone']));
        if ($existing_customer_phone === '') {
            $existing_customer_phone = trim((string) $this->safe_get($booking, 'customer_phone'));
        }
        if ($existing_customer_phone === '') {
            $existing_customer_phone = trim((string) $this->safe_get($booking, 'phone'));
        }

        $existing_customer_first_name = trim((string) $this->safe_get_nested($booking, ['customer', 'first_name']));
        if ($existing_customer_first_name === '') {
            $existing_customer_first_name = trim((string) $this->safe_get($booking, 'customer_first_name'));
        }
        if ($existing_customer_first_name === '') {
            $existing_customer_first_name = trim((string) $this->safe_get($booking, 'first_name'));
        }

        $existing_customer_last_name = trim((string) $this->safe_get_nested($booking, ['customer', 'last_name']));
        if ($existing_customer_last_name === '') {
            $existing_customer_last_name = trim((string) $this->safe_get($booking, 'customer_last_name'));
        }
        if ($existing_customer_last_name === '') {
            $existing_customer_last_name = trim((string) $this->safe_get($booking, 'last_name'));
        }

        $existing_customer_name = trim((string) $this->safe_get_nested($booking, ['customer', 'full_name']));
        if ($existing_customer_name === '') {
            $existing_customer_name = trim((string) $this->safe_get($booking, 'customer_name'));
        }

        $customer_phone = $this->prefer_non_empty_value($incoming_customer_phone, $existing_customer_phone);
        $customer_first_name = $this->prefer_non_empty_value($incoming_customer_first_name, $existing_customer_first_name);
        $customer_last_name = $this->prefer_non_empty_value($incoming_customer_last_name, $existing_customer_last_name);
        $customer_name = $this->prefer_non_empty_value(trim($customer_first_name . ' ' . $customer_last_name), $existing_customer_name);

        $identity_protected_fields = [
            'first_name' => [$incoming_customer_first_name, $existing_customer_first_name],
            'last_name' => [$incoming_customer_last_name, $existing_customer_last_name],
            'phone' => [$incoming_customer_phone, $existing_customer_phone],
            'customer_name' => [$incoming_customer_name, $existing_customer_name],
        ];
        foreach ($identity_protected_fields as $field => $values) {
            $incoming_value = is_array($values) && array_key_exists(0, $values) ? (string) $values[0] : '';
            $existing_value = is_array($values) && array_key_exists(1, $values) ? (string) $values[1] : '';
            if (trim($incoming_value) === '' && trim($existing_value) !== '') {
                $this->log_event('INFO', 'PARTNER_BOOKING_IDENTITY_KEEP_EXISTING', [
                    'partner_id' => $partner_id,
                    'context' => [
                        'booking_id' => $booking_id,
                        'field' => $field,
                        'incoming_present' => false,
                        'existing_present' => true,
                        'action' => 'kept_existing',
                    ],
                ]);
            }
        }

        $service_name = $this->get_booking_service_name($service_id, $booking);
        $booking_datetime = $this->build_booking_datetime_value($start_date, $start_time);
        $external_reference = $this->resolve_booking_external_reference($partner_id, $booking_id, $booking, $customer_email);
        $amount = $partner_charge !== null ? (float) $partner_charge : $total;

        $this->log_event('INFO', 'PARTNER_BOOKING_IDENTITY_RESOLVED', [
            'partner_id' => $partner_id,
            'context' => [
                'booking_id' => $booking_id,
                'first_name_source' => (string) ($identity['sources']['first_name'] ?? 'missing'),
                'last_name_source' => (string) ($identity['sources']['last_name'] ?? 'missing'),
                'phone_source' => (string) ($identity['sources']['phone'] ?? 'missing'),
                'first_name_present' => $customer_first_name !== '',
                'last_name_present' => $customer_last_name !== '',
                'phone_present' => $customer_phone !== '',
            ],
        ]);

        $this->log_event('INFO', 'BOOKING_PARTNER_HOOK', [
            'partner_id' => $partner_id,
            'context' => [
                'booking_id' => $booking_id,
                'status' => $status,
                'customer_email_present' => $customer_email !== '',
                'first_name_present' => $customer_first_name !== '',
                'last_name_present' => $customer_last_name !== '',
                'phone_present' => $customer_phone !== '',
                'external_reference_present' => $external_reference !== '',
                'amount_present' => ((string) $this->safe_get($booking, 'total')) !== '' || $partner_charge !== null,
                'result' => 'start',
            ],
        ]);

        // Scrivi dati partner nella tabella custom SOS (sorgente unica dalla Fase 3).
        if ($booking_id && $partner_id) {
            $persist_result = $this->upsert_partner_booking_record([
                'lp_booking_id'  => $booking_id,
                'partner_id'     => $partner_id,
                'location_id'    => $location_id,
                'payment_external_ref' => $external_reference,
                'partner_charge' => $partner_charge,
            ]);

            $this->log_event($persist_result ? 'INFO' : 'WARN', 'PARTNER_BOOKING_RECORD_PERSIST', [
                'partner_id' => $partner_id,
                'context' => [
                    'booking_id' => $booking_id,
                    'customer_email_present' => $customer_email !== '',
                    'first_name_present' => $customer_first_name !== '',
                    'last_name_present' => $customer_last_name !== '',
                    'phone_present' => $customer_phone !== '',
                    'external_reference_present' => $external_reference !== '',
                    'amount_present' => ((string) $this->safe_get($booking, 'total')) !== '' || $partner_charge !== null,
                    'result' => $persist_result ? 'success' : 'failed',
                ],
            ]);
        }

        // Webhook per-partner con payload minimo utile al pagamento.
        $partner_payload = [
            'event' => 'booking_created',
            'partner_id' => $partner_id,
            'booking_id' => $booking_id,
            'email' => $customer_email,
            'service' => $service_name !== '' ? $service_name : (string) $service_id,
            'datetime' => $booking_datetime,
            'amount' => $amount,
            'status' => $status,
            'total' => $total,
            'service_id' => $service_id,
            'service_name' => $service_name,
            'location_id' => $location_id,
            'start_date' => $start_date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'customer_email' => $customer_email,
            'customer_phone' => $customer_phone,
            'customer_name' => $customer_name,
            'customer_first_name' => $customer_first_name,
            'customer_last_name' => $customer_last_name,
            'external_reference' => $external_reference,
        ];

        // Se il pagamento è sul portale del partner, includi l'importo da incassare.
        // total = 0 (il sito principale non incassa); partner_charge = importo originale del servizio.
        if ($partner_charge !== null) {
            $partner_payload['partner_charge'] = $partner_charge;
            $partner_payload['pay_on_partner'] = true;
        }

        if ($booking_id_early !== '') {
            $sent_booking_ids[$booking_id_early] = true;
        }
        $this->send_partner_webhook($partner_id, $partner_payload);

    }

    private function send_partner_webhook($partner_id, array $payload) {
        if (!$partner_id) {
            return;
        }

        $webhook = $this->get_partner_webhook_config($partner_id);
        if (empty($webhook['url'])) {
            $this->log_event('INFO', 'WEBHOOK_PARTNER_SKIP_NO_URL', [
                'partner_id' => $partner_id,
                'context' => ['booking_id' => $payload['booking_id'] ?? null],
            ]);
            $this->log_event('ERROR', 'WEBHOOK_BOOKING_ERROR', [
                'partner_id' => $partner_id,
                'reason' => 'missing_webhook_url',
                'context' => [
                    'booking_id' => $payload['booking_id'] ?? null,
                ],
            ]);
            return;
        }

        $url = $webhook['url'];
        $secret = $webhook['secret'] ?? '';

        $body = wp_json_encode($payload);
        $headers = [
            'Content-Type' => 'application/json',
            'X-SOSPG-Event' => 'booking_created',
            'X-SOSPG-Partner-ID' => (string) $partner_id,
        ];
        if ($secret !== '') {
            $headers['X-SOSPG-Signature'] = hash_hmac('sha256', (string) $body, (string) $secret);
        }

        $this->log_event('INFO', 'WEBHOOK_BOOKING_SENT', [
            'partner_id' => $partner_id,
            'context' => [
                'booking_id' => $payload['booking_id'] ?? null,
                'url' => $url,
                'config_source' => (string) ($webhook['source'] ?? ''),
                'customer_email_present' => !empty($payload['customer_email']) || !empty($payload['email']),
                'first_name_present' => !empty($payload['customer_first_name']),
                'last_name_present' => !empty($payload['customer_last_name']),
                'phone_present' => !empty($payload['customer_phone']),
                'external_reference_present' => !empty($payload['external_reference']),
                'amount_present' => array_key_exists('amount', $payload),
                'result' => 'prepared',
            ],
        ]);

        $resp = wp_remote_post($url, [
            'headers' => $headers,
            'body' => $body,
            'timeout' => 10,
        ]);

        if (is_wp_error($resp)) {
            $this->log_event('ERROR', 'WEBHOOK_PARTNER_FAIL', [
                'partner_id' => $partner_id,
                'reason' => $resp->get_error_message(),
                'context' => [
                    'booking_id' => $payload['booking_id'] ?? null,
                    'customer_email_present' => !empty($payload['customer_email']) || !empty($payload['email']),
                    'first_name_present' => !empty($payload['customer_first_name']),
                    'last_name_present' => !empty($payload['customer_last_name']),
                    'phone_present' => !empty($payload['customer_phone']),
                    'external_reference_present' => !empty($payload['external_reference']),
                    'amount_present' => array_key_exists('amount', $payload),
                    'result' => 'request_error',
                ],
            ]);
            $this->log_event('ERROR', 'WEBHOOK_BOOKING_ERROR', [
                'partner_id' => $partner_id,
                'reason' => $resp->get_error_message(),
                'context' => [
                    'booking_id' => $payload['booking_id'] ?? null,
                    'url' => $url,
                ],
            ]);
            return;
        }

        $http_code = wp_remote_retrieve_response_code($resp);
        $response_body = substr((string) wp_remote_retrieve_body($resp), 0, 1000);

        $this->log_event('INFO', 'WEBHOOK_BOOKING_RESPONSE', [
            'partner_id' => $partner_id,
            'context' => [
                'booking_id' => $payload['booking_id'] ?? null,
                'url' => $url,
                'http_code' => $http_code,
                'response_body' => $response_body,
            ],
        ]);

        if ($http_code < 200 || $http_code >= 300) {
            $this->log_event('WARN', 'WEBHOOK_PARTNER_FAIL', [
                'partner_id' => $partner_id,
                'reason' => 'HTTP ' . $http_code,
                'context' => [
                    'booking_id'    => $payload['booking_id'] ?? null,
                    'response_body' => substr((string) wp_remote_retrieve_body($resp), 0, 500),
                ],
            ]);
            $this->log_event('ERROR', 'WEBHOOK_BOOKING_ERROR', [
                'partner_id' => $partner_id,
                'reason' => 'HTTP ' . $http_code,
                'context' => [
                    'booking_id' => $payload['booking_id'] ?? null,
                    'url' => $url,
                    'response_body' => $response_body,
                ],
            ]);
            return;
        }
        $this->log_event('INFO', 'WEBHOOK_PARTNER_SENT', [
            'partner_id' => $partner_id,
            'context' => ['http_code' => $http_code, 'booking_id' => $payload['booking_id'] ?? null],
        ]);
    }

    public function handle_payment_callback() {

        $request_path = $this->current_request_path();
        $cb_path = $this->current_payment_callback_path();

        if ($request_path !== $cb_path && $request_path !== $cb_path . '/') {
            return;
        }

        if (!$this->is_partner_context('payment_callback')) {
            status_header(403);
            exit('Contesto partner non valido');
        }

        $settings = $this->get_settings();

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            status_header(400);
            exit('Payload non valido');
        }

        $booking_id = absint($data['booking_id'] ?? 0);
        $transaction_id = sanitize_text_field($data['transaction_id'] ?? '');
        $partner_id = sanitize_text_field($data['partner_id'] ?? '');
        $amount_paid = isset($data['amount_paid']) ? (float) $data['amount_paid'] : null;
        $currency = sanitize_text_field($data['currency'] ?? '');
        $payment_provider = sanitize_text_field($data['payment_provider'] ?? '');
        $external_reference = sanitize_text_field($data['external_reference'] ?? '');
        $email = sanitize_email((string) ($data['email'] ?? ''));

        $global_secret = trim((string) ($settings['payment_callback_secret'] ?? ''));
        $partner_secret = '';
        $secret_source = 'none';

        if ($partner_id !== '' && $this->partner_registry) {
            $partner_cfg = $this->partner_registry->get_partner_config($partner_id);
            if (is_array($partner_cfg)) {
                $partner_secret = trim((string) ($partner_cfg['callback_secret'] ?? ''));
            }
        }

        if ($partner_secret !== '') {
            $secret = $partner_secret;
            $secret_source = 'partner';
        } else {
            $secret = $global_secret;
            $secret_source = $global_secret !== '' ? 'global' : 'none';
        }

        $this->log_event('INFO', 'PAYMENT_CALLBACK_SECRET_RESOLVED', [
            'partner_id' => $partner_id,
            'reason' => '',
            'context' => [
                'booking_id' => $booking_id,
                'transaction_id' => $transaction_id,
                'secret_source' => $secret_source,
                'partner_secret_configured' => $partner_secret !== '',
                'global_secret_configured' => $global_secret !== '',
            ],
        ]);

        if ($secret === '') {
            $this->log_event('WARN', 'PAYMENT_CALLBACK_SECRET_MISSING', [
                'partner_id' => $partner_id,
                'reason' => 'missing_callback_secret',
                'context' => [
                    'booking_id' => $booking_id,
                    'transaction_id' => $transaction_id,
                    'secret_source' => $secret_source,
                ],
            ]);
            status_header(403);
            exit('Callback non attivato');
        }

        $this->log_event('INFO', 'PAYMENT_CALLBACK_RECEIVED', [
            'partner_id' => $partner_id,
            'reason' => '',
            'context' => [
                'booking_id' => $booking_id,
                'transaction_id' => $transaction_id,
                'amount_paid' => $amount_paid,
                'currency' => $currency,
                'payment_provider' => $payment_provider,
                'external_reference' => $external_reference,
            ],
        ]);

        $sig = $_SERVER['HTTP_X_SOSPG_SIGNATURE'] ?? '';
        $calc = hash_hmac('sha256', (string) $raw, (string) $secret);
        $is_signature_valid = is_string($sig) && $sig !== '' && hash_equals($calc, $sig);
        if (!$is_signature_valid) {
            $invalid_hmac_payload = [
                'reason' => 'invalid_hmac',
                'context' => [
                    'secret_source' => $secret_source,
                    'validation_result' => 'failed',
                ],
            ];
            if ($partner_id !== '') {
                $invalid_hmac_payload['partner_id'] = $partner_id;
            }
            if ($booking_id > 0) {
                $invalid_hmac_payload['context']['booking_id'] = $booking_id;
            }
            if ($transaction_id !== '') {
                $invalid_hmac_payload['context']['transaction_id'] = $transaction_id;
            }
            $this->log_event('WARN', 'PAYMENT_CALLBACK_INVALID_HMAC', $invalid_hmac_payload);
            status_header(401);
            exit('Firma non valida');
        }

        $this->log_event('INFO', 'PAYMENT_CALLBACK_HMAC_VALID', [
            'partner_id' => $partner_id,
            'reason' => '',
            'context' => [
                'booking_id' => $booking_id,
                'transaction_id' => $transaction_id,
                'secret_source' => $secret_source,
                'validation_result' => 'passed',
            ],
        ]);

        if (!$booking_id) {
            status_header(400);
            exit('Dati mancanti');
        }

        if ($partner_id === '') {
            $this->log_event('WARN', 'PAYMENT_CALLBACK_MISSING_PARTNER', [
                'reason' => 'missing_partner_id',
                'context' => [
                    'booking_id' => $booking_id,
                    'transaction_id' => $transaction_id,
                ],
            ]);
            status_header(400);
            exit('Dati mancanti');
        }

        if ($transaction_id === '') {
            $this->log_event('WARN', 'PAYMENT_CALLBACK_MISSING_TX', [
                'partner_id' => $partner_id,
                'context' => ['booking_id' => $booking_id],
            ]);
            status_header(400);
            exit('Dati mancanti');
        }

        global $wpdb;
        // ── 1. Verify the booking row exists; capture current payment_status for later checks ──
        $booking_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, payment_status FROM {$this->booking_table} WHERE id = %d LIMIT 1",
                $booking_id
            )
        );
        if (!$booking_row) {
            $this->log_event('WARN', 'PAYMENT_CALLBACK_BOOKING_NOT_FOUND', [
                'partner_id' => $partner_id,
                'reason' => 'booking_not_found',
                'context' => [
                    'booking_id' => $booking_id,
                    'transaction_id' => $transaction_id,
                ],
            ]);
            status_header(404);
            exit('Prenotazione non trovata');
        }

        // ── 2. Verify partner_id against the record stored during booking_created ──
        $sos_record = $this->get_partner_booking_record($booking_id);
        if ($sos_record !== null) {
            $booking_partner_id = (string) $sos_record->partner_id;
        } else {
            // Fallback compat: prenotazioni create prima della Fase 1 (tabella custom assente).
            $booking_partner_id = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$this->booking_meta_table} WHERE object_id = %d AND meta_key = %s LIMIT 1",
                    $booking_id,
                    'partner_id'
                )
            );
        }
        if ($booking_partner_id === '' || $booking_partner_id !== $partner_id) {
            $this->log_event('WARN', 'PAYMENT_CALLBACK_PARTNER_MISMATCH', [
                'partner_id' => $partner_id,
                'reason' => 'partner_mismatch',
                'context' => [
                    'booking_id' => $booking_id,
                    'booking_partner_id' => $booking_partner_id,
                    'transaction_id' => $transaction_id,
                ],
            ]);
            status_header(403);
            exit('Partner mismatch per booking');
        }

        // ── 3. Cross-check partner_location_id against the webhook registry ──
        // If the booking carries a location_id and that location maps to a *different*
        // partner in the webhook config, the incoming partner_id is inconsistent.
        if ($sos_record !== null) {
            $booking_location_id = (string) $sos_record->location_id;
        } else {
            $booking_location_id = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$this->booking_meta_table} WHERE object_id = %d AND meta_key = %s LIMIT 1",
                    $booking_id,
                    'partner_location_id'
                )
            );
        }
        if ($booking_location_id !== '') {
            $location_partner_id = $this->get_partner_id_by_location($booking_location_id);
            if ($location_partner_id !== '' && $location_partner_id !== $partner_id && !$this->location_allows_multiple_partners($booking_location_id)) {
                $this->log_event('WARN', 'PAYMENT_CALLBACK_LOCATION_MISMATCH', [
                    'partner_id' => $partner_id,
                    'reason' => 'location_partner_mismatch',
                    'context' => [
                        'booking_id' => $booking_id,
                        'booking_location_id' => $booking_location_id,
                        'location_maps_to_partner' => $location_partner_id,
                        'transaction_id' => $transaction_id,
                    ],
                ]);
                status_header(403);
                exit('Location mismatch per booking');
            }
        }

        // ── 4. Dedup: same transaction_id already processed → idempotent 409 ──
        if ($sos_record !== null) {
            $existing_transaction_id = (string) $sos_record->payment_transaction_id;
        } else {
            $existing_transaction_id = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$this->booking_meta_table} WHERE object_id = %d AND meta_key = %s LIMIT 1",
                    $booking_id,
                    'payment_transaction_id'
                )
            );
        }
        if ($existing_transaction_id !== '' && hash_equals($existing_transaction_id, $transaction_id)) {
            $this->log_event('WARN', 'PAYMENT_CALLBACK_DUPLICATE_TX', [
                'partner_id' => $partner_id,
                'reason' => 'duplicate_transaction_id',
                'context' => [
                    'booking_id' => $booking_id,
                    'transaction_id' => $transaction_id,
                ],
            ]);
            status_header(409);
            exit('Transaction già processata');
        }

        // ── 5. Booking already paid with a *different* transaction → reject ──
        // The dedup above handles exact-same-tx replays. This catches a second attempt
        // on a booking that is already closed with a different transaction_id.
        if ((string) $booking_row->payment_status === 'paid' && $existing_transaction_id !== '') {
            $this->log_event('WARN', 'PAYMENT_CALLBACK_ALREADY_PAID', [
                'partner_id' => $partner_id,
                'reason' => 'booking_already_paid',
                'context' => [
                    'booking_id' => $booking_id,
                    'existing_transaction_id' => $existing_transaction_id,
                    'incoming_transaction_id' => $transaction_id,
                ],
            ]);
            status_header(409);
            exit('Prenotazione già pagata con transazione diversa');
        }

        // ── 6. external_reference conflict: same booking, stored ref differs ──
        // Protects against a partner sending a new callback with a different payment
        // reference for a booking that already has one registered.
        if ($sos_record !== null) {
            $stored_ext_ref = (string) $sos_record->payment_external_ref;
        } else {
            $stored_ext_ref = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$this->booking_meta_table} WHERE object_id = %d AND meta_key = %s LIMIT 1",
                    $booking_id,
                    'payment_external_reference'
                )
            );
        }
        if ($stored_ext_ref !== '' && $external_reference !== '' && !hash_equals($stored_ext_ref, $external_reference)) {
            $this->log_event('WARN', 'PAYMENT_CALLBACK_EXT_REF_MISMATCH', [
                'partner_id' => $partner_id,
                'reason' => 'external_reference_mismatch',
                'context' => [
                    'booking_id' => $booking_id,
                    'stored_external_reference' => $stored_ext_ref,
                    'incoming_external_reference' => $external_reference,
                    'transaction_id' => $transaction_id,
                ],
            ]);
            status_header(409);
            exit('Conflitto su external_reference');
        }

        // ── 7. Business gate (warning-only): the callback should ideally target bookings
        // explicitly managed economically by the partner. To preserve legacy behavior,
        // this block only logs an inconsistency and never blocks the callback.
        $partner_charge_detected = null;
        if ($sos_record !== null && isset($sos_record->partner_charge) && $sos_record->partner_charge !== null) {
            $partner_charge_detected = (float) $sos_record->partner_charge;
        }

        $pay_on_partner_detected = false;
        $marker_source = 'none';
        $is_partner_managed_booking = false;

        if ($partner_charge_detected !== null && $partner_charge_detected > 0) {
            $is_partner_managed_booking = true;
            $marker_source = 'partner_charge';
        } else {
            $discount_config = $this->get_partner_discount_config($partner_id);
            $pay_on_partner_detected = !empty($discount_config['pay_on_partner']);

            if ($pay_on_partner_detected) {
                $is_partner_managed_booking = true;
                $marker_source = 'pay_on_partner';
            }
        }

        if (!$is_partner_managed_booking) {
            $this->log_event('WARN', 'PAYMENT_CALLBACK_BUSINESS_GATE_WARN', [
                'partner_id' => $partner_id,
                'reason' => 'booking_not_explicitly_partner_managed',
                'context' => [
                    'booking_id' => $booking_id,
                    'transaction_id' => $transaction_id,
                    'partner_charge' => $partner_charge_detected,
                    'pay_on_partner' => $pay_on_partner_detected,
                    'marker_source' => $marker_source,
                ],
            ]);
        }

        // Stato finale sempre gestito da LatePoint: fissiamo pending (o valore da impostazioni) e pagamento "paid".
        $target_status = $settings['payment_success_status'] ?: 'pending';

        $raw_payment_success_status = isset($settings['payment_success_status']) ? sanitize_text_field((string) $settings['payment_success_status']) : '';
        $resolved_target_status = sanitize_text_field((string) $target_status);
        $latepoint_statuses = [];
        $latepoint_status_slugs = [];
        $is_valid_latepoint_target_status = false;

        if (class_exists('OsBookingHelper') && method_exists('OsBookingHelper', 'get_statuses_list')) {
            $latepoint_statuses = (array) OsBookingHelper::get_statuses_list();
            $latepoint_status_slugs = array_keys($latepoint_statuses);
            $is_valid_latepoint_target_status = in_array($resolved_target_status, $latepoint_status_slugs, true);
        }

        $this->log_event('INFO', 'PAYMENT_CALLBACK_TARGET_STATUS_DEBUG', [
            'partner_id' => $partner_id,
            'context' => [
                'booking_id' => $booking_id,
                'transaction_id' => $transaction_id,
                'raw_payment_success_status' => $raw_payment_success_status,
                'resolved_target_status' => $resolved_target_status,
                'latepoint_statuses' => $latepoint_statuses,
                'latepoint_status_slugs' => $latepoint_status_slugs,
                'is_valid_latepoint_target_status' => $is_valid_latepoint_target_status,
            ],
        ]);

        if (!$is_valid_latepoint_target_status) {
            $this->log_event('WARN', 'PAYMENT_CALLBACK_UNKNOWN_TARGET_STATUS', [
                'partner_id' => $partner_id,
                'reason' => 'unknown_target_status_slug',
                'context' => [
                    'booking_id' => $booking_id,
                    'transaction_id' => $transaction_id,
                    'raw_payment_success_status' => $raw_payment_success_status,
                    'resolved_target_status' => $resolved_target_status,
                    'latepoint_status_slugs' => $latepoint_status_slugs,
                ],
            ]);
        }

        $target_payment_status = 'paid';

        if ($target_status === '') {
            status_header(400);
            exit('Stato mancante');
        }

        $this->log_event('INFO', 'PAYMENT_CALLBACK_BOOKING_LOAD_START', [
            'partner_id' => $partner_id,
            'context' => [
                'booking_id' => $booking_id,
                'target_status' => $target_status,
                'transaction_id' => $transaction_id,
            ],
        ]);

        if (!class_exists('OsBookingModel')) {
            status_header(500);
            exit('LatePoint non disponibile');
        }

        $booking_model = new OsBookingModel($booking_id);
        if (empty($booking_model->id)) {
            status_header(404);
            exit('Prenotazione non trovata');
        }

        if ((string) $booking_model->status !== (string) $target_status) {
            $this->log_event('INFO', 'PAYMENT_CALLBACK_UPDATE_STATUS_START', [
                'partner_id' => $partner_id,
                'context' => [
                    'booking_id' => $booking_id,
                    'from_status' => (string) $booking_model->status,
                    'to_status' => (string) $target_status,
                    'transaction_id' => $transaction_id,
                ],
            ]);

            if (!$booking_model->update_status($target_status)) {
                $this->log_event('WARN', 'PAYMENT_CALLBACK_UPDATE_STATUS_FAIL', [
                    'partner_id' => $partner_id,
                    'reason' => 'latepoint_update_status_failed',
                    'context' => [
                        'booking_id' => $booking_id,
                        'from_status' => (string) $booking_model->status,
                        'to_status' => (string) $target_status,
                        'transaction_id' => $transaction_id,
                    ],
                ]);
                status_header(500);
                exit('Impossibile aggiornare lo stato prenotazione');
            }

            $this->log_event('INFO', 'PAYMENT_CALLBACK_UPDATE_STATUS_OK', [
                'partner_id' => $partner_id,
                'context' => [
                    'booking_id' => $booking_id,
                    'status' => (string) $target_status,
                    'transaction_id' => $transaction_id,
                ],
            ]);
        } else {
            $this->log_event('INFO', 'PAYMENT_CALLBACK_STATUS_ALREADY_SET', [
                'partner_id' => $partner_id,
                'context' => [
                    'booking_id' => $booking_id,
                    'status' => (string) $target_status,
                ],
            ]);
        }

        $result = $wpdb->update(
            $this->booking_table,
            [
                'payment_status' => $target_payment_status,
            ],
            ['id' => $booking_id],
            ['%s'],
            ['%d']
        );

        if ($result === false) {
            $this->log_event('WARN', 'PAYMENT_CALLBACK_PAYMENT_STATUS_FAIL', [
                'partner_id' => $partner_id,
                'context' => [
                    'booking_id' => $booking_id,
                ],
            ]);
        }
        // Aggiorna dati pagamento nella tabella custom SOS (sorgente unica dalla Fase 3).
        $this->upsert_partner_booking_record([
            'lp_booking_id'          => $booking_id,
            'partner_id'             => $partner_id,
            'payment_transaction_id' => $transaction_id,
            'payment_external_ref'   => $external_reference,
            'payment_status'         => $target_payment_status,
            'confirmed_at'           => current_time('mysql'),
        ]);

        $this->log_event('INFO', 'PAYMENT_CALLBACK_OK', [
            'partner_id' => $partner_id,
            'context' => [
                'booking_id' => $booking_id,
                'status' => $target_status,
                'transaction_id' => $transaction_id,
                'amount_paid' => $amount_paid,
                'currency' => $currency,
                'payment_provider' => $payment_provider,
                'external_reference' => $external_reference,
            ],
        ]);

        $summary_payload = [
            'partner_id' => $partner_id,
            'context' => [
                'booking_id' => $booking_id,
                'transaction_id' => $transaction_id,
                'amount_paid' => $amount_paid,
                'currency' => $currency,
                'payment_provider' => $payment_provider,
                'external_reference' => $external_reference,
                'final_status' => $target_status,
            ],
        ];
        if ($email !== '') {
            $summary_payload['email'] = $email;
        }
        $this->log_event('INFO', 'PARTNER_FLOW_SUMMARY', $summary_payload);


        wp_send_json_success(['ok' => true]);
    }

    private function location_allows_multiple_partners($location_id) {
    $webhooks = $this->get_partner_webhooks();
    $count = 0;
    foreach ($webhooks as $cfg) {
        if (is_array($cfg) && (string)($cfg['location_id'] ?? '') === (string)$location_id) {
            $count++;
        }
    }
    return $count > 1;
    }

    private function safe_get($source, $key, $default = '') {
        if (is_array($source) && isset($source[$key])) {
            return $source[$key];
        }
        if (is_object($source) && isset($source->$key)) {
            return $source->$key;
        }
        return $default;
    }

    private function safe_get_nested($source, array $path, $default = '') {
        $current = $source;
        foreach ($path as $key) {
            if (is_array($current) && isset($current[$key])) {
                $current = $current[$key];
                continue;
            }
            if (is_object($current) && isset($current->$key)) {
                $current = $current->$key;
                continue;
            }
            return $default;
        }
        return $current;
    }
    // -----------------------------------------------------------------------
    // Metodi per la modalita partner: webhook listener + tester login/pagamento
    // -----------------------------------------------------------------------

    /**
     * Hook init: ascolta ?sos_pg_webhook=1 (e il parametro legacy ?sos_pg_tester_webhook=1)
     * e registra il webhook ricevuto.
     * Usato in modalita partner per ricevere le notifiche booking_created dal sito principale.
     */
    public function handle_partner_tester_webhook() {
        if (!isset($_GET['sos_pg_webhook']) && !isset($_GET['sos_pg_tester_webhook'])) {
            return;
        }

        if (!$this->is_partner_context('partner_webhook')) {
            status_header(403);
            exit;
        }

        // Accetta solo richieste POST: una GET (es. browser) non deve sovrascrivere l'ultimo webhook registrato.
        if (strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? 'GET'))) !== 'POST') {
            status_header(405);
            exit;
        }

        $settings = $this->get_settings();
        $secret = (string) ($settings['partner_webhook_secret'] ?? '');
        $raw = (string) file_get_contents('php://input');
        // Normalizza i nomi degli header in lowercase per un confronto affidabile.
        $headers_raw = function_exists('getallheaders') ? getallheaders() : [];
        $headers = array_change_key_case((array) $headers_raw, CASE_LOWER);
        $sig = (string) ($headers['x-sospg-signature'] ?? '');
        $valid = true;

        if ($secret !== '') {
            $calc = hash_hmac('sha256', $raw, $secret);
            $valid = ($sig !== '' && hash_equals($calc, $sig));
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) && $raw !== '') {
            $ct = strtolower($headers['content-type'] ?? '');
            if (strpos($ct, 'application/x-www-form-urlencoded') !== false) {
                $arr = [];
                parse_str($raw, $arr);
                if (is_array($arr) && !empty($arr)) {
                    $payload = $arr;
                }
            }
        }

        $store = [
            'received_at'    => current_time('mysql'),
            'valid_signature' => $valid,
            'headers'        => $headers,
            'body'           => $payload,
            'raw'            => $raw,
        ];
        update_option($this->tester_webhook_key, $store);

        status_header($valid ? 200 : 401);
        wp_send_json(['ok' => $valid]);
    }

    /**
     * Pagina admin "Tester" (solo modalita partner).
     * Mostra: form configurazione, send login test, webhook listener, callback pagamento.
     */
    public function render_tester_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        $settings = $this->get_settings();

        if (isset($_POST['sos_pg_tester_action']) && $_POST['sos_pg_tester_action'] === 'send') {
            check_admin_referer('sos_pg_tester_send');
            $this->tester_render_send_form($settings);
            return;
        }

        if (isset($_POST['sos_pg_tester_action']) && $_POST['sos_pg_tester_action'] === 'pay') {
            check_admin_referer('sos_pg_tester_pay');
            $this->tester_send_payment_callback($settings);
            return;
        }

        echo '<div class="wrap"><h1>SOS Partner Gateway &mdash; Tester (sito partner)</h1>';
        echo '<p>Usa questa pagina per testare il login firmato verso il sito principale e per inviare conferme di pagamento.</p>';

        // --- Riepilogo configurazione corrente ---
        echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:12px 16px;margin-bottom:16px;border-radius:3px;">';
        echo '<strong>Configurazione attiva:</strong> ';
        echo 'Partner ID: <code>' . esc_html($settings['self_login_partner_id'] ?: '—') . '</code> &nbsp;|&nbsp; ';
        echo 'Endpoint: <code>' . esc_html($settings['self_login_endpoint_url'] ?: '—') . '</code> &nbsp;|&nbsp; ';
        echo 'Chiave privata: ' . ($settings['self_login_private_key_pem'] ? '<span style="color:#46b450;">&#10003; configurata</span>' : '<span style="color:#d63638;">&#10007; mancante</span>');
        echo ' &mdash; <a href="' . esc_url(admin_url('admin.php?page=sos-partner-gateway')) . '">Modifica impostazioni</a>';
        echo '</div>';

        // --- Form invio login di test ---
        echo '<h2>Invia login di test</h2>';
        echo '<form method="post">';
        wp_nonce_field('sos_pg_tester_send');
        echo '<input type="hidden" name="sos_pg_tester_action" value="send">';
        submit_button('Invia login firmato al sito principale', 'primary', 'submit', false);
        echo '</form>';

        echo '<hr style="margin:24px 0;">';

        // --- Webhook listener ---
        $listener_url = home_url('/?sos_pg_webhook=1');
        $last = get_option($this->tester_webhook_key, []);
        // Compatibilità retroattiva: se non ci sono dati nel nuovo listener, leggi dal vecchio
        // plugin partner-login-tester (sos_pg_tester_last_webhook) nel caso il sito principale
        // stia ancora usando il vecchio URL ?sos_pg_tester_webhook=1.
        if (empty($last)) {
            $legacy = get_option('sos_pg_tester_last_webhook', []);
            if (!empty($legacy)) {
                // Migra i dati nel nuovo option key in modo che le operazioni successive
                // (es. auto_confirmed) agiscano sull'opzione corretta.
                $last = $legacy;
                update_option($this->tester_webhook_key, $last);
            }
        }
        $last_booking_id = 0;
        if (!empty($last['body']['booking_id'])) {
            $last_booking_id = (int) $last['body']['booking_id'];
        }

        echo '<h2>Listener webhook (booking_created)</h2>';
        echo '<p>Configura nel sito principale questo URL webhook per il tuo partner: <code>' . esc_html($listener_url) . '</code></p>';
        echo '<p><em>Nota: questo listener accetta anche le richieste al vecchio URL <code>' . esc_html(home_url('/?sos_pg_tester_webhook=1')) . '</code> per compatibilit&agrave; con installazioni precedenti.</em></p>';
        echo '<p><strong>Secret webhook:</strong> ' . ($settings['partner_webhook_secret'] ? '<code>configurato</code>' : '<span style="color:#d63638;">mancante &mdash; impostalo nelle Impostazioni</span>') . '</p>';

        echo '<h3>Ultimo webhook ricevuto:</h3>';
        if ($last) {
            $sig_label = !empty($last['valid_signature'])
                ? '<span style="color:#46b450;">&#10003; firma valida</span>'
                : '<span style="color:#d63638;">&#10007; firma non valida o assente</span>';
            echo '<p>' . $sig_label . ' &mdash; ' . esc_html($last['received_at'] ?? '') . '</p>';
            echo '<pre style="max-height:240px;overflow:auto;background:#f6f6f6;padding:8px;border:1px solid #ddd;">' . esc_html(wp_json_encode($last['body'] ?? $last, JSON_PRETTY_PRINT)) . '</pre>';

            if ($last_booking_id) {
                $last_total = (float) ($last['body']['total'] ?? -1);
                $is_free    = $last_total === 0.0;
                if ($is_free) {
                    echo '<div style="margin-top:8px;padding:8px 12px;background:#e8f5e9;border:1px solid #a5d6a7;border-radius:3px;">';
                    echo '<strong>&#10004; Prenotazione gratuita (totale 0 &euro;)</strong> &mdash; <em>Solo in questo Tester</em>: la conferma pagamento viene inviata automaticamente per verificare il flusso.';
                    echo '</div>';
                    if (empty($last['auto_confirmed'])) {
                        echo '<form id="sosPgAutoFreeForm" method="post" style="display:none;">';
                        wp_nonce_field('sos_pg_tester_pay');
                        echo '<input type="hidden" name="sos_pg_tester_action" value="pay">';
                        echo '<input type="hidden" name="pay_booking_id" value="' . esc_attr($last_booking_id) . '">';
                        echo '<input type="hidden" name="pay_partner_id" value="' . esc_attr($last['body']['partner_id'] ?? $settings['self_login_partner_id']) . '">';
                        echo '<input type="hidden" name="pay_tx" value="FREE-' . esc_attr($last_booking_id) . '">';
                        echo '<input type="hidden" name="pay_auto" value="1">';
                        echo '</form>';
                        echo '<script>document.addEventListener("DOMContentLoaded",function(){document.getElementById("sosPgAutoFreeForm").submit();});</script>';
                    } else {
                        echo '<div style="margin-top:4px;padding:4px 12px;background:#f1f8e9;border:1px solid #c5e1a5;border-radius:3px;font-size:.85em;">&#10003; Conferma gi&agrave; inviata.</div>';
                    }
                } else {
                    echo '<div style="margin-top:8px;padding:6px 12px;background:#fff3cd;border:1px solid #ffc107;border-radius:3px;font-size:.85em;">';
                    echo '&#9888; <strong>Solo test interno</strong> &mdash; questo pulsante simula la conferma pagamento. ';
                    echo 'In produzione il partner deve inviare la conferma dalla propria piattaforma.';
                    echo '</div>';
                    echo '<form method="post" style="margin-top:6px;">';
                    wp_nonce_field('sos_pg_tester_pay');
                    echo '<input type="hidden" name="sos_pg_tester_action" value="pay">';
                    echo '<input type="hidden" name="pay_booking_id" value="' . esc_attr($last_booking_id) . '">';
                    echo '<input type="hidden" name="pay_partner_id" value="' . esc_attr($last['body']['partner_id'] ?? $settings['self_login_partner_id']) . '">';
                    echo '<input type="hidden" name="pay_tx" value="TEST-' . esc_attr(time()) . '">';
                    echo '<button class="button" type="submit">[TEST] &#10003; Conferma pagamento per prenotazione #' . esc_html($last_booking_id) . '</button>';
                    echo '</form>';
                }
            }
        } else {
            echo '<p>Nessun webhook ricevuto ancora.</p>';
        }

        echo '<hr style="margin:24px 0;">';

        // --- Callback pagamento manuale ---
        echo '<h2>Invia callback pagamento (manuale)</h2>';
        echo '<p>Invia una conferma di pagamento firmata al sito principale. Se hai ricevuto un webhook con prenotazione gratuita la conferma viene inviata automaticamente.</p>';
        echo '<p><strong>URL callback:</strong> ' . ($settings['partner_callback_url'] ? '<code>' . esc_html($settings['partner_callback_url']) . '</code>' : '<span style="color:#d63638;">non configurato &mdash; impostalo nelle Impostazioni</span>') . '</p>';
        echo '<form method="post">';
        wp_nonce_field('sos_pg_tester_pay');
        echo '<input type="hidden" name="sos_pg_tester_action" value="pay">';
        echo '<table class="form-table">';
        echo '<tr><th>Booking ID</th><td><input type="number" name="pay_booking_id" class="regular-text" min="1" value="' . esc_attr($last_booking_id ?: '') . '" required></td></tr>';
        echo '<tr><th>Partner ID</th><td><input type="text" name="pay_partner_id" class="regular-text" value="' . esc_attr($settings['self_login_partner_id']) . '" placeholder="partner_id"></td></tr>';
        echo '<tr><th>Status (opzionale)</th><td><input type="text" name="pay_status" class="regular-text" placeholder="lascia vuoto per usare il default del gateway"></td></tr>';
        echo '<tr><th>Transaction ID</th><td><input type="text" name="pay_tx" class="regular-text" value="TEST-' . esc_attr(time()) . '"></td></tr>';
        echo '<tr><th>Importo (facoltativo)</th><td><input type="number" step="0.01" name="pay_amount" class="regular-text" placeholder="1.00"><p class="description">Facoltativo, per debug.</p></td></tr>';
        echo '</table>';
        submit_button('Invia callback pagamento');
        echo '</form>';

        echo '</div>';
    }

    /**
     * Genera il form auto-submit per il test di login firmato.
     */
    private function tester_render_send_form($settings) {
        $endpoint   = (string) ($settings['self_login_endpoint_url'] ?? '');
        $partner_id = (string) ($settings['self_login_partner_id'] ?? '');
        $pem        = (string) ($settings['self_login_private_key_pem'] ?? '');

        if ($partner_id !== '') {
            $partner_pem = $this->partner_registry ? $this->partner_registry->get_partner_private_key($partner_id) : '';
            if ($partner_pem !== '') {
                $pem = $partner_pem;
            }
        }

        // Usa l'email dell'utente admin loggato come payload di test.
        $email = wp_get_current_user()->user_email;

        if ($endpoint === '' || $partner_id === '' || $pem === '') {
            echo '<div class="notice notice-error"><p>Configura Partner ID, URL endpoint e chiave privata nelle Impostazioni prima di inviare il test.</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=sos-partner-gateway')) . '" class="button">Torna alla configurazione</a></p>';
            return;
        }

        if (!$email || !is_email($email)) {
            echo '<div class="notice notice-error"><p>Impossibile determinare l\'email per il test. Verifica di essere loggato come utente con email valida.</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=sos-partner-gateway-tester')) . '" class="button">Torna al tester</a></p>';
            return;
        }

        $private_key = openssl_pkey_get_private($pem);
        if (!$private_key) {
            echo '<div class="notice notice-error"><p>Chiave privata non valida. Verificala nelle Impostazioni.</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=sos-partner-gateway')) . '" class="button">Torna alla configurazione</a></p>';
            return;
        }

        $timestamp = time();
        $nonce     = wp_generate_password(12, false, false);
        $message   = $partner_id . '|' . $email . '|' . $timestamp . '|' . $nonce;

        $signature = '';
        $ok = openssl_sign($message, $signature, $private_key, OPENSSL_ALGO_SHA256);
        openssl_free_key($private_key);

        if (!$ok) {
            echo '<div class="notice notice-error"><p>Impossibile firmare la richiesta. Controlla il tipo di chiave (deve essere ECC/EC).</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=sos-partner-gateway-tester')) . '" class="button">Torna al tester</a></p>';
            return;
        }

        $signature_b64 = base64_encode($signature);

        echo '<div class="wrap"><h1>Invio login di test</h1>';
        echo '<p>Invio firmato in corso verso: <code>' . esc_html($endpoint) . '</code></p>';
        echo '<form id="sosPgTesterForm" action="' . esc_url($endpoint) . '" method="POST">';
        echo '<input type="hidden" name="partner_id" value="' . esc_attr($partner_id) . '">';
        echo '<input type="hidden" name="payload" value="' . esc_attr($email) . '">';
        echo '<input type="hidden" name="timestamp" value="' . esc_attr((string) $timestamp) . '">';
        echo '<input type="hidden" name="nonce" value="' . esc_attr($nonce) . '">';
        echo '<input type="hidden" name="signature" value="' . esc_attr($signature_b64) . '">';
        echo '</form>';
        echo '<script>document.getElementById("sosPgTesterForm").submit();</script>';
        echo '</div>';
    }

    /**
     * Invia una callback di conferma pagamento al sito principale.
     */
    private function tester_send_payment_callback($settings) {
        $booking_id = absint($_POST['pay_booking_id'] ?? 0);
        $partner_id = sanitize_text_field($_POST['pay_partner_id'] ?? '');
        $status     = sanitize_text_field($_POST['pay_status'] ?? '');
        $tx         = sanitize_text_field($_POST['pay_tx'] ?? '');
        $amount     = sanitize_text_field($_POST['pay_amount'] ?? '');
        $is_auto    = !empty($_POST['pay_auto']);

        $back_url    = esc_url(admin_url('admin.php?page=sos-partner-gateway-tester'));
        $back_url_js = esc_js(admin_url('admin.php?page=sos-partner-gateway-tester'));

        if (!$booking_id) {
            echo '<div class="notice notice-error"><p>Booking ID mancante.</p></div>';
            echo '<p><a href="' . $back_url . '" class="button">Torna</a></p>';
            echo '<script>setTimeout(function(){window.location.replace("' . $back_url_js . '");},2000);</script>';
            return;
        }

        $url    = (string) ($settings['partner_callback_url'] ?? '');
        $secret = (string) ($settings['partner_callback_secret'] ?? '');

        if ($url === '' || $secret === '') {
            echo '<div class="notice notice-error"><p>Configura URL e secret callback pagamento nelle Impostazioni partner.</p></div>';
            echo '<p><a href="' . $back_url . '" class="button">Torna</a></p>';
            echo '<script>setTimeout(function(){window.location.replace("' . $back_url_js . '");},2000);</script>';
            return;
        }

        $payload = ['booking_id' => $booking_id];
        if ($partner_id !== '') {
            $payload['partner_id'] = $partner_id;
        }
        if ($status !== '') {
            $payload['status'] = $status;
        }
        if ($tx !== '') {
            $payload['transaction_id'] = $tx;
        }
        if ($amount !== '') {
            $payload['amount'] = $amount;
        }

        $body = wp_json_encode($payload);
        $headers = [
            'Content-Type'       => 'application/json',
            'X-SOSPG-Signature'  => hash_hmac('sha256', (string) $body, $secret),
        ];

        $resp = wp_remote_post($url, [
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 10,
        ]);

        if (is_wp_error($resp)) {
            echo '<div class="notice notice-error"><p>Errore: ' . esc_html($resp->get_error_message()) . '</p></div>';
            echo '<p><a href="' . $back_url . '" class="button">Torna</a></p>';
            echo '<script>setTimeout(function(){window.location.replace("' . $back_url_js . '");},2000);</script>';
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);

        if ($is_auto || strpos((string) $tx, 'FREE-') === 0) {
            $last = get_option($this->tester_webhook_key, []);
            if (is_array($last)) {
                $last['auto_confirmed'] = true;
                update_option($this->tester_webhook_key, $last);
            }
        }

        if ($code >= 200 && $code < 300) {
            echo '<div class="notice notice-success"><p>Callback inviata. HTTP ' . esc_html($code) . '.</p></div>';
        } else {
            $body_preview = substr((string) wp_remote_retrieve_body($resp), 0, 300);
            echo '<div class="notice notice-error"><p>Callback fallita. HTTP ' . esc_html($code) . ($body_preview !== '' ? ' &mdash; ' . esc_html($body_preview) : '') . '.</p></div>';
            echo '<p>Verifica che <strong>Secret callback pagamento</strong> nel sito partner corrisponda al <strong>Secret callback</strong> del sito principale.</p>';
        }
        echo '<p><a href="' . $back_url . '" class="button">Torna</a></p>';
        echo '<script>setTimeout(function(){window.location.replace("' . $back_url_js . '");},3000);</script>';
    }

    /**
     * Pagina admin per testare la validazione token dell'embedded booking.
     * Consente di normalizzare il payload e verificare la firma JWT RS256 in base alla configurazione del partner.
     */
    public function render_embedded_booking_tester_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        $registry = $this->partner_registry;
        $embedded = $this->embedded_booking;
        $partners = $registry ? $registry->get_partner_configs() : [];

        $embedded_partners = [];
        foreach ($partners as $pid => $cfg) {
            if (($cfg['type'] ?? '') === 'embedded_booking') {
                $embedded_partners[$pid] = $cfg;
            }
        }

        $selected_partner = sanitize_text_field((string) ($_REQUEST['partner_id'] ?? ''));
        if ($selected_partner === '' && !empty($embedded_partners)) {
            $keys = array_keys($embedded_partners);
            $selected_partner = reset($keys);
        }

        $input = [
            'validation_token' => '',
            'validation_token_type' => '',
            'external_reference' => '',
            'email' => '',
            'name' => '',
            'phone' => '',
        ];
        $normalized = null;
        $verification = null;
        $notice = '';

        if (isset($_POST['sos_pg_embedded_action']) && $_POST['sos_pg_embedded_action'] === 'verify') {
            check_admin_referer('sos_pg_embedded_verify');

            $selected_partner = sanitize_text_field((string) ($_POST['partner_id'] ?? ''));
            $input['validation_token'] = (string) wp_unslash($_POST['validation_token'] ?? '');
            $input['validation_token_type'] = sanitize_text_field(wp_unslash($_POST['validation_token_type'] ?? ''));
            $input['external_reference'] = sanitize_text_field(wp_unslash($_POST['external_reference'] ?? ''));
            $input['email'] = sanitize_email(wp_unslash($_POST['email'] ?? ''));
            $input['name'] = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $input['phone'] = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));

            if ($selected_partner === '') {
                $notice = 'Seleziona un partner configurato per embedded booking.';
            } else {
                $cfg = $registry ? $registry->get_embedded_booking_partner($selected_partner) : null;
                if (!$cfg || empty($cfg['enabled'])) {
                    $notice = 'Partner non trovato o disabilitato.';
                } elseif (!$embedded) {
                    $notice = 'Servizio embedded booking non disponibile.';
                } else {
                    $normalized = $embedded->normalize_token_payload($selected_partner, $input);
                    $verification = $embedded->verify_normalized_token($selected_partner, $normalized);
                }
            }
        }

        echo '<div class="wrap">';
        echo '<h1>SOS Partner Gateway — Tester embedded booking</h1>';

        if ($notice !== '') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($notice) . '</p></div>';
        }

        echo '<p>Usa questa pagina per verificare i token di validazione inviati dal portale partner verso l\'embed booking. Le strategie e le chiavi pubbliche sono prese dalla configurazione partner (tipo <code>embedded_booking</code>).</p>';

        if (empty($embedded_partners)) {
            echo '<div class="notice notice-warning"><p>Nessun partner configurato con tipo <strong>embedded_booking</strong>. Aggiungine uno in <em>Partner multipli (beta)</em>.</p></div>';
            echo '</div>';
            return;
        }

        $rest_base = rest_url('sos-pg/v1/embedded-booking/verify/');
        echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:12px 16px;margin:12px 0;border-radius:3px;">';
        echo '<strong>Endpoint REST di verifica:</strong> <code>' . esc_html($rest_base . '{partner_id}') . '</code><br>';
        echo 'Permessi richiesti: amministratore (stesso controllo di questa pagina). Invia i parametri come query string o body form/JSON.';
        echo '</div>';

        echo '<h2>Partner configurati</h2>';
        echo '<table class="widefat striped" style="max-width:680px;">';
        echo '<thead><tr><th>Partner ID</th><th>Strategia token</th><th>External ref mapping</th><th>Stato</th></tr></thead><tbody>';
        foreach ($embedded_partners as $pid => $cfg) {
            $strategy = $cfg['validation_token_strategy'] ?? '';
            $status_label = !empty($cfg['enabled']) ? '<span style="color:#46b450;">abilitato</span>' : '<span style="color:#d63638;">disabilitato</span>';
            echo '<tr>';
            echo '<td>' . esc_html($pid) . '</td>';
            echo '<td>' . esc_html($strategy ?: '—') . '</td>';
            echo '<td>' . esc_html($cfg['external_ref_mapping'] ?? '') . '</td>';
            echo '<td>' . $status_label . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h2 style="margin-top:20px;">Verifica token</h2>';
        echo '<form method="post" style="max-width:780px;">';
        wp_nonce_field('sos_pg_embedded_verify');
        echo '<input type="hidden" name="sos_pg_embedded_action" value="verify">';

        echo '<table class="form-table">';
        echo '<tr><th>Partner ID</th><td><select name="partner_id">';
        foreach ($embedded_partners as $pid => $cfg) {
            $sel = selected($pid, $selected_partner, false);
            echo '<option value="' . esc_attr($pid) . '" ' . $sel . '>' . esc_html($pid) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th>validation_token</th><td><textarea name="validation_token" class="large-text code" rows="4" placeholder="JWT RS256 o token custom">' . esc_textarea($input['validation_token']) . '</textarea></td></tr>';
        echo '<tr><th>validation_token_type</th><td><input type="text" name="validation_token_type" class="regular-text" value="' . esc_attr($input['validation_token_type']) . '" placeholder="es. bearer"></td></tr>';
        echo '<tr><th>external_reference</th><td><input type="text" name="external_reference" class="regular-text" value="' . esc_attr($input['external_reference']) . '" placeholder="es. booking_id esterno"></td></tr>';
        echo '<tr><th>Email / Name / Phone</th><td>';
        echo '<input type="email" name="email" class="regular-text" value="' . esc_attr($input['email']) . '" placeholder="utente@example.com" style="margin-right:8px;">';
        echo '<input type="text" name="name" class="regular-text" value="' . esc_attr($input['name']) . '" placeholder="Nome" style="margin-right:8px;">';
        echo '<input type="text" name="phone" class="regular-text" value="' . esc_attr($input['phone']) . '" placeholder="Telefono">';
        echo '</td></tr>';
        echo '</table>';

        submit_button('Verifica token embedded');
        echo '</form>';

        if ($normalized !== null && $verification !== null) {
            echo '<h2>Risultato</h2>';
            echo '<div style="margin-top:8px;display:flex;gap:12px;flex-wrap:wrap;">';
            echo '<div style="flex:1 1 320px;background:#fff;border:1px solid #ccd0d4;padding:12px;border-radius:3px;">';
            echo '<strong>Payload normalizzato</strong>';
            echo '<pre style="white-space:pre-wrap;word-break:break-word;max-height:320px;overflow:auto;">' . esc_html(wp_json_encode($normalized, JSON_PRETTY_PRINT)) . '</pre>';
            echo '</div>';
            echo '<div style="flex:1 1 320px;background:#fff;border:1px solid #ccd0d4;padding:12px;border-radius:3px;">';
            echo '<strong>Verifica</strong>';
            echo '<pre style="white-space:pre-wrap;word-break:break-word;max-height:320px;overflow:auto;">' . esc_html(wp_json_encode($verification, JSON_PRETTY_PRINT)) . '</pre>';
            echo '</div>';
            echo '</div>';
        }

        echo '</div>';
    }


}