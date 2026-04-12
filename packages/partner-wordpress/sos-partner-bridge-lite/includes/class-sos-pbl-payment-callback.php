<?php

if (!defined('ABSPATH')) {
    exit;
}

class SOS_PBL_Payment_Callback {
    /** @var SOS_PBL_Config */
    private $config;

    /** @var SOS_PBL_Central_Client */
    private $client;

    public function __construct(SOS_PBL_Config $config, SOS_PBL_Central_Client $client) {
        $this->config = $config;
        $this->client = $client;
    }

    public function build_payment_payload($booking_id, $transaction_id, $amount = null, array $extra = []) {
        $settings = $this->config->get();

        $payload = [
            'partner_id' => sanitize_text_field((string) ($extra['partner_id'] ?? $settings['partner_id'] ?? '')),
            'booking_id' => absint($booking_id),
            'transaction_id' => sanitize_text_field((string) $transaction_id),
        ];

        if ($amount !== null) {
            $payload['amount_paid'] = (float) $amount;
        }

        $optional_fields = [
            'currency',
            'payment_provider',
            'external_reference',
            'email',
            'status',
        ];

        foreach ($optional_fields as $field) {
            if (!array_key_exists($field, $extra) || $extra[$field] === null || $extra[$field] === '') {
                continue;
            }

            if ($field === 'email') {
                $payload[$field] = sanitize_email((string) $extra[$field]);
                continue;
            }

            $payload[$field] = sanitize_text_field((string) $extra[$field]);
        }

        return $payload;
    }

    public function send_payment_callback(array $payload) {
        $settings = $this->config->get();
        $base_url = trim((string) ($settings['central_base_url'] ?? ''));
        $shared_secret = (string) ($settings['shared_secret'] ?? '');
        $callback_path = trim((string) ($settings['payment_callback_path'] ?? ''));

        if ($base_url === '') {
            return new WP_Error('sos_pbl_missing_central_base_url', 'central_base_url non configurato');
        }

        if ($shared_secret === '') {
            return new WP_Error('sos_pbl_missing_shared_secret', 'shared_secret non configurato');
        }

        if ($callback_path === '') {
            $callback_path = '/partner-payment-callback/';
        }

        $url = rtrim($base_url, '/') . '/' . ltrim($callback_path, '/');
        $body = (string) wp_json_encode($payload);
        $signature = hash_hmac('sha256', $body, $shared_secret);

        return wp_remote_post($url, [
            'timeout' => 10,
            'redirection' => 3,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-SOSPG-Signature' => $signature,
            ],
            'body' => $body,
        ]);
    }
}
