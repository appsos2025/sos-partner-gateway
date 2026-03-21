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
}
