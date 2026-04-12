<?php
if (!defined('ABSPATH')) exit;

class SOS_PG_Settings {
    private $settings_key;
    private $routes_key;
    private $discounts_key;
    private $webhooks_key;
    private $partner_configs_key = 'sos_pg_partner_configs';

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

    public function get_partner_configs_key() {
        return $this->partner_configs_key;
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

        $admin_cfgs = $this->get_partner_configs_option();

        // Admin-managed configs take precedence; keep legacy routes for missing IDs.
        foreach ($routes as $pid => $cfg) {
            if (!isset($admin_cfgs[$pid])) {
                $admin_cfgs[$pid] = $cfg;
            }
        }

        return $admin_cfgs;
    }

    public function get_partner_configs_option() {
        $map = get_option($this->partner_configs_key, []);
        return is_array($map) ? $map : [];
    }

    public function get_partner_configs_raw() {
        $routes = $this->get_partner_routes_raw();
        $webhooks = $this->get_partner_webhooks();

        $partner_ids = array_unique(array_merge(array_keys((array) $routes), array_keys((array) $webhooks)));

        $base_defaults = [
            'partner_id' => '',
            'enabled' => true,
            'type' => 'wordpress',
            'integration_mode' => '',
            'completion_return_url' => '',
            'api_base_url' => '',
            'api_key' => '',
            'public_key_pem' => '',
            'private_key_pem' => '',
            'private_key_path' => '',
            'webhook_url' => '',
            'webhook_secret' => '',
            'callback_secret' => '',
            'validation_token_strategy' => '',
            'no_upfront_cost' => false,
            'external_ref_mapping' => '',
            'flags' => [],
            'metadata' => [],
        ];

        $map = [];

        foreach ($partner_ids as $pid) {
            $pid_clean = sanitize_text_field((string) $pid);
            if ($pid_clean === '') {
                continue;
            }

            $route_entry = $routes[$pid] ?? null;
            $webhook_entry = $webhooks[$pid] ?? null;

            $is_route_array = is_array($route_entry);
            $is_webhook_array = is_array($webhook_entry);

            $cfg = $base_defaults;
            $cfg['partner_id'] = $pid_clean;

            if ($is_route_array) {
                $cfg = array_merge($cfg, $route_entry);
            } elseif (is_string($route_entry) && $route_entry !== '') {
                // Legacy string route: treat as wordpress login/redirect path.
                $cfg['type'] = $cfg['type'] ?: 'wordpress';
                $cfg['integration_mode'] = $cfg['integration_mode'] ?: 'login_redirect';
                $cfg['metadata']['route'] = $route_entry;
            }

            if ($is_webhook_array) {
                if (isset($webhook_entry['url'])) {
                    $cfg['webhook_url'] = (string) $webhook_entry['url'];
                }
                if (isset($webhook_entry['secret'])) {
                    $cfg['webhook_secret'] = (string) $webhook_entry['secret'];
                }
                if (isset($webhook_entry['callback_secret'])) {
                    $cfg['callback_secret'] = (string) $webhook_entry['callback_secret'];
                }
            }

            // Normalize arrays.
            if (!is_array($cfg['flags'])) {
                $cfg['flags'] = [];
            }
            if (!is_array($cfg['metadata'])) {
                $cfg['metadata'] = [];
            }

            $map[$pid_clean] = $cfg;
        }

        return $map;
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
