<?php
if (!defined('ABSPATH')) exit;

class SOS_PG_REST_Router {
    private $plugin;

    public function __construct(SOS_PG_Plugin $plugin) {
        $this->plugin = $plugin;
        add_action('rest_api_init', [$this, 'register_routes']);
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

    public function handle_health(WP_REST_Request $request) {
        return new WP_REST_Response($this->plugin->get_health_payload());
    }

    public function handle_partner_lookup(WP_REST_Request $request) {
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
        if (!is_user_logged_in()) {
            return new WP_REST_Response(['logged_in' => false]);
        }

        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return new WP_REST_Response(['logged_in' => false]);
        }

        $partner_id = sanitize_text_field((string) get_user_meta($user->ID, 'partner_id', true));
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
        $partner_id = sanitize_text_field((string) $request->get_param('partner_id'));
        $email_param = sanitize_email((string) $request->get_param('email'));
        $log_embedded_fail = function($reason, $error_code, $validation_errors = []) use ($partner_id, $email_param) {
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
        if (!$verification['ok']) {
            $log_embedded_fail('Token non valido', 'sos_pg_token_invalid', $verification['errors'] ?? []);
            return new WP_Error('sos_pg_token_invalid', 'Token non valido', [
                'status' => 401,
                'errors' => $verification['errors'],
                'strategy' => $verification['strategy'],
            ]);
        }

        $identity = $embedded->validate_identity_payload($request);
        if (!$identity['ok']) {
            $log_embedded_fail('Dati utente non validi', 'sos_pg_identity_invalid', $identity['errors'] ?? []);
            return new WP_Error('sos_pg_identity_invalid', 'Dati utente non validi', [
                'status' => 400,
                'errors' => $identity['errors'],
            ]);
        }

        // Handoff payload per redirect verso il login/flow esistente.
        $email = (string) $identity['identity']['email'];
        $timestamp = time();
        $nonce = wp_generate_password(12, false, false);
        $message = $partner_id . '|' . $email . '|' . $timestamp . '|' . $nonce;

        $cfg_full = $registry ? $registry->get_partner_config($partner_id) : null;
        $pem = $cfg_full && !empty($cfg_full['private_key_pem']) ? trim((string) $cfg_full['private_key_pem']) : '';
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

        return new WP_REST_Response($response);
    }
}
