<?php
/**
 * Plugin Name: SOS Partner Bridge Lite
 * Description: Lightweight companion plugin for WordPress partners integrating with SOS central gateway.
 * Version: 0.1.0
 * Author: VB
 * Text Domain: sos-partner-bridge-lite
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SOS_PBL_FILE', __FILE__);
define('SOS_PBL_DIR', plugin_dir_path(__FILE__));
define('SOS_PBL_VERSION', '0.1.0');

require_once SOS_PBL_DIR . 'includes/class-sos-pbl-config.php';
require_once SOS_PBL_DIR . 'includes/class-sos-pbl-central-client.php';
require_once SOS_PBL_DIR . 'includes/class-sos-pbl-settings-page.php';
require_once SOS_PBL_DIR . 'includes/class-sos-pbl-handoff-service.php';
require_once SOS_PBL_DIR . 'includes/class-sos-pbl-payment-callback.php';
require_once SOS_PBL_DIR . 'includes/class-sos-pbl-fake-payment-gateway.php';
require_once SOS_PBL_DIR . 'includes/class-sos-pbl-plugin.php';

if (!function_exists('sos_pg_get_fake_gateway_url')) {
    function sos_pg_get_fake_gateway_url($payload) {
        $payload = is_array($payload) ? $payload : [];

        return add_query_arg(
            array_merge($payload, ['fake_payment' => '1']),
            site_url('/fake-payment-gateway/')
        );
    }
}

add_action('sos_fake_payment_success', function($data) {
    error_log('FAKE_PAYMENT_SUCCESS: ' . wp_json_encode($data));

    $GLOBALS['sos_pbl_fake_payment_last_result'] = [
        'ok' => false,
        'message' => 'Callback fake non eseguita.',
    ];

    $booking_id = isset($data['booking_id']) ? absint($data['booking_id']) : 0;

    if (!$booking_id) {
        error_log('FAKE_PAYMENT_CALLBACK_ERROR: missing booking_id');
        $GLOBALS['sos_pbl_fake_payment_last_result'] = [
            'ok' => false,
            'message' => 'Callback fake non eseguita: booking_id mancante.',
        ];
        return;
    }

    $config = new SOS_PBL_Config();
    $client = new SOS_PBL_Central_Client($config);
    $payment_callback = new SOS_PBL_Payment_Callback($config, $client);

    $transaction_id = isset($data['transaction_id']) && (string) $data['transaction_id'] !== ''
        ? sanitize_text_field((string) $data['transaction_id'])
        : 'fake-gateway-' . $booking_id . '-' . time();

    $amount_paid = null;
    foreach (['amount_paid', 'amount', 'total', 'partner_charge'] as $amount_key) {
        if (!isset($data[$amount_key]) || $data[$amount_key] === '') {
            continue;
        }
        $amount_paid = (float) $data[$amount_key];
        break;
    }

    $payload = $payment_callback->build_payment_payload($booking_id, $transaction_id, $amount_paid, [
        'partner_id' => isset($data['partner_id']) ? (string) $data['partner_id'] : '',
        'currency' => isset($data['currency']) ? (string) $data['currency'] : 'EUR',
        'payment_provider' => 'fake_gateway',
        'external_reference' => isset($data['external_reference']) ? (string) $data['external_reference'] : '',
        'email' => isset($data['email']) ? (string) $data['email'] : '',
    ]);

    error_log('FAKE_PAYMENT_CALLBACK_SENT: ' . wp_json_encode($payload));
    $response = $payment_callback->send_payment_callback($payload);

    if (is_wp_error($response)) {
        error_log('FAKE_PAYMENT_CALLBACK_ERROR: ' . $response->get_error_message());
        $GLOBALS['sos_pbl_fake_payment_last_result'] = [
            'ok' => false,
            'message' => 'Callback fake fallita: ' . $response->get_error_message(),
        ];
        return;
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);

    if ($status >= 200 && $status < 300) {
        error_log('FAKE_PAYMENT_CALLBACK_OK: ' . wp_json_encode([
            'booking_id' => $booking_id,
            'status' => $status,
            'body' => $body,
        ]));
        $GLOBALS['sos_pbl_fake_payment_last_result'] = [
            'ok' => true,
            'message' => 'Callback fake completata: booking aggiornato dal centrale.',
        ];
        do_action('sos_booking_paid', $booking_id);
        return;
    }

    error_log('FAKE_PAYMENT_CALLBACK_ERROR: ' . wp_json_encode([
        'booking_id' => $booking_id,
        'status' => $status,
        'body' => $body,
    ]));
    $GLOBALS['sos_pbl_fake_payment_last_result'] = [
        'ok' => false,
        'message' => 'Callback fake rifiutata dal centrale. HTTP ' . $status . ($body !== '' ? ' - ' . $body : ''),
    ];
});

SOS_PBL_Fake_Payment_Gateway::instance();
SOS_PBL_Plugin::instance();
