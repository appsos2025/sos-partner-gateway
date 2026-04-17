<?php
if (!defined('ABSPATH')) exit;

class SOS_PG_REST_Router {
    private $plugin;

    public function __construct(SOS_PG_Plugin $plugin) {
        $this->plugin = $plugin;
        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('rest_authentication_errors', [$this, 'allow_anonymous_embedded_booking_create'], PHP_INT_MAX);
    }

    private function is_embedded_booking_create_request() {
        $method = strtoupper(sanitize_text_field((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
        if ($method !== 'POST') {
            return false;
        }

        $target = '/sos-pg/v1/embedded-booking/create';
        $rest_route_qs = sanitize_text_field((string) ($_GET['rest_route'] ?? ''));
        if ($rest_route_qs !== '') {
            $route = '/' . ltrim($rest_route_qs, '/');
            return untrailingslashit($route) === $target;
        }

        $request_path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $prefix = function_exists('rest_get_url_prefix') ? trim((string) rest_get_url_prefix(), '/') : 'wp-json';
        if ($prefix === '') {
            $prefix = 'wp-json';
        }

        $needle = '/' . $prefix . $target;
        return substr(untrailingslashit($request_path), -strlen($needle)) === $needle;
    }

    public function allow_anonymous_embedded_booking_create($result) {

        if (!$this->is_embedded_booking_create_request()) {
            return $result;
        }


        if (!is_wp_error($result)) {
            return $result;
        }

        $code = (string) $result->get_error_code();
        if ($code === 'rest_login_required' || $code === 'rest_not_logged_in') {
            return null;
        }

        return $result;
    }

    public function register_routes() {
        register_rest_route('sos-pg/v1', '/health', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle_health'],
        ]);

        register_rest_route('sos-pg/v1', '/session', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle_session'],
        ]);

        register_rest_route('sos-pg/v1', '/partners/(?P<partner_id>[A-Za-z0-9_-]{1,64})', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle_partner_lookup'],
            'args' => [
                'partner_id' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route('sos-pg/v1', '/handoff/verify', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle_handoff_verify'],
        ]);

        register_rest_route('sos-pg/v1', '/handoff/(?P<partner_id>(?!verify$)[A-Za-z0-9_-]{1,64})', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle_handoff_issue'],
            'args' => [
                'partner_id' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route('sos-pg/v1', '/embedded-booking/debug/(?P<partner_id>[A-Za-z0-9_-]{1,64})', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'callback' => [$this, 'handle_embedded_booking_debug'],
            'args' => [
                'partner_id' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route('sos-pg/v1', '/embedded-booking/verify/(?P<partner_id>[A-Za-z0-9_-]{1,64})', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'callback' => [$this, 'handle_embedded_booking_verify'],
            'args' => [
                'partner_id' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route('sos-pg/v1', '/embedded-booking/create', [
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle_embedded_booking_create'],
            'args' => [
                'partner_id' => [
                    'sanitize_callback' => 'sanitize_text_field',
                    'required' => true,
                ],
            ],
        ]);
    }

    private function ensure_partner_rest_context(WP_REST_Request $request) {
        $route = method_exists($request, 'get_route') ? (string) $request->get_route() : '';
        if ($this->plugin->is_partner_context('rest', ['rest_route' => $route])) {
            return null;
        }

        return new WP_Error('sos_pg_invalid_context', 'Contesto partner non valido', ['status' => 403]);
    }

    public function handle_health(WP_REST_Request $request) {
        $context_error = $this->ensure_partner_rest_context($request);
        if (is_wp_error($context_error)) {
            return $context_error;
        }

        return new WP_REST_Response($this->plugin->get_health_payload());
    }

    public function handle_partner_lookup(WP_REST_Request $request) {
        $context_error = $this->ensure_partner_rest_context($request);
        if (is_wp_error($context_error)) {
            return $context_error;
        }

        $partner_id = sanitize_text_field((string) $request->get_param('partner_id'));
        if ($partner_id === '') {
            return new WP_Error('sos_pg_invalid_partner', 'Partner non valido', ['status' => 404]);
        }

        $registry = $this->plugin->get_partner_registry();
        $config = $registry ? $registry->get_external_api_partner($partner_id) : null;

        if (!$config || ($config['type'] ?? '') !== 'external_api') {
            return new WP_Error('sos_pg_partner_not_found', 'Partner non trovato', ['status' => 404]);
        }

        return new WP_REST_Response([
            'ok' => true,
            'partner_id' => (string) $config['partner_id'],
            'type' => 'external_api',
            'enabled' => !empty($config['enabled']),
            'api_base_url' => isset($config['api_base_url']) ? esc_url_raw((string) $config['api_base_url']) : '',
        ]);
    }

    public function handle_session(WP_REST_Request $request) {
        $context_error = $this->ensure_partner_rest_context($request);
        if (is_wp_error($context_error)) {
            return $context_error;
        }

        if (!is_user_logged_in()) {
            return new WP_REST_Response(['logged_in' => false]);
        }

        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return new WP_REST_Response(['logged_in' => false]);
        }

        $partner_id = sanitize_text_field((string) get_user_meta($user->ID, 'sos_pg_partner_id', true));
        if ($partner_id === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $partner_id)) {
            $partner_id = sanitize_text_field((string) get_user_meta($user->ID, 'partner_id', true));
        }
        if ($partner_id === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $partner_id)) {
            $cookie_pid = sanitize_text_field((string) ($_COOKIE['sos_pg_partner_id'] ?? ''));
            $partner_id = preg_match('/^[A-Za-z0-9_-]{1,64}$/', $cookie_pid) ? $cookie_pid : '';
        }

        return new WP_REST_Response([
            'logged_in' => true,
            'user_id' => (int) $user->ID,
            'email' => (string) $user->user_email,
            'partner_id' => $partner_id,
        ]);
    }

    public function handle_handoff_issue(WP_REST_Request $request) {
        $context_error = $this->ensure_partner_rest_context($request);
        if (is_wp_error($context_error)) {
            return $context_error;
        }

        if (!is_user_logged_in()) {
            return new WP_Error('sos_pg_handoff_forbidden', 'Autenticazione richiesta', ['status' => 403]);
        }

        $partner_id = sanitize_text_field((string) $request->get_param('partner_id'));
        $registry = $this->plugin->get_partner_registry();
        $config = $registry ? $registry->get_external_api_partner($partner_id) : null;

        if (!$config || ($config['type'] ?? '') !== 'external_api' || empty($config['enabled'])) {
            return new WP_Error('sos_pg_partner_not_found', 'Partner non trovato', ['status' => 404]);
        }

        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return new WP_Error('sos_pg_handoff_forbidden', 'Autenticazione richiesta', ['status' => 403]);
        }

        $handoff = $this->plugin->get_handoff_token_service();
        $issued = $handoff->issue($user->ID, $user->user_email, $partner_id);

        return new WP_REST_Response([
            'ok' => true,
            'partner_id' => $partner_id,
            'token' => $issued['token'],
            'expires_at' => (int) $issued['expires_at'],
        ]);
    }

    public function handle_handoff_verify(WP_REST_Request $request) {
        $context_error = $this->ensure_partner_rest_context($request);
        if (is_wp_error($context_error)) {
            return $context_error;
        }

        $token = (string) $request->get_param('token');
        if ($token === '') {
            $auth = (string) $request->get_header('authorization');
            if ($auth && stripos($auth, 'bearer ') === 0) {
                $token = trim(substr($auth, 7));
            }
        }

        if ($token === '') {
            return new WP_Error('sos_pg_handoff_invalid', 'Token mancante', ['status' => 401]);
        }

        $handoff = $this->plugin->get_handoff_token_service();
        $verified = $handoff->verify($token);

        if (is_wp_error($verified)) {
            return new WP_Error('sos_pg_handoff_invalid', 'Token non valido o scaduto', ['status' => 401]);
        }

        // NEW: unified partner config usage
        $registry = $this->plugin->get_partner_registry();
        $config = $registry ? $registry->get_partner_config($verified['partner_id']) : null;

        if (!$config || ($config['type'] ?? '') !== 'external_api' || empty($config['enabled'])) {
            return new WP_Error('sos_pg_handoff_invalid', 'Token non valido o scaduto', ['status' => 401]);
        }

        return new WP_REST_Response([
            'ok' => true,
            'user_id' => (int) $verified['user_id'],
            'email' => (string) $verified['email'],
            'partner_id' => (string) $verified['partner_id'],
            'expires_at' => (int) $verified['expires_at'],
        ]);
    }

    public function handle_embedded_booking_debug(WP_REST_Request $request) {
        $context_error = $this->ensure_partner_rest_context($request);
        if (is_wp_error($context_error)) {
            return $context_error;
        }

        $partner_id = sanitize_text_field((string) $request->get_param('partner_id'));
        if ($partner_id === '') {
            return new WP_Error('sos_pg_invalid_partner', 'Partner non valido', ['status' => 404]);
        }

        $registry = $this->plugin->get_partner_registry();
        $cfg = $registry ? $registry->get_embedded_booking_partner($partner_id) : null;

        if (!$cfg || ($cfg['enabled'] ?? false) === false) {
            return new WP_Error('sos_pg_partner_not_found', 'Partner non trovato o non abilitato', ['status' => 404]);
        }

        if (($cfg['type'] ?? '') !== 'embedded_booking') {
            return new WP_Error('sos_pg_partner_not_embedded', 'Partner non configurato per embedded booking', ['status' => 400]);
        }

        $embedded = $this->plugin->get_embedded_booking_service();
        $normalized = $embedded ? $embedded->normalize_token_payload($partner_id, $request) : [];

        return new WP_REST_Response([
            'ok' => true,
            'partner_id' => $partner_id,
            'token_strategy' => $embedded ? $embedded->get_validation_token_strategy($partner_id) : '',
            'normalized_payload' => $normalized,
        ]);
    }

    public function handle_embedded_booking_verify(WP_REST_Request $request) {
        $context_error = $this->ensure_partner_rest_context($request);
        if (is_wp_error($context_error)) {
            return $context_error;
        }

        $partner_id = sanitize_text_field((string) $request->get_param('partner_id'));
        if ($partner_id === '') {
            return new WP_Error('sos_pg_invalid_partner', 'Partner non valido', ['status' => 404]);
        }

        $registry = $this->plugin->get_partner_registry();
        $cfg = $registry ? $registry->get_embedded_booking_partner($partner_id) : null;

        if (!$cfg || ($cfg['enabled'] ?? false) === false) {
            return new WP_Error('sos_pg_partner_not_found', 'Partner non trovato o non abilitato', ['status' => 404]);
        }

        if (($cfg['type'] ?? '') !== 'embedded_booking') {
            return new WP_Error('sos_pg_partner_not_embedded', 'Partner non configurato per embedded booking', ['status' => 400]);
        }

        $embedded = $this->plugin->get_embedded_booking_service();
        if (!$embedded) {
            return new WP_Error('sos_pg_service_unavailable', 'Servizio non disponibile', ['status' => 500]);
        }

        $normalized = $embedded->normalize_token_payload($partner_id, $request);
        $verification = $embedded->verify_normalized_token($partner_id, $normalized);

        return new WP_REST_Response([
            'ok' => $verification['ok'],
            'partner_id' => $partner_id,
            'strategy' => $verification['strategy'],
            'token_present' => $verification['token_present'],
            'token_type' => $verification['token_type'],
            'external_reference' => $verification['external_reference'],
            'claims' => $verification['claims'],
            'errors' => $verification['errors'],
            'normalized_payload' => $normalized,
        ]);
    }

    public function handle_embedded_booking_create(WP_REST_Request $request) {

        $context_error = $this->ensure_partner_rest_context($request);
        if (is_wp_error($context_error)) {
            return $context_error;
        }

        $partner_id = sanitize_text_field((string) $request->get_param('partner_id'));
        $email_param = sanitize_email((string) $request->get_param('email'));
        $validation_token_param = sanitize_text_field((string) $request->get_param('validation_token'));
        $validation_token_type_param = sanitize_text_field((string) $request->get_param('validation_token_type'));
        error_log('SOS_PG EMBEDDED CREATE request_partner_id=' . $partner_id);
        error_log('[SOS SSO EMBEDDED] central entry partner_id_present=' . ($partner_id !== '' ? '1' : '0') . ' email_present=' . ($email_param !== '' ? '1' : '0') . ' validation_token_present=' . ($validation_token_param !== '' ? '1' : '0') . ' validation_token_type=' . ($validation_token_type_param !== '' ? $validation_token_type_param : ''));
        $ip = sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $rate_limit_max = 10;
        $rate_limit_ttl = 5 * MINUTE_IN_SECONDS;
        $rate_limit_key = 'sos_pg_emb_create_rl_' . md5($ip);
        $rate_count = (int) get_transient($rate_limit_key);
        if ($rate_count >= $rate_limit_max) {
            $rate_limit_payload = [
                'reason' => 'rate_limit_exceeded',
                'ip' => $ip,
                'context' => [
                    'window_seconds' => $rate_limit_ttl,
                    'max_requests' => $rate_limit_max,
                    'current_count' => $rate_count,
                ],
            ];
            if ($partner_id !== '') {
                $rate_limit_payload['partner_id'] = $partner_id;
            }
            if ($email_param !== '') {
                $rate_limit_payload['email'] = $email_param;
            }
            $this->plugin->log_public_event('WARN', 'EMBEDDED_CREATE_RATE_LIMIT', $rate_limit_payload);
            return new WP_Error('sos_pg_rate_limited', 'Troppi tentativi, riprova più tardi', ['status' => 429]);
        }
        set_transient($rate_limit_key, $rate_count + 1, $rate_limit_ttl);

        $log_embedded_fail = function($reason, $error_code, $validation_errors = []) use ($partner_id, $email_param) {
            error_log('[SOS SSO EMBEDDED] central fail error_code=' . (string) $error_code . ' reason=' . (string) $reason . ' partner_id_present=' . ($partner_id !== '' ? '1' : '0') . ' email_present=' . ($email_param !== '' ? '1' : '0') . (!empty($validation_errors) ? ' details=' . wp_json_encode($validation_errors) : ''));
            $payload = [
                'reason' => (string) $reason,
                'context' => [
                    'error_code' => (string) $error_code,
                ],
            ];

            if ($partner_id !== '') {
                $payload['partner_id'] = $partner_id;
            }
            if ($email_param !== '') {
                $payload['email'] = $email_param;
            }
            if (!empty($validation_errors)) {
                $payload['context']['validation_errors'] = $validation_errors;
            }

            $this->plugin->log_public_event('WARN', 'EMBEDDED_CREATE_FAIL', $payload);
        };

        if ($partner_id === '') {
            $log_embedded_fail('Partner non valido', 'sos_pg_invalid_partner');
            return new WP_Error('sos_pg_invalid_partner', 'Partner non valido', ['status' => 404]);
        }

        $registry = $this->plugin->get_partner_registry();
        $cfg_full = $registry ? $registry->get_partner_config($partner_id) : null;
        $normalized_partner_id = is_array($cfg_full) ? (string) ($cfg_full['partner_id'] ?? '') : '';
        $cfg_type = is_array($cfg_full) ? (string) ($cfg_full['type'] ?? '') : '';
        $cfg_enabled = is_array($cfg_full) ? (!empty($cfg_full['enabled']) ? '1' : '0') : 'n/a';
        error_log('SOS_PG EMBEDDED CREATE normalized_partner_id=' . ($normalized_partner_id !== '' ? $normalized_partner_id : $partner_id));
        error_log('SOS_PG EMBEDDED CREATE partner_config_found=' . (is_array($cfg_full) ? '1' : '0') . ' type=' . ($cfg_type !== '' ? $cfg_type : 'n/a') . ' enabled=' . $cfg_enabled);
        $cfg = $registry ? $registry->get_embedded_booking_partner($partner_id) : null;
        if (!$cfg || ($cfg['enabled'] ?? false) === false || ($cfg['type'] ?? '') !== 'embedded_booking') {
            $log_embedded_fail('Partner non configurato per embedded booking', 'sos_pg_partner_not_embedded');
            return new WP_Error('sos_pg_partner_not_embedded', 'Partner non configurato per embedded booking', ['status' => 404]);
        }

        $embedded = $this->plugin->get_embedded_booking_service();
        if (!$embedded) {
            $log_embedded_fail('Servizio non disponibile', 'sos_pg_service_unavailable');
            return new WP_Error('sos_pg_service_unavailable', 'Servizio non disponibile', ['status' => 500]);
        }

        $normalized = $embedded->normalize_token_payload($partner_id, $request);
        $verification = $embedded->verify_normalized_token($partner_id, $normalized);
        error_log('[SOS SSO EMBEDDED] central verification strategy=' . (string) ($verification['strategy'] ?? '') . ' token_present=' . (!empty($verification['token_present']) ? '1' : '0') . ' ok=' . (!empty($verification['ok']) ? '1' : '0') . (!empty($verification['errors']) ? ' errors=' . wp_json_encode($verification['errors']) : ''));
        if (!$verification['ok']) {
            $log_embedded_fail('Token non valido', 'sos_pg_token_invalid', $verification['errors'] ?? []);
            return new WP_Error('sos_pg_token_invalid', 'Token non valido', [
                'status' => 401,
                'errors' => $verification['errors'],
                'strategy' => $verification['strategy'],
            ]);
        }

        $identity = $embedded->validate_identity_payload($request);
        error_log('[SOS SSO EMBEDDED] central identity ok=' . (!empty($identity['ok']) ? '1' : '0') . (!empty($identity['errors']) ? ' errors=' . wp_json_encode($identity['errors']) : '') . ' email=' . ((string) (($identity['identity']['email'] ?? '')) !== '' ? 'present' : 'missing'));
        if (!$identity['ok']) {
            $log_embedded_fail('Dati utente non validi', 'sos_pg_identity_invalid', $identity['errors'] ?? []);
            return new WP_Error('sos_pg_identity_invalid', 'Dati utente non validi', [
                'status' => 400,
                'errors' => $identity['errors'],
            ]);
        }

        // Handoff payload per redirect verso il login/flow esistente.
        $email = (string) $identity['identity']['email'];
        $this->plugin->stash_partner_identity_context($partner_id, (array) ($identity['identity'] ?? []));
        $timestamp = time();
        $nonce = wp_generate_password(12, false, false);
        $message = $partner_id . '|' . $email . '|' . $timestamp . '|' . $nonce;

        $pem = $registry ? $registry->get_partner_private_key($partner_id) : '';
        if ($pem === '') {
            $log_embedded_fail('Chiave privata partner mancante', 'sos_pg_partner_key_missing');
            return new WP_Error('sos_pg_partner_key_missing', 'Chiave privata partner mancante', ['status' => 500]);
        }

        $private_key = openssl_pkey_get_private($pem);
        if (!$private_key) {
            $log_embedded_fail('Chiave privata partner non valida', 'sos_pg_partner_key_invalid');
            return new WP_Error('sos_pg_partner_key_invalid', 'Chiave privata partner non valida', ['status' => 500]);
        }

        $signature_raw = '';
        $ok = openssl_sign($message, $signature_raw, $private_key, OPENSSL_ALGO_SHA256);
        openssl_free_key($private_key);

        if (!$ok) {
            $log_embedded_fail('Impossibile firmare il payload', 'sos_pg_partner_sign_fail');
            return new WP_Error('sos_pg_partner_sign_fail', 'Impossibile firmare il payload', ['status' => 500]);
        }

        $signature_b64 = base64_encode($signature_raw);
        $redirect_url = $this->plugin->get_login_endpoint_url();

        $response = [
            'success' => true,
            'partner_id' => $partner_id,
            'redirect_url' => $redirect_url,
            'handoff' => [
                'partner_id' => $partner_id,
                'payload' => $email,
                'timestamp' => $timestamp,
                'nonce' => $nonce,
                'signature' => $signature_b64,
            ],
            'normalized_payload' => $normalized,
            'verification' => $verification,
            'identity' => $identity['identity'],
            'booking_created' => false,
        ];

        $ok_payload = [
            'partner_id' => $partner_id,
            'email' => $email,
            'context' => [
                'redirect_url' => $redirect_url,
            ],
        ];
        if (!empty($verification['strategy'])) {
            $ok_payload['token_strategy'] = (string) $verification['strategy'];
        }
        if (!empty($normalized['external_reference'])) {
            $ok_payload['external_reference'] = (string) $normalized['external_reference'];
        }
        $this->plugin->log_public_event('INFO', 'EMBEDDED_CREATE_OK', $ok_payload);
        error_log('[SOS SSO EMBEDDED] central success partner_id=' . $partner_id . ' email=' . $email . ' redirect_url=' . $redirect_url);

        return new WP_REST_Response($response);
    }
}
