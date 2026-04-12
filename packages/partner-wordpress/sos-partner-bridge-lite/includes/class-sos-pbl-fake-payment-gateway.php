<?php

if (!defined('ABSPATH')) {
    exit;
}

class SOS_PBL_Fake_Payment_Gateway {
    private static $instance = null;
    private $route_path = '/fake-payment-gateway';
    private $nonce_action = 'sos_pbl_fake_payment_submit';
    private $control_keys = [
        '_wpnonce',
        '_wp_http_referer',
        'fake_payment',
        'fake_payment_status',
        'sos_pbl_fake_payment_submit',
    ];

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_shortcode('fake_payment_gateway', [$this, 'render_shortcode']);
        add_action('template_redirect', [$this, 'handle_virtual_page']);
    }

    public function render_shortcode() {
        if (!$this->is_enabled()) {
            return '';
        }

        return $this->render_gateway_markup();
    }

    public function handle_virtual_page() {
        if (!$this->is_enabled() || !$this->is_virtual_gateway_request()) {
            return;
        }

        status_header(200);
        nocache_headers();

        echo '<!DOCTYPE html><html lang="it"><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Fake Payment Gateway</title>';
        echo '</head><body style="margin:0;background:#f5f7fb;color:#1f2937;font-family:Segoe UI,Arial,sans-serif;">';
        echo $this->render_gateway_markup();
        echo '</body></html>';
        exit;
    }

    private function render_gateway_markup() {
        $notice = $this->maybe_handle_submission();
        $payload = $this->get_visible_payload();
        // TEMP DEBUG CAF
        error_log('SOS_PBL_FAKE_GATEWAY_RENDER_DEBUG ' . wp_json_encode([
            'request_url' => $this->current_request_url(),
            'get' => $_GET,
            'booking_id' => isset($_GET['booking_id']) ? sanitize_text_field((string) wp_unslash($_GET['booking_id'])) : '',
            'external_reference' => isset($_GET['external_reference']) ? sanitize_text_field((string) wp_unslash($_GET['external_reference'])) : '',
        ]));
        error_log('SOS_PBL_FAKE_GATEWAY_URL_PAYLOAD ' . wp_json_encode($_GET));
        error_log('SOS_PBL_FAKE_GATEWAY_URL_PAYLOAD: ' . wp_json_encode([
            'request_url' => $this->current_request_url(),
            'payload' => $payload,
        ]));
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            $json = '{}';
        }

        $form_action = $this->get_form_action_url();
        $html = '<div class="sos-pbl-fake-payment" style="max-width:960px;margin:40px auto;padding:24px;">';
        $html .= '<div style="background:#ffffff;border:1px solid #dbe2ea;border-radius:16px;box-shadow:0 18px 45px rgba(15,23,42,0.08);padding:28px;">';
        $html .= '<h1 style="margin:0 0 20px;font-size:32px;line-height:1.15;">Fake Payment Gateway</h1>';
        if ($notice !== '') {
            $html .= '<div style="margin-bottom:18px;padding:12px 14px;border-radius:10px;background:#ecfdf3;color:#166534;border:1px solid #bbf7d0;">' . esc_html($notice) . '</div>';
        }
        $html .= '<div style="margin-bottom:18px;padding:16px;border-radius:12px;background:#0f172a;color:#e2e8f0;overflow:auto;">';
        $html .= '<div style="margin-bottom:10px;font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#93c5fd;">Payload</div>';
        $html .= '<pre style="margin:0;white-space:pre-wrap;word-break:break-word;font-size:14px;line-height:1.5;">' . esc_html($json) . '</pre>';
        $html .= '</div>';
        $html .= '<form method="post" action="' . esc_url($form_action) . '">';
        $html .= wp_nonce_field($this->nonce_action, '_wpnonce', true, false);
        $html .= '<input type="hidden" name="fake_payment_status" value="success">';
        foreach ($payload as $key => $value) {
            $html .= $this->render_hidden_inputs($key, $value);
        }
        $html .= '<button type="submit" style="display:inline-flex;align-items:center;justify-content:center;min-width:180px;padding:14px 22px;border:0;border-radius:999px;background:#16a34a;color:#ffffff;font-size:16px;font-weight:700;cursor:pointer;">OK PAGATO</button>';
        $html .= '</form>';
        $html .= '</div></div>';

        return $html;
    }

    private function maybe_handle_submission() {
        $status = isset($_POST['fake_payment_status']) ? sanitize_text_field((string) wp_unslash($_POST['fake_payment_status'])) : '';
        if ($status !== 'success' || !$this->is_enabled()) {
            return '';
        }

        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field((string) wp_unslash($_POST['_wpnonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, $this->nonce_action)) {
            return 'Richiesta fake payment non valida.';
        }

        $data = $this->get_visible_payload();
        do_action('sos_fake_payment_success', $data);

        $result = isset($GLOBALS['sos_pbl_fake_payment_last_result']) && is_array($GLOBALS['sos_pbl_fake_payment_last_result'])
            ? $GLOBALS['sos_pbl_fake_payment_last_result']
            : [];

        if (!empty($result['ok'])) {
            return 'Pagamento simulato completato. Callback fake inviata con successo al centrale.';
        }

        if (!empty($result['message'])) {
            return (string) $result['message'];
        }

        return 'Pagamento simulato completato.';
    }

    private function get_visible_payload() {
        return $this->strip_control_keys(
            array_merge($this->sanitize_input_array($_GET), $this->sanitize_input_array($_POST))
        );
    }

    private function sanitize_input_array($source) {
        if (!is_array($source)) {
            return [];
        }

        $clean = [];
        foreach ($source as $key => $value) {
            $clean_key = sanitize_key((string) $key);
            if ($clean_key === '') {
                continue;
            }
            $clean[$clean_key] = $this->sanitize_input_value($value);
        }

        return $clean;
    }

    private function sanitize_input_value($value) {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $child_key => $child_value) {
                $normalized_key = is_string($child_key) ? sanitize_key($child_key) : $child_key;
                $clean[$normalized_key] = $this->sanitize_input_value($child_value);
            }

            return $clean;
        }

        return sanitize_text_field((string) wp_unslash($value));
    }

    private function strip_control_keys(array $payload) {
        foreach ($this->control_keys as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    private function render_hidden_inputs($name, $value) {
        if (is_array($value)) {
            $html = '';
            foreach ($value as $child_key => $child_value) {
                $field_name = $name . '[' . $child_key . ']';
                $html .= $this->render_hidden_inputs($field_name, $child_value);
            }

            return $html;
        }

        return '<input type="hidden" name="' . esc_attr((string) $name) . '" value="' . esc_attr((string) $value) . '">';
    }

    private function is_enabled() {
        return (defined('WP_DEBUG') && WP_DEBUG) || $this->is_fake_payment_query_enabled();
    }

    private function is_fake_payment_query_enabled() {
        $flag = isset($_REQUEST['fake_payment']) ? sanitize_text_field((string) wp_unslash($_REQUEST['fake_payment'])) : '';
        return $flag === '1';
    }

    private function is_virtual_gateway_request() {
        return $this->normalize_path($this->current_request_path()) === $this->route_path;
    }

    private function get_form_action_url() {
        $url = $this->current_request_url();
        $url = remove_query_arg(['fake_payment_status', '_wpnonce'], $url);

        if ($this->is_fake_payment_query_enabled()) {
            $url = add_query_arg('fake_payment', '1', $url);
        }

        return $url;
    }

    private function current_request_url() {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_HOST'])) : '';
        if ($host === '') {
            return home_url('/');
        }

        return (is_ssl() ? 'https://' : 'http://') . $host . $request_uri;
    }

    private function current_request_path() {
        return (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    }

    private function normalize_path($path) {
        $path = '/' . ltrim((string) $path, '/');
        return untrailingslashit($path) ?: '/';
    }
}