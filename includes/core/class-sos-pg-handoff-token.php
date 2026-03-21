<?php
if (!defined('ABSPATH')) exit;

class SOS_PG_Handoff_Token {
    private $secret;
    private $ttl_seconds;

    public function __construct($secret = '', $ttl_seconds = 300) {
        $this->secret = $secret !== '' ? (string) $secret : $this->default_secret();
        $this->ttl_seconds = max(60, (int) $ttl_seconds);
    }

    public function issue($user_id, $email, $partner_id) {
        $now = time();
        $payload = [
            'user_id' => (int) $user_id,
            'email' => (string) $email,
            'partner_id' => (string) $partner_id,
            'issued_at' => $now,
            'expires_at' => $now + $this->ttl_seconds,
        ];

        $body = $this->base64url_encode(wp_json_encode($payload));
        $signature = $this->sign($body);

        return [
            'token' => $body . '.' . $signature,
            'expires_at' => $payload['expires_at'],
        ];
    }

    public function verify($token) {
        if (!is_string($token) || strpos($token, '.') === false) {
            return new WP_Error('sos_pg_handoff_invalid', 'Token non valido');
        }

        list($body, $signature) = explode('.', $token, 2);

        if (!$body || !$signature) {
            return new WP_Error('sos_pg_handoff_invalid', 'Token non valido');
        }

        $expected = $this->sign($body);
        if (!hash_equals($expected, $signature)) {
            return new WP_Error('sos_pg_handoff_invalid', 'Token non valido');
        }

        $json = $this->base64url_decode($body);
        $payload = json_decode($json, true);

        if (!is_array($payload)) {
            return new WP_Error('sos_pg_handoff_invalid', 'Token non valido');
        }

        $required = ['user_id', 'email', 'partner_id', 'issued_at', 'expires_at'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $payload)) {
                return new WP_Error('sos_pg_handoff_invalid', 'Token non valido');
            }
        }

        $now = time();
        if ((int) $payload['expires_at'] < $now) {
            return new WP_Error('sos_pg_handoff_expired', 'Token scaduto');
        }

        return [
            'user_id' => (int) $payload['user_id'],
            'email' => (string) $payload['email'],
            'partner_id' => (string) $payload['partner_id'],
            'issued_at' => (int) $payload['issued_at'],
            'expires_at' => (int) $payload['expires_at'],
        ];
    }

    private function default_secret() {
        if (function_exists('wp_salt')) {
            $salt = wp_salt('auth');
        } else {
            $parts = [
                defined('AUTH_KEY') ? AUTH_KEY : '',
                defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '',
                defined('LOGGED_IN_KEY') ? LOGGED_IN_KEY : '',
                defined('NONCE_KEY') ? NONCE_KEY : '',
                defined('AUTH_SALT') ? AUTH_SALT : '',
                defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '',
                defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : '',
                defined('NONCE_SALT') ? NONCE_SALT : '',
            ];
            $salt = implode('|', $parts);

            if ($salt === '') {
                $salt = php_uname();
            }
        }

        return hash('sha256', 'sos_pg_handoff|' . $salt);
    }

    private function sign($body) {
        return hash_hmac('sha256', $body, $this->secret);
    }

    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64url_decode($data) {
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
