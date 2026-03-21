<?php
if (!defined('ABSPATH')) exit;

class SOS_PG_Partner_Registry {
    private $settings;

    public function __construct(SOS_PG_Settings $settings) {
        $this->settings = $settings;
    }

    public function get_routes() {
        return $this->settings->get_partner_routes();
    }

    public function get_discounts() {
        return $this->settings->get_partner_discounts();
    }

    public function get_webhooks() {
        return $this->settings->get_partner_webhooks();
    }

    public function get_webhook_for_partner($partner_id) {
        $map = $this->settings->get_partner_webhooks();
        return is_array($map) && isset($map[$partner_id]) ? $map[$partner_id] : null;
    }

    public function get_partner_id_by_location($location_id) {
        if ((string) $location_id === '') {
            return '';
        }

        foreach ($this->settings->get_partner_webhooks() as $pid => $cfg) {
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

    public function get_partner_config($partner_id) {
        $partner_id = sanitize_text_field((string) $partner_id);
        if ($partner_id === '') {
            return null;
        }

        $map = $this->settings->get_partner_configs_raw();
        if (!isset($map[$partner_id]) || !is_array($map[$partner_id])) {
            return null;
        }

        $cfg = $map[$partner_id];

        $cfg['partner_id'] = $partner_id;
        $cfg['enabled'] = !empty($cfg['enabled']);
        $cfg['type'] = sanitize_text_field((string) ($cfg['type'] ?? 'wordpress'));
        $cfg['integration_mode'] = sanitize_text_field((string) ($cfg['integration_mode'] ?? ''));
        $cfg['api_base_url'] = isset($cfg['api_base_url']) ? esc_url_raw((string) $cfg['api_base_url']) : '';
        $cfg['api_key'] = isset($cfg['api_key']) ? (string) $cfg['api_key'] : '';
        $cfg['public_key_pem'] = isset($cfg['public_key_pem']) ? (string) $cfg['public_key_pem'] : '';
        $cfg['private_key_pem'] = isset($cfg['private_key_pem']) ? (string) $cfg['private_key_pem'] : '';
        $cfg['webhook_url'] = isset($cfg['webhook_url']) ? esc_url_raw((string) $cfg['webhook_url']) : '';
        $cfg['webhook_secret'] = isset($cfg['webhook_secret']) ? (string) $cfg['webhook_secret'] : '';
        $cfg['callback_secret'] = isset($cfg['callback_secret']) ? (string) $cfg['callback_secret'] : '';
        $cfg['validation_token_strategy'] = isset($cfg['validation_token_strategy']) ? sanitize_text_field((string) $cfg['validation_token_strategy']) : '';
        $cfg['no_upfront_cost'] = !empty($cfg['no_upfront_cost']);
        $cfg['external_ref_mapping'] = isset($cfg['external_ref_mapping']) ? sanitize_text_field((string) $cfg['external_ref_mapping']) : '';
        $cfg['flags'] = isset($cfg['flags']) && is_array($cfg['flags']) ? $cfg['flags'] : [];
        $cfg['metadata'] = isset($cfg['metadata']) && is_array($cfg['metadata']) ? $cfg['metadata'] : [];

        return $cfg;
    }

    public function get_partner_configs() {
        $raw = $this->settings->get_partner_configs_raw();
        $normalized = [];

        foreach ($raw as $pid => $_) {
            $cfg = $this->get_partner_config($pid);
            if ($cfg !== null) {
                $normalized[$pid] = $cfg;
            }
        }

        return $normalized;
    }

    public function get_external_api_partner($partner_id) {
        $partner_id = sanitize_text_field((string) $partner_id);
        if ($partner_id === '') {
            return null;
        }

        $sources = [
            $this->settings->get_partner_routes_raw(),
            $this->settings->get_partner_webhooks(),
        ];

        foreach ($sources as $map) {
            if (!is_array($map) || !isset($map[$partner_id]) || !is_array($map[$partner_id])) {
                continue;
            }

            $candidate = $map[$partner_id];
            if (($candidate['type'] ?? '') !== 'external_api') {
                continue;
            }

            $api_base_url = isset($candidate['api_base_url']) ? esc_url_raw((string) $candidate['api_base_url']) : '';
            $api_key = isset($candidate['api_key']) ? (string) $candidate['api_key'] : '';
            $enabled = !empty($candidate['enabled']);

            return [
                'partner_id' => $partner_id,
                'type' => 'external_api',
                'api_base_url' => $api_base_url,
                'api_key' => $api_key,
                'enabled' => $enabled,
            ];
        }

        return null;
    }

    public function get_embedded_booking_partner($partner_id) {
        $partner_id = sanitize_text_field((string) $partner_id);
        if ($partner_id === '') {
            return null;
        }

        $cfg = $this->get_partner_config($partner_id);
        if (!$cfg || ($cfg['type'] ?? '') !== 'embedded_booking') {
            return null;
        }

        return [
            'partner_id' => $cfg['partner_id'],
            'type' => 'embedded_booking',
            'enabled' => !empty($cfg['enabled']),
            'validation_token_strategy' => $cfg['validation_token_strategy'] ?? '',
            'no_upfront_cost' => !empty($cfg['no_upfront_cost']),
            'external_ref_mapping' => $cfg['external_ref_mapping'] ?? '',
            'metadata' => isset($cfg['metadata']) && is_array($cfg['metadata']) ? $cfg['metadata'] : [],
        ];
    }

    public function get_validation_token_strategy($partner_id) {
        $cfg = $this->get_partner_config($partner_id);
        return $cfg ? (string) ($cfg['validation_token_strategy'] ?? '') : '';
    }

    public function get_external_reference_mapping($partner_id) {
        $cfg = $this->get_partner_config($partner_id);
        return $cfg ? (string) ($cfg['external_ref_mapping'] ?? '') : '';
    }
}
