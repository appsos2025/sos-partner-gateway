<?php
if (!defined('ABSPATH')) exit;

class SOS_PG_Embedded_Booking {
    private $registry;

    public function __construct(SOS_PG_Partner_Registry $registry) {
        $this->registry = $registry;
    }

    public function get_validation_token_strategy($partner_id) {
        return $this->registry->get_validation_token_strategy($partner_id);
    }

    public function get_external_reference_mapping($partner_id) {
        return $this->registry->get_external_reference_mapping($partner_id);
    }

    /**
     * Extract a validation token from generic request data (array or WP_REST_Request).
     * Returns a normalized shape without enforcing any validation logic.
     */
    public function extract_validation_token_from_request($request_data) {
        $payload = [
            'token_raw' => '',
            'token_type' => '',
            'token_strategy' => '',
            'external_reference' => '',
            'partner_id' => '',
            'email' => '',
            'name' => '',
            'phone' => '',
        ];

        $get = function($key) use ($request_data) {
            if (is_array($request_data) && isset($request_data[$key])) {
                return $request_data[$key];
            }
            if (is_object($request_data) && method_exists($request_data, 'get_param')) {
                return $request_data->get_param($key);
            }
            return null;
        };

        $token_raw = $get('validation_token');
        $payload['token_raw'] = is_string($token_raw) ? trim($token_raw) : '';

        $token_type = $get('validation_token_type');
        $payload['token_type'] = is_string($token_type) ? sanitize_text_field($token_type) : '';

        $payload['partner_id'] = is_string($get('partner_id')) ? sanitize_text_field($get('partner_id')) : '';
        $payload['external_reference'] = is_string($get('external_reference')) ? sanitize_text_field($get('external_reference')) : '';
        $payload['email'] = is_string($get('email')) ? sanitize_email($get('email')) : '';
        $payload['name'] = is_string($get('name')) ? sanitize_text_field($get('name')) : '';
        $payload['phone'] = is_string($get('phone')) ? sanitize_text_field($get('phone')) : '';

        return $payload;
    }

    /**
     * Normalize a token payload with partner-aware strategy references (no validation performed).
     */
    public function normalize_token_payload($partner_id, $request_data) {
        $strategy = $this->get_validation_token_strategy($partner_id);
        $external_ref = $this->get_external_reference_mapping($partner_id);

        $payload = $this->extract_validation_token_from_request($request_data);
        $payload['token_strategy'] = $strategy;

        if ($payload['external_reference'] === '' && $external_ref !== '') {
            $payload['external_reference'] = $external_ref;
        }

        return $payload;
    }
}
