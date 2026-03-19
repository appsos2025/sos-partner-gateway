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
    private $default_latepoint_partner_field = 'cf_910bA88i';

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
        add_action('add_meta_boxes', [$this, 'register_partner_page_metabox']);
        add_action('save_post_page', [$this, 'save_partner_page_meta'], 10, 2);

        add_action('init', [$this, 'handle_partner_login'], 1);
        add_action('template_redirect', [$this, 'protect_partner_pages'], 1);
        add_action('init', [$this, 'handle_payment_callback'], 1);

        add_action('admin_post_sos_pg_unlock_ip', [$this, 'handle_unlock_ip']);
        add_action('admin_post_sos_pg_save_settings', [$this, 'handle_save_settings']);
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
                'latepoint_partner_field' => $this->default_latepoint_partner_field,
            ]);
        }
    }

    private function get_settings() {
        $defaults = [
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
            'latepoint_partner_field' => $this->default_latepoint_partner_field,
        ];

        $settings = get_option($this->settings_key, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        return wp_parse_args($settings, $defaults);
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

    private function get_partner_discount_amount($partner_id = '') {
        if ($partner_id === '') {
            $partner_id = $this->get_current_partner_id();
        }

        if ($partner_id === '') {
            return 0.0;
        }

        $map = $this->get_partner_discounts();
        return isset($map[$partner_id]) ? (float) $map[$partner_id] : 0.0;
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
        add_menu_page('SOS Partner Gateway', 'SOS Partner Gateway', 'manage_options', 'sos-partner-gateway', [$this, 'render_logs_page'], 'dashicons-shield', 58);
        add_submenu_page('sos-partner-gateway', 'Log', 'Log', 'manage_options', 'sos-partner-gateway', [$this, 'render_logs_page']);
        add_submenu_page('sos-partner-gateway', 'Impostazioni', 'Impostazioni', 'manage_options', 'sos-partner-gateway-settings', [$this, 'render_settings_page']);
        add_submenu_page('sos-partner-gateway', 'Pagine Partner', 'Pagine Partner', 'manage_options', 'sos-partner-gateway-pages', [$this, 'render_pages_page']);
        add_submenu_page('sos-partner-gateway', 'Test pagamento', 'Test pagamento', 'manage_options', 'sos-partner-gateway-payment-test', [$this, 'render_test_payment_page']);
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
        $pages = get_pages(['sort_column' => 'post_title']);

        echo '<div class="wrap"><h1>SOS Partner Gateway — Impostazioni</h1>';
        $this->notice();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('sos_pg_save_settings');
        echo '<input type="hidden" name="action" value="sos_pg_save_settings">';
        echo '<table class="form-table">';
        echo '<tr><th>Slug endpoint login</th><td><input type="text" class="regular-text" name="endpoint_slug" value="' . esc_attr($settings['endpoint_slug']) . '"></td></tr>';

        echo '<tr><th>Pagina di cortesia</th><td><select name="courtesy_page_id"><option value="0">— Nessuna —</option>';
        foreach ($pages as $p) {
            echo '<option value="' . esc_attr($p->ID) . '" ' . selected((int) $settings['courtesy_page_id'], (int) $p->ID, false) . '>' . esc_html($p->post_title) . ' (#' . $p->ID . ')</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th>Debug logs sviluppo</th><td><label><input type="checkbox" name="debug_logging_enabled" value="1" ' . checked(!empty($settings['debug_logging_enabled']), true, false) . '> Attiva</label></td></tr>';

        echo '<tr><th>Rate limit breve</th><td><input type="number" name="max_fail_short" value="' . esc_attr($settings['max_fail_short']) . '" min="1"> errori in <input type="number" name="window_short_minutes" value="' . esc_attr($settings['window_short_minutes']) . '" min="1"> minuti → ban <input type="number" name="ban_short_minutes" value="' . esc_attr($settings['ban_short_minutes']) . '" min="1"> minuti</td></tr>';

        echo '<tr><th>Rate limit lungo</th><td><input type="number" name="max_fail_long" value="' . esc_attr($settings['max_fail_long']) . '" min="1"> errori in <input type="number" name="window_long_minutes" value="' . esc_attr($settings['window_long_minutes']) . '" min="1"> minuti → ban <input type="number" name="ban_long_minutes" value="' . esc_attr($settings['ban_long_minutes']) . '" min="1"> minuti</td></tr>';

        echo '<tr><th>Chiave pubblica PEM</th><td><textarea class="large-text code" rows="12" name="public_key_pem">' . esc_textarea($settings['public_key_pem']) . '</textarea></td></tr>';
        echo '<tr><th>Slug callback pagamento partner</th><td><input type="text" class="regular-text" name="payment_callback_slug" value="' . esc_attr($settings['payment_callback_slug']) . '" placeholder="partner-payment-callback"><p class="description">Percorso chiamato dal partner per confermare il pagamento.</p></td></tr>';
        echo '<tr><th>Secret callback pagamento</th><td><input type="text" class="regular-text" name="payment_callback_secret" value="' . esc_attr($settings['payment_callback_secret']) . '" placeholder="secret condiviso"></td></tr>';
        echo '<tr><th>Stato di successo pagamento</th><td><input type="text" class="regular-text" name="payment_success_status" value="' . esc_attr($settings['payment_success_status']) . '" placeholder="attesa_partner"><p class="description">Slug dello stato da impostare quando il partner conferma il pagamento (es. attesa_partner).</p></td></tr>';
        echo '<tr><th>Campo LatePoint partner</th><td><input type="text" class="regular-text" name="latepoint_partner_field" value="' . esc_attr($settings['latepoint_partner_field']) . '" placeholder="' . esc_attr($this->default_latepoint_partner_field) . '"><p class="description">Nome del campo custom LatePoint usato per tracciare il partner nella prenotazione (es. cf_910bA88i). Può variare per installazioni diverse.</p></td></tr>';
        echo '</table>';
        submit_button('Salva impostazioni');
        echo '</form></div>';

        // Sconti partner
        $discounts = $this->get_partner_discounts();
        echo '<div class="wrap" style="margin-top:24px;"><h2>Sconti Partner</h2>';
        echo '<p>Imposta lo sconto fisso (in euro) da applicare ai partner. Per HF inserisci 100 per azzerare l’importo mostrato al cliente.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('sos_pg_save_discounts');
        echo '<input type="hidden" name="action" value="sos_pg_save_discounts">';
        echo '<table class="widefat striped"><thead><tr><th>Partner ID</th><th>Sconto (€)</th></tr></thead><tbody>';
        $rows = $discounts;
        $rows[''] = '';
        foreach ($rows as $pid => $amount) {
            echo '<tr>';
            echo '<td><input type="text" name="discounts[partner_id][]" value="' . esc_attr($pid) . '" class="regular-text" placeholder="es. hf"></td>';
            echo '<td><input type="number" step="0.01" min="0" name="discounts[amount][]" value="' . esc_attr($amount) . '" class="regular-text" placeholder="es. 100"></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p><button class="button button-primary" type="submit">Salva sconti partner</button></p>';
        echo '</form></div>';
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
        $settings['endpoint_slug'] = sanitize_title(wp_unslash($_POST['endpoint_slug'] ?? 'partner-login'));
        $settings['courtesy_page_id'] = absint($_POST['courtesy_page_id'] ?? 0);
        $settings['debug_logging_enabled'] = !empty($_POST['debug_logging_enabled']) ? 1 : 0;
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
        $settings['latepoint_partner_field'] = sanitize_text_field(wp_unslash($_POST['latepoint_partner_field'] ?? $this->default_latepoint_partner_field)) ?: $this->default_latepoint_partner_field;

        update_option($this->settings_key, $settings);

        wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway-settings', 'msg' => 'saved'], admin_url('admin.php')));
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

        $partner_ids = $_POST['discounts']['partner_id'] ?? [];
        $amounts = $_POST['discounts']['amount'] ?? [];

        $map = [];
        if (is_array($partner_ids) && is_array($amounts)) {
            foreach ($partner_ids as $idx => $pid_raw) {
                $pid = sanitize_text_field(wp_unslash($pid_raw));
                $amount = isset($amounts[$idx]) ? (float) wp_unslash($amounts[$idx]) : 0.0;

                if ($pid === '' || $amount <= 0) {
                    continue;
                }

                $map[$pid] = round($amount, 2);
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
        $discount = $this->get_partner_discount_amount();

        if ($discount <= 0) {
            return $amount;
        }

        $new_amount = max(0, (float) $amount - $discount);
        return $new_amount;
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
        $start_date = $this->safe_get($booking, 'start_date');
        $start_time = $this->safe_get($booking, 'start_time');
        $end_time = $this->safe_get($booking, 'end_time');
        $total = (float) $this->safe_get($booking, 'total', 0);
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
            'start_date' => $start_date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'total' => $total,
            'customer_email' => $customer_email,
            'customer_phone' => $customer_phone,
            'customer_name' => $customer_name,
        ];

        // Salva l'id partner in meta LatePoint (campo configurabile e partner_id) per tracciamento/report.
        if ($booking_id && $partner_id) {
            $this->set_booking_meta($booking_id, $this->get_partner_field_name(), $partner_id);
            $this->set_booking_meta($booking_id, 'partner_id', $partner_id);
        }

        // Webhook per-partner con payload minimo utile al pagamento.
        $partner_payload = [
            'event' => 'booking_created',
            'partner_id' => $partner_id,
            'booking_id' => $booking_id,
            'status' => $status,
            'total' => $total,
            'service_id' => $service_id,
            'start_date' => $start_date,
            'start_time' => $start_time,
            'customer_email' => $customer_email,
            'partner_field' => $this->get_partner_field_name(),
        ];
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

    private function get_partner_field_name() {
        $field = trim((string) $this->get_settings()['latepoint_partner_field']);
        return $field !== '' ? $field : $this->default_latepoint_partner_field;
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
}