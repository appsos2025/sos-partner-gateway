<?php
if (!defined('ABSPATH')) exit;

class SOS_PG_Plugin {
    private static $instance = null;
    private $table_logs = '';
    private $settings_key = 'sos_pg_settings';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_logs = $wpdb->prefix . SOS_PG_TABLE_LOGS;

        register_activation_hook(SOS_PG_FILE, [$this, 'activate']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('add_meta_boxes', [$this, 'register_partner_page_metabox']);
        add_action('save_post_page', [$this, 'save_partner_page_meta'], 10, 2);

        add_action('init', [$this, 'handle_partner_login'], 1);
        add_action('template_redirect', [$this, 'protect_partner_pages'], 1);

        add_action('admin_post_sos_pg_unlock_ip', [$this, 'handle_unlock_ip']);
        add_action('admin_post_sos_pg_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_sos_pg_clear_logs', [$this, 'handle_clear_logs']);
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
        ];

        $settings = get_option($this->settings_key, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        return wp_parse_args($settings, $defaults);
    }

    private function current_endpoint_path() {
        $slug = trim((string) $this->get_settings()['endpoint_slug'], '/');
        return '/' . $slug;
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
        if (!$page) {
            $this->register_fail('pagina partner non configurata', $partner_id, $email);
            status_header(404);
            exit('Pagina partner non configurata');
        }

        $redirect_url = $this->get_redirect_url_for_page($page->ID);

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
        echo '</table>';
        submit_button('Salva impostazioni');
        echo '</form></div>';
    }

    public function render_pages_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        $pages = $this->get_partner_pages();

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
}