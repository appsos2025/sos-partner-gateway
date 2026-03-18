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

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
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
            'partner_id' => 'hf',
            'email' => 'test@example.com',
            'private_key_pem' => '',
            'private_key_path' => '',
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
        echo '</table>';

        submit_button('Salva configurazione');
        echo '</form>';

        echo '<hr style="margin:24px 0;">';

        echo '<form method="post">';
        wp_nonce_field('sos_pg_tester_send');
        echo '<input type="hidden" name="sos_pg_tester_action" value="send">';
        submit_button('Invia login di test', 'primary', 'submit', false);
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
}

new SOS_PG_Partner_Login_Tester();
