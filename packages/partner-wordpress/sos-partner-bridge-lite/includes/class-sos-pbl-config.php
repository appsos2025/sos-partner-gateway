<?php

if (!defined('ABSPATH')) {
    exit;
}

class SOS_PBL_Config {
    private $option_key = 'sos_pbl_settings';

    public function get_option_key() {
        return $this->option_key;
    }

    public function defaults() {
        return [
            'partner_id' => '',
            'central_base_url' => '',
            'integration_mode' => 'handoff_login',
            'shared_secret' => '',
            'private_key_path' => '',
            'handoff_endpoint_path' => '/partner-login/',
            'payment_callback_path' => '/partner-payment-callback/',
            'embedded_entrypoint_path' => '/wp-json/sos-pg/v1/embedded-booking/create',
            'debug_enabled' => 0,
            'show_advanced_settings' => 0,
        ];
    }

    public function ensure_defaults() {
        if (get_option($this->option_key, null) === null) {
            add_option($this->option_key, $this->defaults());
        }
    }

    public function get() {
        $settings = get_option($this->option_key, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        return wp_parse_args($settings, $this->defaults());
    }

    public function update(array $settings) {
        $defaults = $this->defaults();
        $clean = [
            'partner_id' => sanitize_text_field((string) ($settings['partner_id'] ?? '')),
            'central_base_url' => esc_url_raw((string) ($settings['central_base_url'] ?? '')),
            'integration_mode' => sanitize_text_field((string) ($settings['integration_mode'] ?? 'handoff_login')),
            'shared_secret' => sanitize_text_field((string) ($settings['shared_secret'] ?? '')),
            'private_key_path' => sanitize_text_field((string) ($settings['private_key_path'] ?? '')),
            'handoff_endpoint_path' => sanitize_text_field((string) ($settings['handoff_endpoint_path'] ?? $defaults['handoff_endpoint_path'])),
            'payment_callback_path' => sanitize_text_field((string) ($settings['payment_callback_path'] ?? $defaults['payment_callback_path'])),
            'embedded_entrypoint_path' => sanitize_text_field((string) ($settings['embedded_entrypoint_path'] ?? $defaults['embedded_entrypoint_path'])),
            'debug_enabled' => !empty($settings['debug_enabled']) ? 1 : 0,
            'show_advanced_settings' => !empty($settings['show_advanced_settings']) ? 1 : 0,
        ];

        if ($clean['private_key_path'] !== '') {
            $clean['private_key_path'] = str_replace('\\\\', '/', trim((string) $clean['private_key_path']));
        }

        foreach (['handoff_endpoint_path', 'payment_callback_path', 'embedded_entrypoint_path'] as $path_key) {
            $value = trim((string) $clean[$path_key]);
            if ($value === '') {
                $value = (string) $defaults[$path_key];
            }
            if ($value !== '' && $value[0] !== '/') {
                $value = '/' . $value;
            }
            $clean[$path_key] = $value;
        }

        if (!in_array($clean['integration_mode'], ['handoff_login', 'embedded_booking', 'payment_callback', 'combined'], true)) {
            $clean['integration_mode'] = 'handoff_login';
        }

        $log_payload = $clean;
        if (!empty($log_payload['shared_secret'])) {
            $log_payload['shared_secret'] = '***masked***';
        }
        error_log('SOS_PBL: update payload for option ' . $this->option_key . ' => ' . wp_json_encode($log_payload));
        $updated = update_option($this->option_key, $clean, false);
        error_log('SOS_PBL: update_option result => ' . ($updated ? 'updated' : 'unchanged_or_failed'));

        return $clean;
    }

    public function is_debug_enabled() {
        $settings = $this->get();
        return !empty($settings['debug_enabled']);
    }
}
