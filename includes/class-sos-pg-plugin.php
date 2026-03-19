<?php
if (!defined('ABSPATH')) exit;

class SOS_PG_Plugin {
    private static $instance = null;
    private $table_logs = '';
    private $booking_table = '';
    private $booking_meta_table = '';
    private $settings_key = 'sos_pg_settings';
    private $routes_key = 'sos_pg_partner_routes';
    private $discounts_key = 'sos_pg_partner_discounts';
    private $webhooks_key = 'sos_pg_partner_webhooks';
    private $tester_webhook_key = 'sos_pg_main_last_webhook';
    private $partner_original_total = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_logs = $wpdb->prefix . SOS_PG_TABLE_LOGS;
        $this->booking_table = $wpdb->prefix . 'latepoint_bookings';
        $this->booking_meta_table = $wpdb->prefix . 'latepoint_booking_meta';

        register_activation_hook(SOS_PG_FILE, [$this, 'activate']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_notices', [$this, 'admin_notice_missing_key']);
        add_action('admin_post_sos_pg_save_settings', [$this, 'handle_save_settings']);

        $partner_mode = $this->is_partner_mode();

        if ($partner_mode) {
            // Modalità partner: solo funzionalità lato partner attive.
            add_action('init', [$this, 'handle_book_now_request'], 1);
            add_action('init', [$this, 'handle_partner_tester_webhook'], 1);
            add_shortcode('sos_partner_prenota', [$this, 'shortcode_partner_prenota']);
        } else {
            // Modalità sito principale: funzionalità gateway complete.
            add_action('add_meta_boxes', [$this, 'register_partner_page_metabox']);
            add_action('save_post_page', [$this, 'save_partner_page_meta'], 10, 2);

            add_action('init', [$this, 'handle_partner_login'], 1);
            add_action('init', [$this, 'handle_book_now_request'], 1);
            add_action('template_redirect', [$this, 'protect_partner_pages'], 1);
            add_action('init', [$this, 'handle_payment_callback'], 1);

            // Shortcode [sos_partner_prenota] per embed booking button su portali propri.
            add_shortcode('sos_partner_prenota', [$this, 'shortcode_partner_prenota']);

            add_action('admin_post_sos_pg_unlock_ip', [$this, 'handle_unlock_ip']);
            add_action('admin_post_sos_pg_clear_logs', [$this, 'handle_clear_logs']);
            add_action('admin_post_sos_pg_save_routes', [$this, 'handle_save_routes']);
            add_action('admin_post_sos_pg_save_discounts', [$this, 'handle_save_discounts']);
            add_action('admin_post_sos_pg_save_webhooks', [$this, 'handle_save_webhooks']);
            add_action('admin_post_sos_pg_send_payment_test', [$this, 'handle_send_payment_test']);

            // LatePoint sconto partner.
            add_filter('latepoint_full_amount', [$this, 'apply_partner_discount'], 20, 3);
            add_filter('latepoint_full_amount_for_service', [$this, 'apply_partner_discount'], 20, 3);
            add_filter('latepoint_deposit_amount', [$this, 'apply_partner_discount'], 20, 3);
            add_filter('latepoint_deposit_amount_for_service', [$this, 'apply_partner_discount'], 20, 3);

            // LatePoint booking lifecycle.
            add_action('latepoint_after_create_booking', [$this, 'handle_booking_created'], 20, 2);
            add_action('latepoint_booking_created', [$this, 'handle_booking_created'], 20, 2);
        }
    }

    public function activate() {
        global $wpdb;
        $table = $this->table_logs;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
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

        dbDelta($sql);

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
        echo 'Il login partner non funzioner&agrave; finch&eacute; non viene impostata una chiave in ';
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

        return wp_parse_args($settings, $defaults);
    }

    private function is_partner_mode() {
        return $this->get_settings()['site_role'] === 'partner';
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
        return is_array($map) ? $map : [];
    }

    private function get_partner_webhooks() {
        $map = get_option($this->webhooks_key, []);
        return is_array($map) ? $map : [];
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
        if (!isset($map[$partner_id])) {
            return $defaults;
        }

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

    private function set_booking_meta($booking_id, $key, $value) {
        if (!$booking_id || !$key) {
            return;
        }

        global $wpdb;
        $now = current_time('mysql');
        $wpdb->replace(
            $this->booking_meta_table,
            [
                'object_id' => (int) $booking_id,
                'meta_key' => (string) $key,
                'meta_value' => (string) $value,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
    }

    private function get_current_partner_id($user_id = 0) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            // Fallback su cookie se l’utente non risulta autenticato (es. sessione persa nel frontend LatePoint).
            $cookie_pid = sanitize_text_field((string) ($_COOKIE['sos_pg_partner_id'] ?? ''));
            return $cookie_pid;
        }

        $partner_id = get_user_meta($user_id, 'partner_id', true);
        return is_string($partner_id) ? trim($partner_id) : '';
    }

    private function current_endpoint_path() {
        $slug = trim((string) $this->get_settings()['endpoint_slug'], '/');
        return '/' . $slug;
    }

    private function current_payment_callback_path() {
        $slug = trim((string) $this->get_settings()['payment_callback_slug'], '/');
        return '/' . ($slug === '' ? 'partner-payment-callback' : $slug);
    }

    private function current_request_path() {
        return (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    }

    private function get_ip() {
        return sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
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

    private function public_key_resource() {
        $pem = trim((string) ($this->get_settings()['public_key_pem'] ?? ''));
        if ($pem === '') {
            return false;
        }
        return openssl_pkey_get_public($pem);
    }

    private function log_event($level, $event_type, $args = []) {
        $settings = $this->get_settings();

        if ($level === 'DEBUG' && empty($settings['debug_logging_enabled'])) {
            return;
        }

        global $wpdb;
        $wpdb->insert(
            $this->table_logs,
            [
                'created_at' => current_time('mysql'),
                'level' => substr((string) $level, 0, 20),
                'event_type' => substr((string) $event_type, 0, 50),
                'partner_id' => sanitize_text_field((string) ($args['partner_id'] ?? '')),
                'email' => sanitize_email((string) ($args['email'] ?? '')),
                'ip' => sanitize_text_field((string) ($args['ip'] ?? '')),
                'reason' => isset($args['reason']) ? wp_strip_all_tags((string) $args['reason']) : '',
                'user_agent' => isset($args['user_agent']) ? wp_strip_all_tags((string) $args['user_agent']) : '',
                'context' => !empty($args['context']) ? wp_json_encode($args['context']) : '',
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    public function register_partner_page_metabox() {
        add_meta_box(
            'sos-pg-partner-page',
            'SOS Partner Gateway',
            [$this, 'render_partner_page_metabox'],
            'page',
            'normal',
            'high'
        );
    }

    public function render_partner_page_metabox($post) {
        wp_nonce_field('sos_pg_save_partner_page', 'sos_pg_partner_page_nonce');
        $enabled = (int) get_post_meta($post->ID, '_sos_pg_partner_enabled', true);
        ?>
        <p>
            <label>
                <input type="checkbox" name="sos_pg_partner_enabled" value="1" <?php checked($enabled, 1); ?>>
                Proteggi questa pagina come pagina partner
            </label>
        </p>
        <table class="form-table">
            <tr>
                <th>Partner ID</th>
                <td>
                    <input type="text" class="regular-text" name="sos_pg_partner_id" value="<?php echo esc_attr(get_post_meta($post->ID, '_sos_pg_partner_id', true)); ?>" placeholder="fh">
                </td>
            </tr>
            <tr>
                <th>Redirect path</th>
                <td>
                    <input type="text" class="regular-text" name="sos_pg_redirect_path" value="<?php echo esc_attr(get_post_meta($post->ID, '_sos_pg_redirect_path', true)); ?>" placeholder="/prenotazioni-fh/">
                </td>
            </tr>
            <tr>
                <th>Sconto partner (€)</th>
                <td>
                    <input type="number" step="0.01" min="0" name="sos_pg_discount_amount" value="<?php echo esc_attr(get_post_meta($post->ID, '_sos_pg_discount_amount', true)); ?>" placeholder="0.00">
                </td>
            </tr>
            <tr>
                <th>Stato iniziale LatePoint</th>
                <td>
                    <input type="text" class="regular-text" name="sos_pg_initial_status" value="<?php echo esc_attr(get_post_meta($post->ID, '_sos_pg_initial_status', true)); ?>" placeholder="F+H">
                </td>
            </tr>
            <tr>
                <th>Location / Posizione</th>
                <td>
                    <input type="text" class="regular-text" name="sos_pg_location_label" value="<?php echo esc_attr(get_post_meta($post->ID, '_sos_pg_location_label', true)); ?>" placeholder="F+H">
                </td>
            </tr>
        </table>
        <?php
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

        $enabled = (int) get_post_meta($post_id, '_sos_pg_partner_enabled', true);
        if (!$enabled) {
            return;
        }

        $required_partner = (string) get_post_meta($post_id, '_sos_pg_partner_id', true);
        $courtesy_page_id = (int) $this->get_settings()['courtesy_page_id'];

        if (!is_user_logged_in()) {
            wp_safe_redirect($courtesy_page_id ? get_permalink($courtesy_page_id) : home_url('/'));
            exit;
        }

        $user_partner = (string) get_user_meta(get_current_user_id(), 'partner_id', true);

        if ($required_partner && $user_partner !== $required_partner) {
            $this->log_event('WARN', 'PAGE_BLOCKED_PARTNER_MISMATCH', [
                'partner_id' => $required_partner,
                'email' => wp_get_current_user()->user_email,
                'ip' => $this->get_ip(),
                'reason' => 'Utente con partner_id diverso',
            ]);
            wp_die('Accesso non consentito a questa pagina partner.', 'Accesso negato', ['response' => 403]);
        }
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

        if ($request_path !== $endpoint && $request_path !== $endpoint . '/') {
            return;
        }

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
        $signature_b64 = (string) wp_unslash($_POST['signature'] ?? '');
        $signature = base64_decode($signature_b64, true);

        if ($partner_id === '') {
            $this->register_fail('partner_id mancante');
            status_header(400);
            exit('Partner non valido');
        }

        if ($email === '' || !is_email($email)) {
            $this->register_fail('email non valida', $partner_id, $email);
            status_header(400);
            exit('Email non valida');
        }

        if (!$timestamp || abs(time() - $timestamp) > 180) {
            $this->register_fail('timestamp scaduto', $partner_id, $email);
            status_header(403);
            exit('Richiesta scaduta');
        }

        if ($nonce === '') {
            $this->register_fail('nonce mancante', $partner_id, $email);
            status_header(400);
            exit('Nonce mancante');
        }

        if ($signature === false || empty($signature)) {
            $this->register_fail('firma mancante/non valida', $partner_id, $email);
            status_header(400);
            exit('Firma non valida');
        }

        $public_key = $this->public_key_resource();
        if (!$public_key) {
            $this->log_event('ERROR', 'PARTNER_LOGIN_KEY_ERROR', [
                'partner_id' => $partner_id,
                'email' => $email,
                'ip' => $ip,
                'reason' => 'Chiave pubblica non configurata o non valida',
                'user_agent' => $ua,
            ]);
            status_header(500);
            exit('Chiave pubblica non valida');
        }

        $message = $partner_id . '|' . $email . '|' . $timestamp . '|' . $nonce;
        $ok = openssl_verify($message, $signature, $public_key, OPENSSL_ALGO_SHA256);

        if ($ok !== 1) {
            $this->register_fail('firma non valida', $partner_id, $email);
            status_header(403);
            exit('Firma non valida');
        }

        $page = $this->find_partner_page_by_partner_id($partner_id);
        $routes = $this->get_partner_routes();
        $route = $routes[$partner_id] ?? '';

        if ($route) {
            $redirect_url = (strpos($route, 'http://') === 0 || strpos($route, 'https://') === 0)
                ? $route
                : home_url($route);
        } elseif ($page) {
            $redirect_url = $this->get_redirect_url_for_page($page->ID);
        } else {
            $this->register_fail('pagina partner non configurata', $partner_id, $email);
            status_header(404);
            exit('Pagina partner non configurata');
        }

        $user = get_user_by('email', $email);
        $is_new_user = false;

        if (!$user) {
            $user_id = wp_create_user($email, wp_generate_password(20, true, true), $email);

            if (is_wp_error($user_id)) {
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

            $user = get_user_by('id', $user_id);
            $is_new_user = true;
        }

        update_user_meta($user->ID, 'partner_id', $partner_id);
        update_user_meta($user->ID, 'partner_last_login', time());
        update_user_meta($user->ID, 'partner_target_page', $redirect_url);

        wp_clear_auth_cookie();
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        do_action('wp_login', $user->user_login, $user);

        delete_transient($this->fail_short_key($ip));
        delete_transient($this->fail_long_key($ip));
        delete_transient($this->ban_key($ip));

        $this->log_event('INFO', 'PARTNER_LOGIN_OK', [
            'partner_id' => $partner_id,
            'email' => $email,
            'ip' => $ip,
            'reason' => $is_new_user ? 'new_user' : 'existing_user',
            'user_agent' => $ua,
            'context' => [
                'timestamp' => $timestamp,
                'nonce' => $nonce,
                'redirect' => $redirect_url,
            ],
        ]);

        // Cookie di cortesia per frontend LatePoint se la sessione WP viene persa.
        setcookie('sos_pg_partner_id', $partner_id, time() + 4 * HOUR_IN_SECONDS, '/', '', is_ssl(), false);

        wp_safe_redirect($redirect_url);
        exit;
    }

    private function get_logs($limit = 300) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_logs} ORDER BY id DESC LIMIT %d",
                max(1, min(1000, (int) $limit))
            )
        );
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
            'saved' => 'Impostazioni salvate.',
            'unlocked' => 'IP sbloccato correttamente.',
            'ip_missing' => 'IP mancante.',
            'cleared' => 'Log svuotati.',
            'discount_saved' => 'Sconti partner salvati.',
            'routes_saved' => 'Routing partner salvato.',
            'webhooks_saved' => 'Webhook partner salvati.',
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
            add_submenu_page('sos-partner-gateway', 'Test pagamento', 'Test pagamento', 'manage_options', 'sos-partner-gateway-payment-test', [$this, 'render_test_payment_page']);
        }
    }

    public function render_logs_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        $logs = $this->get_logs();
        $settings = $this->get_settings();

        echo '<div class="wrap"><h1>SOS Partner Gateway — Log</h1>';
        $this->notice();
        echo '<p><strong>Endpoint:</strong> <code>' . esc_html(home_url($this->current_endpoint_path() . '/')) . '</code></p>';
        echo '<p><strong>Debug logs:</strong> ' . (!empty($settings['debug_logging_enabled']) ? 'attivi' : 'disattivati') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:16px 0;">';
        wp_nonce_field('sos_pg_clear_logs');
        echo '<input type="hidden" name="action" value="sos_pg_clear_logs"><button class="button">Svuota log</button></form>';

        echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Evento</th><th>Partner</th><th>Email</th><th>IP</th><th>Motivo</th><th>Context</th><th>Azione</th></tr></thead><tbody>';

        if (!$logs) {
            echo '<tr><td colspan="8">Nessun log.</td></tr>';
        }

        foreach ((array) $logs as $log) {
            echo '<tr>';
            echo '<td>' . esc_html($log->created_at) . '</td>';
            echo '<td>' . esc_html($log->event_type) . '</td>';
            echo '<td>' . esc_html($log->partner_id) . '</td>';
            echo '<td>' . esc_html($log->email) . '</td>';
            echo '<td>' . esc_html($log->ip) . '</td>';
            echo '<td>' . esc_html($log->reason) . '</td>';
            echo '<td style="max-width:300px;word-break:break-word;">' . esc_html((string) $log->context) . '</td>';
            echo '<td>';
            if (!empty($log->ip)) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('sos_pg_unlock_ip');
                echo '<input type="hidden" name="action" value="sos_pg_unlock_ip">';
                echo '<input type="hidden" name="ip" value="' . esc_attr($log->ip) . '">';
                echo '<button class="button button-small">Sblocca IP</button>';
                echo '</form>';
            } else {
                echo '—';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
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
        echo '<p class="description"><strong>Sito principale</strong>: riceve i login firmati, gestisce le prenotazioni, invia webhook ai partner.<br>';
        echo '<strong>Sito partner</strong>: firma e invia le richieste di login al sito principale tramite shortcode o tester.<br>';
        echo '<em>Dopo aver cambiato il ruolo, clicca &quot;Salva impostazioni&quot; per applicare la modalit&agrave;.</em></p>';
        echo '</td></tr>';

        if ($is_partner) {
            // --- Modalita partner: solo campi rilevanti ---
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
            echo '<textarea class="large-text code" rows="10" name="self_login_private_key_pem" placeholder="-----BEGIN PRIVATE KEY-----">' . esc_textarea($settings['self_login_private_key_pem']) . '</textarea>';
            echo '<p class="description">Chiave privata ECC per firmare le richieste di login. Deve corrispondere alla chiave pubblica configurata sul sito principale.</p>';
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

            echo '<tr><th>Debug logs sviluppo</th><td><label><input type="checkbox" name="debug_logging_enabled" value="1" ' . checked(!empty($settings['debug_logging_enabled']), true, false) . '> Attiva</label></td></tr>';
        } else {
            // --- Modalita sito principale: impostazioni complete ---
            echo '<tr><th>Slug endpoint login</th><td><input type="text" class="regular-text" name="endpoint_slug" value="' . esc_attr($settings['endpoint_slug']) . '"></td></tr>';

            echo '<tr><th>Pagina di cortesia</th><td><select name="courtesy_page_id"><option value="0">&mdash; Nessuna &mdash;</option>';
            $pages = get_pages(['sort_column' => 'post_title']);
            foreach ($pages as $p) {
                echo '<option value="' . esc_attr($p->ID) . '" ' . selected((int) $settings['courtesy_page_id'], (int) $p->ID, false) . '>' . esc_html($p->post_title) . ' (#' . $p->ID . ')</option>';
            }
            echo '</select></td></tr>';

            echo '<tr><th>Debug logs sviluppo</th><td><label><input type="checkbox" name="debug_logging_enabled" value="1" ' . checked(!empty($settings['debug_logging_enabled']), true, false) . '> Attiva</label></td></tr>';

            echo '<tr><th>Rate limit breve</th><td><input type="number" name="max_fail_short" value="' . esc_attr($settings['max_fail_short']) . '" min="1"> errori in <input type="number" name="window_short_minutes" value="' . esc_attr($settings['window_short_minutes']) . '" min="1"> minuti &rarr; ban <input type="number" name="ban_short_minutes" value="' . esc_attr($settings['ban_short_minutes']) . '" min="1"> minuti</td></tr>';

            echo '<tr><th>Rate limit lungo</th><td><input type="number" name="max_fail_long" value="' . esc_attr($settings['max_fail_long']) . '" min="1"> errori in <input type="number" name="window_long_minutes" value="' . esc_attr($settings['window_long_minutes']) . '" min="1"> minuti &rarr; ban <input type="number" name="ban_long_minutes" value="' . esc_attr($settings['ban_long_minutes']) . '" min="1"> minuti</td></tr>';

            echo '<tr><th>Chiave pubblica PEM</th><td><textarea class="large-text code" rows="12" name="public_key_pem">' . esc_textarea($settings['public_key_pem']) . '</textarea></td></tr>';
            echo '<tr><th>Slug callback pagamento partner</th><td><input type="text" class="regular-text" name="payment_callback_slug" value="' . esc_attr($settings['payment_callback_slug']) . '" placeholder="partner-payment-callback"><p class="description">Percorso chiamato dal partner per confermare il pagamento.</p></td></tr>';
            echo '<tr><th>Secret callback pagamento</th><td><input type="text" class="regular-text" name="payment_callback_secret" value="' . esc_attr($settings['payment_callback_secret']) . '" placeholder="secret condiviso"></td></tr>';
            echo '<tr><th>Stato di successo pagamento</th><td><input type="text" class="regular-text" name="payment_success_status" value="' . esc_attr($settings['payment_success_status']) . '" placeholder="attesa_partner"><p class="description">Slug dello stato da impostare quando il partner conferma il pagamento (es. attesa_partner).</p></td></tr>';
            echo '<tr><th colspan="2"><hr style="margin:4px 0;"><strong>Shortcode [sos_partner_prenota] &mdash; uso self-service</strong><p class="description" style="font-weight:normal;">Usato quando vuoi inserire un pulsante &quot;Prenota&quot; direttamente su una pagina di questo sito senza un portale partner esterno. La chiave privata qui sotto firma la richiesta di login.</p></th></tr>';
            echo '<tr><th>Partner ID self-use</th><td><input type="text" class="regular-text" name="self_login_partner_id" value="' . esc_attr($settings['self_login_partner_id']) . '" placeholder="hf"><p class="description">Partner ID di default per lo shortcode quando non specificato nell\'attributo.</p></td></tr>';
            echo '<tr><th>URL endpoint login</th><td><input type="url" class="regular-text" name="self_login_endpoint_url" value="' . esc_attr($settings['self_login_endpoint_url']) . '" placeholder="https://videoconsulto.sospediatra.org/partner-login/"><p class="description">Lascia vuoto per usare l\'endpoint locale (<code>' . esc_html(home_url($this->current_endpoint_path() . '/')) . '</code>). Compila con l\'URL completo del sito principale se il plugin &egrave; installato su un sito partner separato.</p></td></tr>';
            echo '<tr><th>Chiave privata self-use (PEM)</th><td><textarea class="large-text code" rows="10" name="self_login_private_key_pem" placeholder="-----BEGIN PRIVATE KEY-----">' . esc_textarea($settings['self_login_private_key_pem']) . '</textarea><p class="description">Chiave privata ECC per firmare le richieste generate dallo shortcode. <strong>Deve corrispondere alla chiave pubblica configurata sull\'endpoint di login.</strong></p></td></tr>';
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

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
        wp_nonce_field('sos_pg_save_webhooks');
        echo '<input type="hidden" name="action" value="sos_pg_save_webhooks">';
        $webhooks = $this->get_partner_webhooks();
        $webhooks[''] = ['url' => '', 'secret' => ''];
        echo '<table class="widefat striped"><thead><tr><th>Partner ID</th><th>Webhook URL</th><th>Secret (HMAC)</th></tr></thead><tbody>';
        foreach ($webhooks as $pid => $cfg) {
            $url = is_array($cfg) ? ($cfg['url'] ?? '') : '';
            $secret = is_array($cfg) ? ($cfg['secret'] ?? '') : '';
            echo '<tr>';
            echo '<td><input type="text" name="webhooks[partner_id][]" value="' . esc_attr($pid) . '" class="regular-text" placeholder="es. hf"></td>';
            echo '<td><input type="url" name="webhooks[url][]" value="' . esc_attr($url) . '" class="regular-text" placeholder="https://partner.example.com/webhook"></td>';
            echo '<td><input type="text" name="webhooks[secret][]" value="' . esc_attr($secret) . '" class="regular-text" placeholder="secret condiviso"></td>';
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
        $settings['debug_logging_enabled'] = !empty($_POST['debug_logging_enabled']) ? 1 : 0;
        $settings['self_login_private_key_pem'] = trim((string) wp_unslash($_POST['self_login_private_key_pem'] ?? ''));
        $settings['self_login_partner_id'] = sanitize_text_field(wp_unslash($_POST['self_login_partner_id'] ?? ''));
        $settings['self_login_endpoint_url'] = esc_url_raw(trim((string) wp_unslash($_POST['self_login_endpoint_url'] ?? '')));

        if ($settings['site_role'] === 'partner') {
            // Campi presenti solo nel form del sito partner.
            $settings['partner_webhook_secret'] = sanitize_text_field(wp_unslash($_POST['partner_webhook_secret'] ?? ''));
            $settings['partner_callback_url'] = esc_url_raw(trim((string) wp_unslash($_POST['partner_callback_url'] ?? '')));
            $settings['partner_callback_secret'] = sanitize_text_field(wp_unslash($_POST['partner_callback_secret'] ?? ''));
        } else {
            // Campi presenti solo nel form del sito principale.
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

        $map = [];
        if (is_array($partner_ids) && is_array($urls) && is_array($secrets)) {
            foreach ($partner_ids as $idx => $pid_raw) {
                $pid = sanitize_text_field(wp_unslash($pid_raw));
                $url = isset($urls[$idx]) ? esc_url_raw(trim((string) wp_unslash($urls[$idx]))) : '';
                $secret = isset($secrets[$idx]) ? sanitize_text_field(wp_unslash($secrets[$idx])) : '';

                if ($pid === '' || $url === '') {
                    continue;
                }

                $map[$pid] = [
                    'url' => $url,
                    'secret' => $secret,
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
        $config = $this->get_partner_discount_config();

        if ($config['pay_on_partner']) {
            // Forza il totale a 0 sul sito principale: il pagamento avviene sul portale del partner.
            // Cattura l'importo originale per includerlo nel webhook (partner_charge).
            if (in_array(current_filter(), ['latepoint_full_amount', 'latepoint_full_amount_for_service'], true)) {
                $this->partner_original_total = max(0.0, (float) $amount);
            }
            return 0.0;
        }

        if ($config['amount'] <= 0) {
            return $amount;
        }

        if ($config['type'] === 'percent') {
            $discount = (float) $amount * ($config['amount'] / 100.0);
            return max(0.0, (float) $amount - $discount);
        }

        // Sconto fisso in euro.
        return max(0.0, (float) $amount - $config['amount']);
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
        $partner_id = $this->get_current_partner_id();

        if ($partner_id === '') {
            $this->log_event('INFO', 'BOOKING_WEBHOOK_SKIP_NO_PARTNER', [
                'context' => ['booking_id' => $this->safe_get($booking, 'id')],
            ]);
            return;
        }

        $booking_id = $this->safe_get($booking, 'id');
        $status = $this->safe_get($booking, 'status');
        $service_id = $this->safe_get($booking, 'service_id');
        $agent_id = $this->safe_get($booking, 'agent_id');
        $location_id = $this->safe_get($booking, 'location_id');
        $start_date = $this->safe_get($booking, 'start_date');
        $start_time = $this->safe_get($booking, 'start_time');
        $end_time = $this->safe_get($booking, 'end_time');
        $total = (float) $this->safe_get($booking, 'total', 0);

        // Rileva se il pagamento avviene sul portale partner (pay_on_partner).
        $discount_config  = $this->get_partner_discount_config($partner_id);
        $partner_charge   = null;
        if ($discount_config['pay_on_partner'] && $this->partner_original_total !== null) {
            // Non azzera partner_original_total: handle_booking_created può essere richiamata
            // da entrambi gli hook (latepoint_after_create_booking e latepoint_booking_created)
            // per lo stesso booking nella stessa request, quindi il valore deve restare disponibile.
            $partner_charge = $this->partner_original_total;
        }
        $customer_email = $this->safe_get_nested($booking, ['customer', 'email']);
        $customer_phone = $this->safe_get_nested($booking, ['customer', 'phone']);
        $customer_name = $this->safe_get_nested($booking, ['customer', 'full_name']);

        $this->log_event('INFO', 'BOOKING_PARTNER_HOOK', [
            'partner_id' => $partner_id,
            'context' => [
                'booking_id' => $booking_id,
                'status' => $status,
            ],
        ]);

        $payload = [
            'event' => 'booking_created',
            'partner_id' => $partner_id,
            'booking_id' => $booking_id,
            'status' => $status,
            'service_id' => $service_id,
            'agent_id' => $agent_id,
            'location_id' => $location_id,
            'start_date' => $start_date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'total' => $total,
            'customer_email' => $customer_email,
            'customer_phone' => $customer_phone,
            'customer_name' => $customer_name,
        ];

        // Salva partner_id e location_id in meta LatePoint per tracciamento/report.
        if ($booking_id) {
            if ($partner_id) {
                $this->set_booking_meta($booking_id, 'partner_id', $partner_id);
            }
            if ($location_id) {
                $this->set_booking_meta($booking_id, 'partner_location_id', $location_id);
            }
        }

        // Webhook per-partner con payload minimo utile al pagamento.
        $partner_payload = [
            'event' => 'booking_created',
            'partner_id' => $partner_id,
            'booking_id' => $booking_id,
            'status' => $status,
            'total' => $total,
            'service_id' => $service_id,
            'location_id' => $location_id,
            'start_date' => $start_date,
            'start_time' => $start_time,
            'customer_email' => $customer_email,
        ];

        // Se il pagamento è sul portale del partner, includi l'importo da incassare.
        // total = 0 (il sito principale non incassa); partner_charge = importo originale del servizio.
        if ($partner_charge !== null) {
            $partner_payload['partner_charge'] = $partner_charge;
            $partner_payload['pay_on_partner'] = true;
        }

        $this->send_partner_webhook($partner_id, $partner_payload);
    }

    private function send_partner_webhook($partner_id, array $payload) {
        if (!$partner_id) {
            return;
        }

        $webhooks = $this->get_partner_webhooks();
        if (empty($webhooks[$partner_id]['url'])) {
            $this->log_event('INFO', 'WEBHOOK_PARTNER_SKIP_NO_URL', [
                'partner_id' => $partner_id,
                'context' => ['booking_id' => $payload['booking_id'] ?? null],
            ]);
            return;
        }

        $url = $webhooks[$partner_id]['url'];
        $secret = $webhooks[$partner_id]['secret'] ?? '';

        $body = wp_json_encode($payload);
        $headers = ['Content-Type' => 'application/json'];
        if ($secret !== '') {
            $headers['X-SOSPG-Signature'] = hash_hmac('sha256', (string) $body, (string) $secret);
        }

        $resp = wp_remote_post($url, [
            'headers' => $headers,
            'body' => $body,
            'timeout' => 10,
        ]);

        if (is_wp_error($resp)) {
            $this->log_event('ERROR', 'WEBHOOK_PARTNER_FAIL', [
                'partner_id' => $partner_id,
                'reason' => $resp->get_error_message(),
                'context' => $payload,
            ]);
            return;
        }

        $this->log_event('INFO', 'WEBHOOK_PARTNER_SENT', [
            'partner_id' => $partner_id,
            'context' => ['http_code' => wp_remote_retrieve_response_code($resp)],
        ]);
    }

    public function handle_payment_callback() {
        $request_path = $this->current_request_path();
        $cb_path = $this->current_payment_callback_path();

        if ($request_path !== $cb_path && $request_path !== $cb_path . '/') {
            return;
        }

        $settings = $this->get_settings();
        $secret = $settings['payment_callback_secret'];

        if ($secret === '') {
            status_header(403);
            exit('Callback non attivato');
        }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            status_header(400);
            exit('Payload non valido');
        }

        $sig = $_SERVER['HTTP_X_SOSPG_SIGNATURE'] ?? '';
        $calc = hash_hmac('sha256', (string) $raw, (string) $secret);
        if (!$sig || !hash_equals($calc, $sig)) {
            status_header(401);
            exit('Firma non valida');
        }

        $booking_id = absint($data['booking_id'] ?? 0);
        $incoming_status = sanitize_text_field($data['status'] ?? '');
        $transaction_id = sanitize_text_field($data['transaction_id'] ?? '');
        $partner_id = sanitize_text_field($data['partner_id'] ?? '');

        if (!$booking_id) {
            status_header(400);
            exit('Dati mancanti');
        }

        // Stato finale sempre gestito da LatePoint: fissiamo pending (o valore da impostazioni) e pagamento "paid".
        $target_status = $settings['payment_success_status'] ?: 'pending';
        $target_payment_status = 'paid';

        if ($target_status === '') {
            status_header(400);
            exit('Stato mancante');
        }

        global $wpdb;
        $wpdb->update(
            $this->booking_table,
            [
                'status' => $target_status,
                'payment_status' => $target_payment_status,
            ],
            ['id' => $booking_id],
            ['%s', '%s'],
            ['%d']
        );

        $this->log_event('INFO', 'PAYMENT_CALLBACK_OK', [
            'partner_id' => $partner_id,
            'context' => [
                'booking_id' => $booking_id,
                'status' => $target_status,
                'transaction_id' => $transaction_id,
            ],
        ]);

        wp_send_json_success(['ok' => true]);
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
     * Hook init: ascolta ?sos_pg_webhook=1 e registra il webhook ricevuto.
     * Usato in modalita partner per ricevere le notifiche booking_created dal sito principale.
     */
    public function handle_partner_tester_webhook() {
        if (!isset($_GET['sos_pg_webhook'])) {
            return;
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
        $last_booking_id = 0;
        if (!empty($last['body']['booking_id'])) {
            $last_booking_id = (int) $last['body']['booking_id'];
        }

        echo '<h2>Listener webhook (booking_created)</h2>';
        echo '<p>Configura nel sito principale questo URL webhook per il tuo partner: <code>' . esc_html($listener_url) . '</code></p>';
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

        echo '<div class="notice notice-success"><p>Callback inviata. HTTP ' . esc_html($code) . '.</p></div>';
        echo '<p><a href="' . $back_url . '" class="button">Torna</a></p>';
        echo '<script>setTimeout(function(){window.location.replace("' . $back_url_js . '");},2000);</script>';
    }


}