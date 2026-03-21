<?php
if (!defined('ABSPATH')) exit;

class SOS_PG_Settings {
    private $settings_key;
    private $routes_key;
    private $discounts_key;
    private $webhooks_key;

    public function __construct(
        $settings_key = 'sos_pg_settings',
        $routes_key = 'sos_pg_partner_routes',
        $discounts_key = 'sos_pg_partner_discounts',
        $webhooks_key = 'sos_pg_partner_webhooks'
    ) {
        $this->settings_key = $settings_key;
        $this->routes_key = $routes_key;
        $this->discounts_key = $discounts_key;
        $this->webhooks_key = $webhooks_key;
    }

    public function get_settings_key() {
        return $this->settings_key;
    }

    public function get_routes_key() {
        return $this->routes_key;
    }

    public function get_discounts_key() {
        return $this->discounts_key;
    }

    public function get_webhooks_key() {
        return $this->webhooks_key;
    }

    public function get_settings() {
        $settings = get_option($this->settings_key, []);
        if (!is_array($settings)) {
            $settings = [];
        }
        return wp_parse_args($settings, $this->defaults());
    }

    public function get_partner_routes() {
        $routes = get_option($this->routes_key, []);
        if (!is_array($routes)) {
            return [];
        }

        $clean = [];
        foreach ($routes as $pid => $path) {
            $pid = sanitize_text_field((string) $pid);
            if (is_array($path)) {
                // Preserve backward compatibility: skip complex entries here.
                continue;
            }
            $path = trim((string) $path);
            if ($pid === '' || $path === '') {
                continue;
            }

            if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
                $clean[$pid] = esc_url_raw($path);
            } else {
                $clean[$pid] = '/' . ltrim($path, '/');
            }
        }

        return $clean;
    }

    public function get_partner_routes_raw() {
        $defaults = [
            'caf-ext' => [
                'type' => 'external_api',
                'api_base_url' => 'https://cafsanitario.sosmedico.org',
                'enabled' => true,
                'api_key' => 'test-key-internal-only',
            ],
        ];

        $routes = get_option($this->routes_key, []);
        $routes = is_array($routes) ? $routes : [];

        foreach ($defaults as $pid => $cfg) {
            if (!isset($routes[$pid])) {
                $routes[$pid] = $cfg; // test config, safe to remove later
            }
        }

        return $routes;
    }

    public function get_partner_discounts() {
        $map = get_option($this->discounts_key, []);
        return is_array($map) ? $map : [];
    }

    public function get_partner_webhooks() {
        $map = get_option($this->webhooks_key, []);
        return is_array($map) ? $map : [];
    }

    private function defaults() {
        return [
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
    }
}
