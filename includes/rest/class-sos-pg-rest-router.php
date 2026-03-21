<?php
if (!defined('ABSPATH')) exit;

class SOS_PG_REST_Router {
    private $plugin;

    public function __construct(SOS_PG_Plugin $plugin) {
        $this->plugin = $plugin;
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('sos-pg/v1', '/health', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle_health'],
        ]);

        register_rest_route('sos-pg/v1', '/session', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle_session'],
        ]);

        register_rest_route('sos-pg/v1', '/partners/(?P<partner_id>[A-Za-z0-9_-]{1,64})', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle_partner_lookup'],
            'args' => [
                'partner_id' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function handle_health(WP_REST_Request $request) {
        return new WP_REST_Response($this->plugin->get_health_payload());
    }

    public function handle_partner_lookup(WP_REST_Request $request) {
        $partner_id = sanitize_text_field((string) $request->get_param('partner_id'));
        if ($partner_id === '') {
            return new WP_Error('sos_pg_invalid_partner', 'Partner non valido', ['status' => 404]);
        }

        $registry = $this->plugin->get_partner_registry();
        $config = $registry ? $registry->get_external_api_partner($partner_id) : null;

        if (!$config || ($config['type'] ?? '') !== 'external_api') {
            return new WP_Error('sos_pg_partner_not_found', 'Partner non trovato', ['status' => 404]);
        }

        return new WP_REST_Response([
            'ok' => true,
            'partner_id' => (string) $config['partner_id'],
            'type' => 'external_api',
            'enabled' => !empty($config['enabled']),
            'api_base_url' => isset($config['api_base_url']) ? esc_url_raw((string) $config['api_base_url']) : '',
        ]);
    }

    public function handle_session(WP_REST_Request $request) {
        if (!is_user_logged_in()) {
            return new WP_REST_Response(['logged_in' => false]);
        }

        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return new WP_REST_Response(['logged_in' => false]);
        }

        $partner_id = sanitize_text_field((string) get_user_meta($user->ID, 'partner_id', true));
        if ($partner_id === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $partner_id)) {
            $cookie_pid = sanitize_text_field((string) ($_COOKIE['sos_pg_partner_id'] ?? ''));
            $partner_id = preg_match('/^[A-Za-z0-9_-]{1,64}$/', $cookie_pid) ? $cookie_pid : '';
        }

        return new WP_REST_Response([
            'logged_in' => true,
            'user_id' => (int) $user->ID,
            'email' => (string) $user->user_email,
            'partner_id' => $partner_id,
        ]);
    }
}
