<?php
/**
 * Plugin Name: Partner Login Tester
 * Description: Utility per simulare il login partner firmato verso un endpoint esterno (es. /partner-login/ su altro dominio).
 * Version: 0.1.0
 * Author: OpenAI
 */

if (!defined('ABSPATH')) {
    exit;
}

class SOS_PG_Partner_Login_Tester {
    private $option_key = 'sos_pg_tester_settings';
    private $last_webhook_key = 'sos_pg_tester_last_webhook';

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('init', [$this, 'handle_webhook_listener']);
    }

    public function admin_menu() {
        add_menu_page(
            'Partner Login Tester',
            'Partner Login Tester',
            'manage_options',
            'sos-pg-partner-tester',
            [$this, 'render_page'],
            'dashicons-admin-generic',
            59
        );
    }

    private function get_settings() {
        $defaults = [
            'endpoint_url' => '',
            'partner_id' => '',
            'email' => '',
            'private_key_pem' => '',
            'private_key_path' => '',
            'webhook_secret' => '',
            'payment_callback_url' => '',
            'payment_callback_secret' => '',
        ];
        $stored = get_option($this->option_key, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return wp_parse_args($stored, $defaults);
    }

    private function save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }
        check_admin_referer('sos_pg_tester_save');

        $settings = [
            'endpoint_url' => esc_url_raw(trim((string) ($_POST['endpoint_url'] ?? ''))),
            'partner_id' => sanitize_text_field($_POST['partner_id'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'private_key_pem' => trim((string) ($_POST['private_key_pem'] ?? '')),
            'private_key_path' => trim((string) ($_POST['private_key_path'] ?? '')),
            'webhook_secret' => sanitize_text_field($_POST['webhook_secret'] ?? ''),
            'payment_callback_url' => esc_url_raw(trim((string) ($_POST['payment_callback_url'] ?? ''))),
            'payment_callback_secret' => sanitize_text_field($_POST['payment_callback_secret'] ?? ''),
        ];

        update_option($this->option_key, $settings);
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        if (isset($_POST['sos_pg_tester_action']) && $_POST['sos_pg_tester_action'] === 'save') {
            $this->save_settings();
        }

        $settings = $this->get_settings();

        // Se richiesto l'invio di test, costruisce i parametri e mostra un form auto-submit.
        if (isset($_POST['sos_pg_tester_action']) && $_POST['sos_pg_tester_action'] === 'send') {
            check_admin_referer('sos_pg_tester_send');
            $this->render_send_form($settings);
            return;
        }

        if (isset($_POST['sos_pg_tester_action']) && $_POST['sos_pg_tester_action'] === 'pay') {
            check_admin_referer('sos_pg_tester_pay');
            $this->send_payment_callback($settings);
            return;
        }

        echo '<div class="wrap"><h1>Partner Login Tester</h1>';
        echo '<p>Compila endpoint, partner_id, email e chiave privata ECC/PEM per firmare la richiesta. Poi clicca "Invia login di test" per aprire il redirect sul dominio target.</p>';

        echo '<form method="post">';
        wp_nonce_field('sos_pg_tester_save');
        echo '<input type="hidden" name="sos_pg_tester_action" value="save">';

        echo '<table class="form-table">';
        echo '<tr><th>Endpoint login</th><td><input type="url" class="regular-text" name="endpoint_url" value="' . esc_attr($settings['endpoint_url']) . '" placeholder="https://example.com/partner-login/"></td></tr>';
        echo '<tr><th>Partner ID</th><td><input type="text" class="regular-text" name="partner_id" value="' . esc_attr($settings['partner_id']) . '" placeholder="hf"></td></tr>';
        echo '<tr><th>Email payload</th><td><input type="email" class="regular-text" name="email" value="' . esc_attr($settings['email']) . '" placeholder="user@example.com"></td></tr>';
        echo '<tr><th>Chiave privata (PEM)</th><td><textarea name="private_key_pem" rows="10" class="large-text code" placeholder="-----BEGIN PRIVATE KEY-----">' . esc_textarea($settings['private_key_pem']) . '</textarea><p class="description">Incolla il PEM oppure indica un percorso file.</p></td></tr>';
        echo '<tr><th>Percorso file PEM</th><td><input type="text" class="regular-text" name="private_key_path" value="' . esc_attr($settings['private_key_path']) . '" placeholder="/path/to/private.pem"><p class="description">Se valorizzato, viene letto questo file (ha precedenza sul textarea).</p></td></tr>';
        echo '<tr><th>Secret webhook (HMAC)</th><td><input type="text" class="regular-text" name="webhook_secret" value="' . esc_attr($settings['webhook_secret']) . '" placeholder="secret condiviso dal gateway"></td></tr>';
        echo '<tr><th>URL callback pagamento</th><td><input type="url" class="regular-text" name="payment_callback_url" value="' . esc_attr($settings['payment_callback_url']) . '" placeholder="https://videoconsulto.../partner-payment-callback"></td></tr>';
        echo '<tr><th>Secret callback pagamento</th><td><input type="text" class="regular-text" name="payment_callback_secret" value="' . esc_attr($settings['payment_callback_secret']) . '" placeholder="secret condiviso"></td></tr>';
        echo '</table>';

        submit_button('Salva configurazione');
        echo '</form>';

        echo '<hr style="margin:24px 0;">';

        echo '<form method="post">';
        wp_nonce_field('sos_pg_tester_send');
        echo '<input type="hidden" name="sos_pg_tester_action" value="send">';
        submit_button('Invia login di test', 'primary', 'submit', false);
        echo '</form>';

        echo '<hr style="margin:24px 0;">';

        $listener_url = home_url('/?sos_pg_tester_webhook=1');
        $last = get_option($this->last_webhook_key, []);
        $last_booking_id = 0;
        if (!empty($last['body']['booking_id'])) {
            $last_booking_id = (int) $last['body']['booking_id'];
        }

        echo '<h2>Listener webhook (booking_created)</h2>';
        echo '<p>Configura nel gateway questo URL: <code>' . esc_html($listener_url) . '</code></p>';
        echo '<p>Ultimo webhook ricevuto:</p>';
        if ($last) {
            $sig_label = !empty($last['valid_signature'])
                ? '<span style="color:#46b450;">&#10003; firma valida</span>'
                : '<span style="color:#d63638;">&#10007; firma non valida o assente</span>';
            echo '<p>' . $sig_label . ' &mdash; ' . esc_html($last['received_at'] ?? '') . '</p>';
            echo '<pre style="max-height:240px;overflow:auto;background:#f6f6f6;padding:8px;border:1px solid #ddd;">' . esc_html(wp_json_encode($last['body'] ?? $last, JSON_PRETTY_PRINT)) . '</pre>';
            if ($last_booking_id) {
                $last_total = (float) ($last['body']['total'] ?? -1);
                $is_free    = $last_total === 0.0;
                if ($is_free) {
                    // Prenotazione gratuita (sconto 100%) — conferma automaticamente senza pagamento.
                    echo '<div style="margin-top:8px;padding:8px 12px;background:#e8f5e9;border:1px solid #a5d6a7;border-radius:3px;">';
                    echo '<strong>&#10004; Prenotazione gratuita (totale 0 &euro;)</strong> &mdash; Il callback di conferma viene inviato automaticamente senza attendere il pagamento.';
                    echo '</div>';
                    // Auto-submit only once: skip if this webhook was already auto-confirmed.
                    if (empty($last['auto_confirmed'])) {
                        echo '<form id="sosPgAutoFreeForm" method="post" style="display:none;">';
                        wp_nonce_field('sos_pg_tester_pay');
                        echo '<input type="hidden" name="sos_pg_tester_action" value="pay">';
                        echo '<input type="hidden" name="pay_booking_id" value="' . esc_attr($last_booking_id) . '">';
                        echo '<input type="hidden" name="pay_partner_id" value="' . esc_attr($last['body']['partner_id'] ?? $settings['partner_id']) . '">';
                        echo '<input type="hidden" name="pay_tx" value="FREE-' . esc_attr($last_booking_id) . '">';
                        echo '<input type="hidden" name="pay_auto" value="1">';
                        echo '</form>';
                        echo '<script>document.addEventListener("DOMContentLoaded",function(){document.getElementById("sosPgAutoFreeForm").submit();});</script>';
                    } else {
                        echo '<div style="margin-top:4px;padding:4px 12px;background:#f1f8e9;border:1px solid #c5e1a5;border-radius:3px;font-size:.85em;">&#10003; Conferma già inviata.</div>';
                    }
                } else {
                    echo '<div style="margin-top:8px;padding:6px 12px;background:#fff3cd;border:1px solid #ffc107;border-radius:3px;font-size:.85em;">';
                    echo '&#9888; <strong>Solo test interno</strong> &mdash; questo pulsante simula la conferma pagamento. ';
                    echo 'In produzione il partner deve inviare la conferma dalla propria piattaforma.';
                    echo '</div>';
                    echo '<form method="post" style="margin-top:6px;">';
                    wp_nonce_field('sos_pg_tester_pay');
                    echo '<input type="hidden" name="sos_pg_tester_action" value="pay">';
                    echo '<input type="hidden" name="pay_booking_id" value="' . esc_attr($last_booking_id) . '">';
                    echo '<input type="hidden" name="pay_partner_id" value="' . esc_attr($last['body']['partner_id'] ?? $settings['partner_id']) . '">';
                    echo '<input type="hidden" name="pay_tx" value="TEST-' . esc_attr(time()) . '">';
                    echo '<button class="button" type="submit">[TEST] &#10003; Conferma pagamento per prenotazione #' . esc_html($last_booking_id) . '</button>';
                    echo '</form>';
                }
            }
        } else {
            echo '<p>Nessun webhook ricevuto.</p>';
        }

        echo '<hr style="margin:24px 0;">';

        echo '<h2>Invia callback pagamento (manuale)</h2>';
        echo '<p>Usa il modulo manuale per inviare un callback firmato. Se hai ricevuto un webhook con prenotazione gratuita (totale 0 &euro;) la conferma viene inviata automaticamente.</p>';
        echo '<form method="post">';
        wp_nonce_field('sos_pg_tester_pay');
        echo '<input type="hidden" name="sos_pg_tester_action" value="pay">';
        echo '<table class="form-table">';
        echo '<tr><th>Booking ID</th><td><input type="number" name="pay_booking_id" class="regular-text" min="1" value="' . esc_attr($last_booking_id ?: '') . '" required></td></tr>';
        echo '<tr><th>Partner ID</th><td><input type="text" name="pay_partner_id" class="regular-text" value="' . esc_attr($settings['partner_id']) . '" placeholder="partner_id"></td></tr>';
        echo '<tr><th>Status (opzionale)</th><td><input type="text" name="pay_status" class="regular-text" placeholder="lascia vuoto per usare il default del gateway"></td></tr>';
        echo '<tr><th>Transaction ID</th><td><input type="text" name="pay_tx" class="regular-text" value="TEST-' . esc_attr(time()) . '"></td></tr>';
        echo '<tr><th>Importo (facoltativo)</th><td><input type="number" step="0.01" name="pay_amount" class="regular-text" placeholder="1.00"><p class="description">Facoltativo, per debug; il gateway attuale ignora l\'importo.</p></td></tr>';
        echo '</table>';
        submit_button('Invia callback pagamento');
        echo '</form>';

        echo '</div>';
    }

    private function render_send_form($settings) {
        $endpoint = $settings['endpoint_url'];
        $partner_id = $settings['partner_id'];
        $email = $settings['email'];
        $pem = $settings['private_key_pem'];
        $pem_path = $settings['private_key_path'];

        if ($pem_path !== '' && file_exists($pem_path)) {
            $file_contents = file_get_contents($pem_path);
            if ($file_contents !== false && trim($file_contents) !== '') {
                $pem = $file_contents;
            }
        }

        if ($endpoint === '' || $partner_id === '' || $email === '' || $pem === '') {
            echo '<div class="notice notice-error"><p>Compila endpoint, partner_id, email e chiave privata.</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=sos-pg-partner-tester')) . '" class="button">Torna alla configurazione</a></p>';
            return;
        }

        $private_key = openssl_pkey_get_private($pem);
        if (!$private_key) {
            echo '<div class="notice notice-error"><p>Chiave privata non valida.</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=sos-pg-partner-tester')) . '" class="button">Torna alla configurazione</a></p>';
            return;
        }

        $timestamp = time();
        $nonce = wp_generate_password(12, false, false);
        $message = $partner_id . '|' . $email . '|' . $timestamp . '|' . $nonce;

        $signature = '';
        $ok = openssl_sign($message, $signature, $private_key, OPENSSL_ALGO_SHA256);
        openssl_free_key($private_key);

        if (!$ok) {
            echo '<div class="notice notice-error"><p>Impossibile firmare la richiesta.</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=sos-pg-partner-tester')) . '" class="button">Torna alla configurazione</a></p>';
            return;
        }

        $signature_b64 = base64_encode($signature);

        // Form auto-submit per simulare il partner.
        echo '<div class="wrap"><h1>Invio login di test</h1>';
        echo '<p>Invio in corso verso: ' . esc_html($endpoint) . '</p>';
        echo '<form id="sosPgTesterForm" action="' . esc_url($endpoint) . '" method="POST">';
        echo '<input type="hidden" name="partner_id" value="' . esc_attr($partner_id) . '">';
        echo '<input type="hidden" name="payload" value="' . esc_attr($email) . '">';
        echo '<input type="hidden" name="timestamp" value="' . esc_attr($timestamp) . '">';
        echo '<input type="hidden" name="nonce" value="' . esc_attr($nonce) . '">';
        echo '<input type="hidden" name="signature" value="' . esc_attr($signature_b64) . '">';
        echo '</form>';
        echo '<script>document.getElementById("sosPgTesterForm").submit();</script>';
        echo '</div>';

        // Log minimale lato WP per debug.
        $msg = sprintf('PARTNER TEST SEND | endpoint=%s | partner_id=%s | email=%s | timestamp=%s | nonce=%s', $endpoint, $partner_id, $email, $timestamp, $nonce);
        error_log($msg);
    }

    public function handle_webhook_listener() {
        if (!isset($_GET['sos_pg_tester_webhook'])) {
            return;
        }

        $secret = $this->get_settings()['webhook_secret'];
        $raw = file_get_contents('php://input');
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $sig = $headers['X-SOSPG-Signature'] ?? ($headers['x-sospg-signature'] ?? '');
        $valid = true;

        if ($secret !== '') {
            $calc = hash_hmac('sha256', (string) $raw, (string) $secret);
            $valid = ($sig && hash_equals($calc, $sig));
        }

        $payload = json_decode($raw, true);

        // Se è form-urlencoded, decodifica in array per comodità.
        if (!is_array($payload) && is_string($raw) && $raw !== '') {
            $content_type = strtolower($headers['Content-Type'] ?? ($headers['content-type'] ?? ''));
            if (strpos($content_type, 'application/x-www-form-urlencoded') !== false) {
                $arr = [];
                parse_str($raw, $arr);
                if (is_array($arr) && !empty($arr)) {
                    $payload = $arr;
                }
            }
        }
        $store = [
            'received_at' => current_time('mysql'),
            'valid_signature' => $valid,
            'headers' => $headers,
            'body' => $payload,
            'raw' => $raw,
        ];
        update_option($this->last_webhook_key, $store);

        status_header($valid ? 200 : 401);
        wp_send_json(['ok' => $valid]);
    }

    private function send_payment_callback($settings) {
        $booking_id = absint($_POST['pay_booking_id'] ?? 0);
        $partner_id = sanitize_text_field($_POST['pay_partner_id'] ?? '');
        $status = sanitize_text_field($_POST['pay_status'] ?? '');
        $tx = sanitize_text_field($_POST['pay_tx'] ?? '');
        $amount = sanitize_text_field($_POST['pay_amount'] ?? '');

        $is_auto = !empty($_POST['pay_auto']);
        $back_url    = esc_url(admin_url('admin.php?page=sos-pg-partner-tester'));
        $back_url_js = esc_js(admin_url('admin.php?page=sos-pg-partner-tester'));

        if (!$booking_id) {
            echo '<div class="notice notice-error"><p>Booking ID mancante.</p></div>';
            echo '<p><a href="' . $back_url . '" class="button">Torna</a></p>';
            echo '<script>setTimeout(function(){window.location.replace("' . $back_url_js . '");},2000);</script>';
            return;
        }

        $url = $settings['payment_callback_url'];
        $secret = $settings['payment_callback_secret'];

        if ($url === '' || $secret === '') {
            echo '<div class="notice notice-error"><p>Configura URL e secret callback pagamento.</p></div>';
            echo '<p><a href="' . $back_url . '" class="button">Torna</a></p>';
            echo '<script>setTimeout(function(){window.location.replace("' . $back_url_js . '");},2000);</script>';
            return;
        }

        $payload = [
            'booking_id' => $booking_id,
        ];
        if ($partner_id !== '') {
            $payload['partner_id'] = $partner_id;
        }
        if ($status !== '') {
            $payload['status'] = $status;
        }
        if ($tx !== '') {
            $payload['transaction_id'] = $tx;
        }
        if ($amount !== '') {
            $payload['amount'] = $amount;
        }

        $body = wp_json_encode($payload);
        $headers = [
            'Content-Type' => 'application/json',
            'X-SOSPG-Signature' => hash_hmac('sha256', (string) $body, (string) $secret),
        ];

        $resp = wp_remote_post($url, [
            'headers' => $headers,
            'body' => $body,
            'timeout' => 10,
        ]);

        if (is_wp_error($resp)) {
            echo '<div class="notice notice-error"><p>Errore: ' . esc_html($resp->get_error_message()) . '</p></div>';
            echo '<p><a href="' . $back_url . '" class="button">Torna</a></p>';
            echo '<script>setTimeout(function(){window.location.replace("' . $back_url_js . '");},2000);</script>';
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);

        // Mark the stored webhook as auto-confirmed so the auto-submit form is not
        // rendered again on the next page load (prevents the infinite callback loop).
        if ($is_auto || strpos((string) $tx, 'FREE-') === 0) {
            $last = get_option($this->last_webhook_key, []);
            if (is_array($last)) {
                $last['auto_confirmed'] = true;
                update_option($this->last_webhook_key, $last);
            }
        }

        echo '<div class="notice notice-success"><p>Callback inviata. HTTP ' . esc_html($code) . '.</p></div>';
        echo '<p><a href="' . $back_url . '" class="button">Torna</a></p>';
        // Redirect via JavaScript (Post/Redirect/Get) to prevent browser form re-submission on refresh.
        echo '<script>setTimeout(function(){window.location.replace("' . $back_url_js . '");},1500);</script>';
    }
}

new SOS_PG_Partner_Login_Tester();
