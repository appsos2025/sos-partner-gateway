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
    }

    public function handle_health(WP_REST_Request $request) {
        return new WP_REST_Response($this->plugin->get_health_payload());
    }
}
