<?php

if (!defined('ABSPATH')) {
    exit;
}

class SOS_PBL_Handoff_Service {
    /** @var SOS_PBL_Config */
    private $config;

    /** @var SOS_PBL_Central_Client */
    private $client;

    public function __construct(SOS_PBL_Config $config, SOS_PBL_Central_Client $client) {
        $this->config = $config;
        $this->client = $client;
    }

    public function build_handoff_payload($email = '', $return_url = '') {
        $settings = $this->config->get();

        $partner_id = (string) ($settings['partner_id'] ?? '');
        $payload_email = sanitize_email((string) $email);
        $timestamp = time();
        $nonce = wp_generate_password(12, false, false);

        $private_key_path = trim((string) ($settings['private_key_path'] ?? ''));
        $resolved_private_key_path = $this->resolve_private_key_path($private_key_path);
        if ($resolved_private_key_path === '') {
            if ($this->config->is_debug_enabled()) {
                error_log('SOS_PBL: private_key_path invalid for handoff signing');
            }
            return new WP_Error('sos_pbl_private_key_invalid', 'Percorso chiave privata non valido');
        }

        if ($this->config->is_debug_enabled()) {
            error_log('SOS_PBL: private_key_path valid=' . $resolved_private_key_path);
        }

        $pem_contents = file_get_contents($resolved_private_key_path);
        if ($pem_contents === false || $pem_contents === '') {
            if ($this->config->is_debug_enabled()) {
                error_log('SOS_PBL: private key file unreadable for signing');
            }
            return new WP_Error('sos_pbl_private_key_unreadable', 'Chiave privata non leggibile');
        }

        if (!function_exists('openssl_pkey_get_private') || !function_exists('openssl_sign')) {
            if ($this->config->is_debug_enabled()) {
                error_log('SOS_PBL: OpenSSL extension not available for signing');
            }
            return new WP_Error('sos_pbl_openssl_missing', 'OpenSSL non disponibile sul server');
        }

        $private_key = openssl_pkey_get_private($pem_contents);
        if ($private_key === false) {
            if ($this->config->is_debug_enabled()) {
                error_log('SOS_PBL: openssl_pkey_get_private failed');
            }
            return new WP_Error('sos_pbl_private_key_parse_error', 'Chiave privata non valida');
        }

        // Central contract: partner_id|payload(email)|timestamp|nonce
        $message_to_sign = $partner_id . '|' . $payload_email . '|' . $timestamp . '|' . $nonce;

        $raw_signature = '';
        $algorithm = OPENSSL_ALGO_SHA256;
        $ok = openssl_sign($message_to_sign, $raw_signature, $private_key, $algorithm);
        if (function_exists('openssl_free_key')) {
            openssl_free_key($private_key);
        }

        if (!$ok || $raw_signature === '') {
            if ($this->config->is_debug_enabled()) {
                error_log('SOS_PBL: openssl_sign failed with algorithm=OPENSSL_ALGO_SHA256');
            }
            return new WP_Error('sos_pbl_sign_failed', 'Firma handoff non riuscita');
        }

        $signature_b64 = base64_encode($raw_signature);

        $payload = [
            'partner_id' => $partner_id,
            'payload' => $payload_email,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => $signature_b64,
        ];

        if ($return_url !== '') {
            $payload['return_url'] = esc_url_raw((string) $return_url);
        }

        if ($this->config->is_debug_enabled()) {
            error_log('[SOS SSO] partner handoff payload built partner_id=' . $partner_id . ' return_url=' . ($return_url !== '' ? esc_url_raw((string) $return_url) : ''));
            error_log('SOS_PBL: handoff signing algorithm=OPENSSL_ALGO_SHA256');
            error_log('SOS_PBL: handoff signed_message=' . $message_to_sign);
            error_log('SOS_PBL: handoff signature generated=***present***');
        }

        return $payload;
    }

    public function send_handoff_request(array $payload) {
        $settings = $this->config->get();
        $path = (string) ($settings['handoff_endpoint_path'] ?? '/partner-login/');

        if ($this->config->is_debug_enabled()) {
            $payload_log = $payload;
            if (isset($payload_log['signature'])) {
                $payload_log['signature'] = $payload_log['signature'] !== '' ? '***present***' : '***missing***';
            }
            error_log('SOS_PBL: handoff request method=POST transport=form endpoint=' . $path);
            error_log('SOS_PBL: handoff request payload=' . wp_json_encode($payload_log));
            error_log('SOS_PBL: handoff request headers=[Content-Type, X-SOS-Partner-ID, X-SOS-Partner-Token]');
        }

        return $this->client->post_form($path, $payload);
    }

    private function resolve_private_key_path($input_path) {
        $path = trim((string) $input_path);
        if ($path === '') {
            return '';
        }

        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            return '';
        }

        return (string) $resolved;
    }
}
