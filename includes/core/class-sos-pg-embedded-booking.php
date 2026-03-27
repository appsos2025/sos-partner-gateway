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

    public function verify_normalized_token($partner_id, $normalized_payload) {
        $result = [
            'ok' => false,
            'strategy' => (string) ($normalized_payload['token_strategy'] ?? ''),
            'token_present' => ((string) ($normalized_payload['token_raw'] ?? '') !== ''),
            'token_type' => (string) ($normalized_payload['token_type'] ?? ''),
            'partner_id' => (string) ($normalized_payload['partner_id'] ?? ''),
            'external_reference' => (string) ($normalized_payload['external_reference'] ?? ''),
            'claims' => [],
            'errors' => [],
        ];

        $strategy = $result['strategy'];

        if ($strategy === '') {
            $result['errors'][] = 'strategy_empty';
            return $result;
        }

        if (!$result['token_present']) {
            $result['errors'][] = 'token_missing';
            return $result;
        }

        // Minimal scaffolded strategies
        if ($strategy === 'passthrough') {
            $result['ok'] = true;
            return $result;
        }

        if ($strategy === 'opaque') {
            // Token present but not validated; keep ok=false, note unsupported.
            $result['errors'][] = 'opaque_unverified';
            return $result;
        }

        if ($strategy === 'jwt_rs256') {
            $token = (string) ($normalized_payload['token_raw'] ?? '');
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                $result['errors'][] = 'jwt_malformed';
                return $result;
            }

            list($h64, $p64, $s64) = $parts;
            $header_json = $this->base64url_decode_safe($h64);
            $payload_json = $this->base64url_decode_safe($p64);
            $signature = $this->base64url_decode_safe($s64, true);

            if ($header_json === null || $payload_json === null || $signature === null) {
                $result['errors'][] = 'jwt_decode_error';
                return $result;
            }

            $header = json_decode($header_json, true);
            $payload = json_decode($payload_json, true);

            if (!is_array($header) || !is_array($payload)) {
                $result['errors'][] = 'jwt_json_error';
                return $result;
            }

            $alg = isset($header['alg']) ? (string) $header['alg'] : '';
            if (strtoupper($alg) !== 'RS256') {
                $result['errors'][] = 'jwt_alg_mismatch';
                return $result;
            }

            $cfg = $this->registry ? $this->registry->get_partner_config($partner_id) : null;
            $pem = $cfg && !empty($cfg['public_key_pem']) ? trim((string) $cfg['public_key_pem']) : '';
            if ($pem === '') {
                $result['errors'][] = 'partner_key_missing';
                return $result;
            }

            $pub = openssl_pkey_get_public($pem);
            if (!$pub) {
                $result['errors'][] = 'partner_key_invalid';
                return $result;
            }

            $data = $h64 . '.' . $p64;
            $ok = openssl_verify($data, $signature, $pub, OPENSSL_ALGO_SHA256);
            openssl_free_key($pub);

            if ($ok !== 1) {
                $result['errors'][] = 'jwt_signature_invalid';
                return $result;
            }

            $result['ok'] = true;
            $result['claims'] = $this->filter_claims($payload);
            return $result;
        }

        $result['errors'][] = 'strategy_unsupported';
        return $result;
    }

    /**
     * Basic identity validation for embedded booking payloads.
     * Ensures email is present/valid; normalizes standard fields (email, first_name, last_name, phone, external_reference, validation_token)
     * and keeps backward compatibility with legacy `name`.
     */
    public function validate_identity_payload($request_data) {
        $get = function($key) use ($request_data) {
            if (is_array($request_data) && isset($request_data[$key])) {
                return $request_data[$key];
            }
            if (is_object($request_data) && method_exists($request_data, 'get_param')) {
                return $request_data->get_param($key);
            }
            return null;
        };

        $email_raw = $get('email');
        $name_raw = $get('name');
        $first_raw = $get('first_name');
        $last_raw = $get('last_name');
        $phone_raw = $get('phone');
        $ext_ref_raw = $get('external_reference');
        $validation_token_raw = $get('validation_token');

        $email = is_string($email_raw) ? sanitize_email($email_raw) : '';
        $name = is_string($name_raw) ? sanitize_text_field($name_raw) : '';
        $first_name = is_string($first_raw) ? sanitize_text_field($first_raw) : '';
        $last_name = is_string($last_raw) ? sanitize_text_field($last_raw) : '';
        $phone = is_string($phone_raw) ? sanitize_text_field($phone_raw) : '';
        $external_reference = is_string($ext_ref_raw) ? sanitize_text_field($ext_ref_raw) : '';
        $validation_token = is_string($validation_token_raw) ? sanitize_text_field($validation_token_raw) : '';

        $customer_name = trim(($first_name . ' ' . $last_name));
        if ($customer_name === '' && $name !== '') {
            $customer_name = $name;
            if ($first_name === '') {
                $first_name = $customer_name; // fallback legacy name into first_name for compatibility
            }
        }

        $errors = [];
        if ($email === '' || !is_email($email)) {
            $errors[] = 'email_invalid';
        }

        return [
            'ok' => empty($errors),
            'errors' => $errors,
            'identity' => [
                'email' => $email,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'customer_name' => $customer_name,
                'phone' => $phone,
                'external_reference' => $external_reference,
                'validation_token' => $validation_token,
            ],
        ];
    }

    public function verify_token_payload($partner_id, $request_data) {
        $normalized = $this->normalize_token_payload($partner_id, $request_data);
        return $this->verify_normalized_token($partner_id, $normalized);
    }

    private function base64url_decode_safe($data, $binary = false) {
        $data = strtr((string) $data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return null;
        }
        return $binary ? $decoded : $decoded;
    }

    private function filter_claims(array $claims) {
        $safe = [];
        foreach ($claims as $k => $v) {
            if (is_scalar($v) || is_null($v)) {
                $safe[$k] = $v;
            }
        }
        return $safe;
    }
}
