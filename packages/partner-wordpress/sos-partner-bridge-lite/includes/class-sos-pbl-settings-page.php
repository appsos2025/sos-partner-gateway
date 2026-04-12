<?php

if (!defined('ABSPATH')) {
    exit;
}

class SOS_PBL_Settings_Page {
    /** @var SOS_PBL_Config */
    private $config;

    public function __construct(SOS_PBL_Config $config) {
        $this->config = $config;

        add_action('admin_post_sos_pbl_save_settings', [$this, 'handle_save']);
        add_action('admin_post_sos_pbl_test_connection', [$this, 'handle_test_connection']);
        add_action('admin_post_sos_pbl_test_handoff', [$this, 'handle_test_handoff']);
        add_action('admin_post_sos_pbl_test_callback', [$this, 'handle_test_callback']);
    }



    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed');
        }

        $settings = $this->config->get();
        $settings_log = $settings;
        if (!empty($settings_log['shared_secret'])) {
            $settings_log['shared_secret'] = '***masked***';
        }
        error_log('SOS_PBL: render settings => ' . wp_json_encode($settings_log));
        $summary = $this->build_admin_summary($settings);
        $test_result = $this->consume_test_result();
        $handoff_result = $this->consume_handoff_result();
        $callback_result = $this->consume_callback_result();

        echo '<div class="wrap">';
        echo '<h1>SOS Partner Bridge Lite</h1>';
        echo '<p>Configurazione guidata per siti WordPress partner. Compila solo i campi necessari alla modalita attiva.</p>';

        // Display save notices
        $user_id = get_current_user_id();
        $admin_notice = get_transient('sos_pbl_admin_notice_' . $user_id);
        if ($admin_notice !== false) {
            delete_transient('sos_pbl_admin_notice_' . $user_id);
            $notice_type = (!empty($admin_notice['type']) && $admin_notice['type'] === 'success') ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($notice_type) . ' is-dismissible"><p>';
            if (!empty($admin_notice['messages']) && is_array($admin_notice['messages'])) {
                echo esc_html(implode(' | ', $admin_notice['messages']));
            } else {
                echo esc_html('Configurazione non salvata');
            }
            echo '</p></div>';
        }
        if ($test_result !== null) {
            $notice_class = !empty($test_result['ok']) ? 'notice notice-success is-dismissible' : 'notice notice-warning is-dismissible';
            echo '<div class="' . esc_attr($notice_class) . '"><p><strong>Test connessione:</strong> ' . esc_html((string) ($test_result['message'] ?? '')) . '</p></div>';
        }
        if ($handoff_result !== null) {
            $notice_class = !empty($handoff_result['ok']) ? 'notice notice-success is-dismissible' : 'notice notice-warning is-dismissible';
            echo '<div class="' . esc_attr($notice_class) . '"><p><strong>Test handoff:</strong> ' . esc_html((string) ($handoff_result['message'] ?? '')) . '</p></div>';
        }
        if ($callback_result !== null) {
            $notice_class = !empty($callback_result['ok']) ? 'notice notice-success is-dismissible' : 'notice notice-warning is-dismissible';
            echo '<div class="' . esc_attr($notice_class) . '"><p><strong>Test callback:</strong> ' . esc_html((string) ($callback_result['message'] ?? '')) . '</p></div>';
        }

        echo '<style>
            .sos-pbl-grid { display: grid; grid-template-columns: minmax(680px, 1fr) 320px; gap: 16px; align-items: start; }
            .sos-pbl-card { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; margin-bottom: 14px; }
            .sos-pbl-card h2 { margin: 0; padding: 12px 14px; border-bottom: 1px solid #f0f0f1; font-size: 15px; }
            .sos-pbl-card .inside { padding: 12px 14px; }
            .sos-pbl-kpi { padding: 10px 12px; border-left: 4px solid #d63638; background: #fcf0f1; margin-bottom: 10px; }
            .sos-pbl-kpi.ok { border-left-color: #00a32a; background: #f0f9f1; }
            .sos-pbl-badge { display: inline-block; border: 1px solid #c3c4c7; border-radius: 12px; padding: 2px 8px; font-size: 11px; margin: 3px 6px 0 0; }
            .sos-pbl-checklist { margin: 8px 0 0 0; }
            .sos-pbl-checklist li { margin: 0 0 4px 0; }
            .sos-pbl-check-ok { color: #008a20; }
            .sos-pbl-check-ko { color: #b32d2e; }
            .sos-pbl-help { color: #646970; margin: 4px 0 0 0; }
            .sos-pbl-required { color: #b32d2e; font-size: 11px; margin-left: 5px; }
            .sos-pbl-mode-row { display: none; }
            .sos-pbl-mode-row.active { display: table-row; }
            .sos-pbl-test-grid { display: grid; grid-template-columns: repeat(3, minmax(120px, 1fr)); gap: 10px; }
            .sos-pbl-path-status { display: inline-block; padding: 4px 8px; border-radius: 999px; background: #f0f6fc; color: #0f5132; font-size: 11px; font-weight: 600; }
            .sos-pbl-path-field { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
            .sos-pbl-path-editor { width: 100%; }
            @media (max-width: 1200px) { .sos-pbl-grid { grid-template-columns: 1fr; } }
        </style>';

        echo '<div class="sos-pbl-grid">';

        echo '<div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('sos_pbl_save_settings');
        echo '<input type="hidden" name="action" value="sos_pbl_save_settings" />';

        $private_key_path = (string) ($settings['private_key_path'] ?? '');
        $private_key_basename = $private_key_path !== '' ? basename(str_replace('\\', '/', $private_key_path)) : '';

        echo '<div class="sos-pbl-card"><h2>Configurazione base</h2><div class="inside">';
        echo '<table class="form-table">';
        echo '<tr><th><label for="sos_pbl_partner_id">Partner ID <span class="sos-pbl-required">obbligatorio</span></label></th><td><input id="sos_pbl_partner_id" name="partner_id" type="text" class="regular-text" value="' . esc_attr($settings['partner_id']) . '" /><p class="sos-pbl-help">Identificativo partner fornito dal sito centrale.</p></td></tr>';
        echo '<tr><th><label for="sos_pbl_integration_mode">Integrazione attiva <span class="sos-pbl-required">obbligatorio</span></label></th><td><select id="sos_pbl_integration_mode" name="integration_mode">';
        foreach ($this->get_integration_modes() as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($settings['integration_mode'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><p class="sos-pbl-help">Scegli il flusso da attivare su questo sito partner.</p></td></tr>';
        echo '<tr class="sos-pbl-mode-row" data-modes="handoff_login,embedded_booking,combined"><th><label for="sos_pbl_private_key_path">Percorso chiave privata partner</label></th><td>';
        echo '<div class="sos-pbl-path-field">';
        if ($private_key_path !== '') {
            echo '<input type="hidden" name="retain_private_key_path" value="1" />';
            echo '<div class="sos-pbl-path-readonly">';
            echo '<span class="sos-pbl-path-status">private_key_path configurato' . ($private_key_basename !== '' ? ' · ' . esc_html($private_key_basename) : '') . '</span> ';
            echo '<button type="button" class="button button-secondary button-small" id="sos_pbl_enable_private_key_edit">Modifica percorso</button>';
            echo '</div>';
            echo '<div class="sos-pbl-path-editor" style="display:none;">';
            echo '<input id="sos_pbl_private_key_path" name="private_key_path" type="text" class="regular-text" value="" placeholder="/var/www/keys/partner-private.pem" />';
            echo '</div>';
            echo '<p class="sos-pbl-help">Per sicurezza il path completo non viene mostrato dopo il salvataggio. Il valore attuale resta invariato finché non usi <strong>Modifica percorso</strong> e salvi un nuovo path valido.</p>';
        } else {
            echo '<input type="hidden" name="retain_private_key_path" value="0" />';
            echo '<div class="sos-pbl-path-editor">';
            echo '<input id="sos_pbl_private_key_path" name="private_key_path" type="text" class="regular-text" value="" placeholder="/var/www/keys/partner-private.pem" />';
            echo '</div>';
            echo '<p class="sos-pbl-help">Campo operativo per i flussi con firma partner. Inserisci il path assoluto del file PEM sul server partner; dopo il salvataggio il path viene nascosto per sicurezza.</p>';
        }
        echo '</div>';
        echo '</td></tr>';
        echo '</table>';
        echo '</div></div>';

        echo '<div class="sos-pbl-card"><h2>Connessione al sito centrale</h2><div class="inside">';
        echo '<table class="form-table">';
        echo '<tr><th><label for="sos_pbl_central_base_url">URL sito centrale <span class="sos-pbl-required">obbligatorio</span></label></th><td><input id="sos_pbl_central_base_url" name="central_base_url" type="url" class="regular-text" placeholder="https://central.example.com" value="' . esc_attr($settings['central_base_url']) . '" /><p class="sos-pbl-help">Dominio base del sito SOS centrale, senza endpoint finale.</p></td></tr>';
        echo '<tr><th><label for="sos_pbl_shared_secret">Shared secret / token <span class="sos-pbl-required">obbligatorio</span></label></th><td><input id="sos_pbl_shared_secret" name="shared_secret" type="text" class="regular-text" value="' . esc_attr($settings['shared_secret']) . '" /><p class="sos-pbl-help">Credenziale condivisa con il sito centrale per autenticare le richieste.</p></td></tr>';
        echo '<tr><th><label for="sos_pbl_debug_enabled">Debug</label></th><td><label><input id="sos_pbl_debug_enabled" name="debug_enabled" type="checkbox" value="1" ' . checked(!empty($settings['debug_enabled']), true, false) . ' /> Attiva log tecnici</label><p class="sos-pbl-help">Facoltativo. Da usare solo durante setup o troubleshooting.</p></td></tr>';
        echo '</table>';
        echo '</div></div>';

        // Show advanced settings section with toggle
        echo '<div class="sos-pbl-card"><h2>Impostazioni avanzate</h2><div class="inside">';
        echo '<label style="margin-bottom: 16px; display: block;"><input id="sos_pbl_show_advanced_settings" name="show_advanced_settings" type="checkbox" value="1" ' . checked(!empty($settings['show_advanced_settings']), true, false) . ' /> <strong>Mostra impostazioni avanzate (endpoint personalizzati)</strong></label>';
        echo '<p class="sos-pbl-help" style="margin-bottom: 16px;">Questa sezione contiene solo override rari ed endpoint custom. I campi necessari al flusso partner restano nella configurazione principale.</p>';

        echo '<div id="sos_pbl_advanced_section" style="' . (empty($settings['show_advanced_settings']) ? 'display:none;' : '') . 'border-top: 1px solid #dcdcde; padding-top: 16px;">';
        echo '<table class="form-table">';
        echo '<tr class="sos-pbl-mode-row" data-modes="handoff_login,combined,embedded_booking"><th><label for="sos_pbl_handoff_endpoint_path">Endpoint handoff login</label></th><td><input id="sos_pbl_handoff_endpoint_path" name="handoff_endpoint_path" type="text" class="regular-text" value="' . esc_attr($settings['handoff_endpoint_path']) . '" /><p class="sos-pbl-help">Path sul sito centrale per richieste handoff/login. Default: /partner-login/</p></td></tr>';
        echo '<tr class="sos-pbl-mode-row" data-modes="payment_callback,combined"><th><label for="sos_pbl_payment_callback_path">Endpoint callback pagamento</label></th><td><input id="sos_pbl_payment_callback_path" name="payment_callback_path" type="text" class="regular-text" value="' . esc_attr($settings['payment_callback_path']) . '" /><p class="sos-pbl-help">Path sul sito centrale per callback pagamento. Default: /partner-payment-callback/</p></td></tr>';
        echo '<tr class="sos-pbl-mode-row" data-modes="embedded_booking,combined"><th><label for="sos_pbl_embedded_entrypoint_path">Endpoint embedded booking</label></th><td><input id="sos_pbl_embedded_entrypoint_path" name="embedded_entrypoint_path" type="text" class="regular-text" value="' . esc_attr($settings['embedded_entrypoint_path']) . '" /><p class="sos-pbl-help">Path REST sul sito centrale per booking embedded. Default: /wp-json/sos-pg/v1/embedded-booking/create</p></td></tr>';
        echo '</table>';
        echo '</div>';
        if (empty($settings['show_advanced_settings'])) {
            echo '<div style="border-top: 1px solid #dcdcde; padding-top: 16px;">';
            echo '<p><strong>Valori endpoint attivi (default consigliati):</strong></p>';
            echo '<p class="sos-pbl-help"><strong>handoff_endpoint_path:</strong> ' . esc_html((string) $settings['handoff_endpoint_path']) . '</p>';
            echo '<p class="sos-pbl-help"><strong>payment_callback_path:</strong> ' . esc_html((string) $settings['payment_callback_path']) . '</p>';
            echo '<p class="sos-pbl-help"><strong>embedded_entrypoint_path:</strong> ' . esc_html((string) $settings['embedded_entrypoint_path']) . '</p>';
            echo '</div>';
            echo '<input type="hidden" name="handoff_endpoint_path" value="' . esc_attr((string) $settings['handoff_endpoint_path']) . '" />';
            echo '<input type="hidden" name="payment_callback_path" value="' . esc_attr((string) $settings['payment_callback_path']) . '" />';
            echo '<input type="hidden" name="embedded_entrypoint_path" value="' . esc_attr((string) $settings['embedded_entrypoint_path']) . '" />';
        }
        echo '</div></div>';

        submit_button('Save Settings');
        echo '</form>';

        echo '<div class="sos-pbl-card"><h2>Test e diagnostica</h2><div class="inside">';
        echo '<p>Verifica rapida della connettivita verso il sito centrale.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 12px 0;">';
        wp_nonce_field('sos_pbl_test_connection');
        echo '<input type="hidden" name="action" value="sos_pbl_test_connection" />';
        echo '<button class="button button-secondary" type="submit">Test connessione</button>';
        echo '</form>';

        if ($test_result !== null) {
            echo '<div style="border:1px solid #dcdcde; background:#f6f7f7; padding:10px; margin:0 0 12px 0;">';
            echo '<p style="margin:0 0 6px 0;"><strong>URL testato:</strong> <code>' . esc_html((string) ($test_result['tested_url'] ?? '')) . '</code></p>';
            echo '<p style="margin:0 0 6px 0;"><strong>HTTP status:</strong> ' . esc_html((string) ($test_result['http_status'] ?? '-')) . '</p>';
            echo '<p style="margin:0 0 6px 0;"><strong>Esito:</strong> ' . esc_html(!empty($test_result['ok']) ? 'successo' : 'errore') . '</p>';
            echo '<p style="margin:0;"><strong>Dettaglio:</strong> ' . esc_html((string) ($test_result['detail'] ?? '')) . '</p>';
            echo '</div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 12px 0;">';
        wp_nonce_field('sos_pbl_test_handoff');
        echo '<input type="hidden" name="action" value="sos_pbl_test_handoff" />';
        echo '<button class="button button-secondary" type="submit">Test handoff</button>';
        echo ' <span style="color:#646970;font-size:12px;">Chiama <code>GET /wp-json/sos-pg/v1/handoff/{partner_id}</code> sul sito centrale.</span>';
        echo '</form>';

        if ($handoff_result !== null) {
            $border_color = !empty($handoff_result['ok']) ? '#00a32a' : '#dcdcde';
            echo '<div style="border:1px solid ' . esc_attr($border_color) . '; background:#f6f7f7; padding:10px; margin:0 0 12px 0;">';
            echo '<p style="margin:0 0 6px 0;"><strong>URL testato:</strong> <code>' . esc_html((string) ($handoff_result['tested_url'] ?? '')) . '</code></p>';
            echo '<p style="margin:0 0 6px 0;"><strong>Metodo:</strong> GET</p>';
            echo '<p style="margin:0 0 6px 0;"><strong>HTTP status:</strong> ' . esc_html((string) ($handoff_result['http_status'] ?? '-')) . '</p>';
            echo '<p style="margin:0 0 6px 0;"><strong>Esito:</strong> ' . esc_html(!empty($handoff_result['ok']) ? 'successo' : 'errore') . '</p>';
            echo '<p style="margin:0;"><strong>Dettaglio:</strong> ' . esc_html((string) ($handoff_result['detail'] ?? '')) . '</p>';
            echo '</div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:10px 0 0 0;">';
        wp_nonce_field('sos_pbl_test_callback');
        echo '<input type="hidden" name="action" value="sos_pbl_test_callback" />';
        echo '<button class="button button-secondary" type="submit">Test callback</button>';
        echo ' <span style="color:#646970;font-size:12px;">POST verso <code>{central_base_url}{payment_callback_path}</code> con payload di test e HMAC.</span>';
        echo '</form>';

        if ($callback_result !== null) {
            $border_color = !empty($callback_result['ok']) ? '#00a32a' : '#dcdcde';
            echo '<div style="border:1px solid ' . esc_attr($border_color) . '; background:#f6f7f7; padding:10px; margin:10px 0 0 0;">';
            echo '<p style="margin:0 0 6px 0;"><strong>URL testato:</strong> <code>' . esc_html((string) ($callback_result['tested_url'] ?? '')) . '</code></p>';
            echo '<p style="margin:0 0 6px 0;"><strong>Metodo:</strong> POST</p>';
            echo '<p style="margin:0 0 6px 0;"><strong>HTTP status:</strong> ' . esc_html((string) ($callback_result['http_status'] ?? '-')) . '</p>';
            echo '<p style="margin:0 0 6px 0;"><strong>Esito:</strong> ' . esc_html(!empty($callback_result['ok']) ? 'successo' : 'errore') . '</p>';
            echo '<p style="margin:0;"><strong>Dettaglio:</strong> ' . esc_html((string) ($callback_result['detail'] ?? '')) . '</p>';
            echo '</div>';
        }

        echo '</div></div>';

        echo '</div>';

        echo '<aside>';
        $kpi_class = $summary['is_ready'] ? 'sos-pbl-kpi ok' : 'sos-pbl-kpi';
        $status_label = $summary['is_ready'] ? 'Plugin pronto' : 'Configurazione incompleta';
        echo '<div class="' . esc_attr($kpi_class) . '"><strong>' . esc_html($status_label) . '</strong><br />';
        echo '<span>Modalita attiva: ' . esc_html($summary['mode_label']) . '</span></div>';

        echo '<div class="sos-pbl-card"><h2>Stato configurazione</h2><div class="inside">';
        echo '<ul class="sos-pbl-checklist">';
        foreach ($summary['checklist'] as $item) {
            $cls = $item['ok'] ? 'sos-pbl-check-ok' : 'sos-pbl-check-ko';
            $icon = $item['ok'] ? 'OK' : 'KO';
            echo '<li class="' . esc_attr($cls) . '"><strong>' . esc_html($icon) . '</strong> - ' . esc_html($item['label']) . '</li>';
        }
        echo '</ul>';

        if (!empty($summary['missing_fields'])) {
            echo '<p><strong>Campi mancanti:</strong></p><ul class="sos-pbl-checklist">';
            foreach ($summary['missing_fields'] as $missing) {
                echo '<li class="sos-pbl-check-ko">' . esc_html($missing) . '</li>';
            }
            echo '</ul>';
        }

        echo '<p><strong>Flussi attivi:</strong></p>';
        foreach ($summary['active_flows'] as $flow) {
            echo '<span class="sos-pbl-badge">' . esc_html($flow) . '</span>';
        }
        echo '</div></div>';
        echo '</aside>';

        echo '</div>';

        echo '<script>
            (function(){
                var modeSelect = document.getElementById("sos_pbl_integration_mode");
                var advancedToggle = document.getElementById("sos_pbl_show_advanced_settings");
                var advancedSection = document.getElementById("sos_pbl_advanced_section");
                var pathEditButton = document.getElementById("sos_pbl_enable_private_key_edit");
                
                if (!modeSelect) return;

                function updateModeRows() {
                    var mode = modeSelect.value;
                    document.querySelectorAll(".sos-pbl-mode-row").forEach(function(row) {
                        var modes = (row.getAttribute("data-modes") || "").split(",").map(function(v){ return v.trim(); });
                        if (modes.indexOf(mode) >= 0) {
                            row.classList.add("active");
                        } else {
                            row.classList.remove("active");
                        }
                    });
                }

                function updateAdvancedSection() {
                    if (advancedToggle && advancedSection) {
                        advancedSection.style.display = advancedToggle.checked ? "block" : "none";
                    }
                }

                modeSelect.addEventListener("change", updateModeRows);
                if (advancedToggle) {
                    advancedToggle.addEventListener("change", updateAdvancedSection);
                }
                if (pathEditButton) {
                    pathEditButton.addEventListener("click", function() {
                        var wrapper = pathEditButton.closest(".sos-pbl-path-field");
                        if (!wrapper) return;
                        var readonlyNode = wrapper.querySelector(".sos-pbl-path-readonly");
                        var editorNode = wrapper.querySelector(".sos-pbl-path-editor");
                        var retainNode = wrapper.querySelector("input[type=\"hidden\"][name=\"retain_private_key_path\"]");
                        if (readonlyNode) readonlyNode.style.display = "none";
                        if (editorNode) editorNode.style.display = "block";
                        if (retainNode) retainNode.value = "0";
                        var input = editorNode ? editorNode.querySelector("input") : null;
                        if (input) input.focus();
                    });
                }
                updateModeRows();
                updateAdvancedSection();
            })();
        </script>';
    }

    private function get_integration_modes() {
        return [
            'handoff_login' => 'Handoff login',
            'embedded_booking' => 'Embedded booking',
            'payment_callback' => 'Payment callback',
            'combined' => 'Combined (handoff + callback + embedded)',
        ];
    }

    private function build_admin_summary(array $settings) {
        $modes = $this->get_integration_modes();
        $mode = (string) ($settings['integration_mode'] ?? 'handoff_login');
        $is_valid_mode = isset($modes[$mode]);

        $missing = [];

        if ((string) ($settings['partner_id'] ?? '') === '') {
            $missing[] = 'partner_id';
        }
        if ((string) ($settings['central_base_url'] ?? '') === '') {
            $missing[] = 'central_base_url';
        }
        if ((string) ($settings['shared_secret'] ?? '') === '') {
            $missing[] = 'shared_secret';
        }
        if (in_array($mode, ['handoff_login', 'combined'], true) && (string) ($settings['private_key_path'] ?? '') === '') {
            $missing[] = 'private_key_path';
        }
        if (!$is_valid_mode) {
            $missing[] = 'integration_mode';
        }

        if (in_array($mode, ['handoff_login', 'embedded_booking', 'combined'], true) && (string) ($settings['handoff_endpoint_path'] ?? '') === '') {
            $missing[] = 'handoff_endpoint_path';
        }
        if (in_array($mode, ['payment_callback', 'combined'], true) && (string) ($settings['payment_callback_path'] ?? '') === '') {
            $missing[] = 'payment_callback_path';
        }
        if (in_array($mode, ['embedded_booking', 'combined'], true) && (string) ($settings['embedded_entrypoint_path'] ?? '') === '') {
            $missing[] = 'embedded_entrypoint_path';
        }

        $checklist = [
            [
                'label' => 'partner_id configurato',
                'ok' => (string) ($settings['partner_id'] ?? '') !== '',
            ],
            [
                'label' => 'central_base_url configurato',
                'ok' => (string) ($settings['central_base_url'] ?? '') !== '',
            ],
            [
                'label' => 'shared_secret configurato',
                'ok' => (string) ($settings['shared_secret'] ?? '') !== '',
            ],
            [
                'label' => 'private_key_path configurato',
                'ok' => (string) ($settings['private_key_path'] ?? '') !== '',
            ],
            [
                'label' => 'modalita attiva valida',
                'ok' => $is_valid_mode,
            ],
        ];

        $active_flows = [];
        if (in_array($mode, ['handoff_login', 'combined'], true)) {
            $active_flows[] = 'handoff_login';
        }
        if (in_array($mode, ['embedded_booking', 'combined'], true)) {
            $active_flows[] = 'embedded_booking';
        }
        if (in_array($mode, ['payment_callback', 'combined'], true)) {
            $active_flows[] = 'payment_callback';
        }

        if (empty($active_flows)) {
            $active_flows[] = 'none';
        }

        return [
            'is_ready' => empty($missing),
            'missing_fields' => $missing,
            'mode_label' => $modes[$mode] ?? 'Invalid mode',
            'checklist' => $checklist,
            'active_flows' => $active_flows,
        ];
    }

    public function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed');
        }

        check_admin_referer('sos_pbl_save_settings');

        $post_log = [
            'partner_id' => isset($_POST['partner_id']) ? (string) wp_unslash($_POST['partner_id']) : null,
            'central_base_url' => isset($_POST['central_base_url']) ? (string) wp_unslash($_POST['central_base_url']) : null,
            'integration_mode' => isset($_POST['integration_mode']) ? (string) wp_unslash($_POST['integration_mode']) : null,
            'shared_secret' => isset($_POST['shared_secret']) ? '***masked***' : null,
            'private_key_path' => isset($_POST['private_key_path']) && trim((string) wp_unslash($_POST['private_key_path'])) !== '' ? '***provided***' : '***not-provided***',
            'retain_private_key_path' => !empty($_POST['retain_private_key_path']) ? 1 : 0,
            'debug_enabled' => !empty($_POST['debug_enabled']) ? 1 : 0,
            'show_advanced_settings' => !empty($_POST['show_advanced_settings']) ? 1 : 0,
            'handoff_endpoint_path' => isset($_POST['handoff_endpoint_path']) ? (string) wp_unslash($_POST['handoff_endpoint_path']) : null,
            'payment_callback_path' => isset($_POST['payment_callback_path']) ? (string) wp_unslash($_POST['payment_callback_path']) : null,
            'embedded_entrypoint_path' => isset($_POST['embedded_entrypoint_path']) ? (string) wp_unslash($_POST['embedded_entrypoint_path']) : null,
        ];
        error_log('SOS_PBL: save POST payload => ' . wp_json_encode($post_log));

        $partner_id = trim((string) wp_unslash($_POST['partner_id'] ?? ''));
        $central_base_url = trim((string) wp_unslash($_POST['central_base_url'] ?? ''));
        $shared_secret = trim((string) wp_unslash($_POST['shared_secret'] ?? ''));
        $private_key_path_input = trim((string) wp_unslash($_POST['private_key_path'] ?? ''));
        $current = $this->config->get();
        $defaults = $this->config->defaults();
        $retain_private_key_path = !empty($_POST['retain_private_key_path']);

        // Validate required fields
        $validation_errors = [];
        if ($partner_id === '') {
            $validation_errors[] = 'Campo obbligatorio mancante: Partner ID';
        }
        if ($central_base_url === '') {
            $validation_errors[] = 'Campo obbligatorio mancante: URL sito centrale';
        }
        if ($shared_secret === '') {
            $validation_errors[] = 'Campo obbligatorio mancante: Shared secret';
        }

        $private_key_path = (string) ($current['private_key_path'] ?? '');
        if ($private_key_path_input !== '') {
            $resolved_private_key_path = realpath($private_key_path_input);
            if ($resolved_private_key_path === false || !is_file($resolved_private_key_path) || !is_readable($resolved_private_key_path)) {
                $validation_errors[] = 'Percorso chiave privata partner non valido';
            } else {
                $private_key_path = str_replace('\\', '/', (string) $resolved_private_key_path);
            }
        } elseif (!$retain_private_key_path) {
            $private_key_path = (string) ($current['private_key_path'] ?? '');
        }

        if (!empty($validation_errors)) {
            array_unshift($validation_errors, 'Configurazione non salvata');
            $user_id = get_current_user_id();
            set_transient('sos_pbl_admin_notice_' . $user_id, [
                'type' => 'error',
                'messages' => $validation_errors,
            ], 120);
            wp_safe_redirect(admin_url('admin.php?page=sos-pbl-settings'));
            exit;
        }

        $handoff_endpoint_path = array_key_exists('handoff_endpoint_path', $_POST)
            ? (string) wp_unslash($_POST['handoff_endpoint_path'])
            : (string) ($current['handoff_endpoint_path'] ?? $defaults['handoff_endpoint_path']);
        $payment_callback_path = array_key_exists('payment_callback_path', $_POST)
            ? (string) wp_unslash($_POST['payment_callback_path'])
            : (string) ($current['payment_callback_path'] ?? $defaults['payment_callback_path']);
        $embedded_entrypoint_path = array_key_exists('embedded_entrypoint_path', $_POST)
            ? (string) wp_unslash($_POST['embedded_entrypoint_path'])
            : (string) ($current['embedded_entrypoint_path'] ?? $defaults['embedded_entrypoint_path']);

        $update_payload = [
            'partner_id' => $partner_id,
            'central_base_url' => $central_base_url,
            'integration_mode' => wp_unslash($_POST['integration_mode'] ?? ''),
            'shared_secret' => $shared_secret,
            'private_key_path' => $private_key_path,
            'handoff_endpoint_path' => $handoff_endpoint_path,
            'payment_callback_path' => $payment_callback_path,
            'embedded_entrypoint_path' => $embedded_entrypoint_path,
            'debug_enabled' => !empty($_POST['debug_enabled']),
            'show_advanced_settings' => !empty($_POST['show_advanced_settings']),
        ];
        $update_payload_log = $update_payload;
        $update_payload_log['shared_secret'] = '***masked***';
        if (!empty($update_payload_log['private_key_path'])) {
            $update_payload_log['private_key_path'] = '***configured***';
        }
        error_log('SOS_PBL: save update payload => ' . wp_json_encode($update_payload_log));

        $saved = $this->config->update($update_payload);
        $saved_log = $saved;
        if (!empty($saved_log['shared_secret'])) {
            $saved_log['shared_secret'] = '***masked***';
        }
        error_log('SOS_PBL: save result => ' . wp_json_encode($saved_log));

        $persisted = $this->config->get();
        $is_persisted = (
            (string) ($persisted['partner_id'] ?? '') === (string) $saved['partner_id']
            && (string) ($persisted['central_base_url'] ?? '') === (string) $saved['central_base_url']
            && (string) ($persisted['shared_secret'] ?? '') === (string) $saved['shared_secret']
            && (string) ($persisted['private_key_path'] ?? '') === (string) $saved['private_key_path']
            && (string) ($persisted['integration_mode'] ?? '') === (string) $saved['integration_mode']
            && (int) (!empty($persisted['debug_enabled'])) === (int) (!empty($saved['debug_enabled']))
        );

        if ($is_persisted) {
            set_transient('sos_pbl_admin_notice_' . get_current_user_id(), [
                'type' => 'success',
                'messages' => ['Impostazioni salvate correttamente'],
            ], 120);
        } else {
            set_transient('sos_pbl_admin_notice_' . get_current_user_id(), [
                'type' => 'error',
                'messages' => ['Configurazione non salvata'],
            ], 120);
            error_log('SOS_PBL: persistence check failed after save');
        }

        if (!empty($saved['debug_enabled'])) {
            error_log('SOS_PBL: settings updated for partner ' . $saved['partner_id']);
        }

        wp_safe_redirect(admin_url('admin.php?page=sos-pbl-settings'));
        exit;
    }

    public function handle_test_connection() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed');
        }

        check_admin_referer('sos_pbl_test_connection');

        $settings = $this->config->get();
        $user_id = get_current_user_id();
        $base_url = trim((string) ($settings['central_base_url'] ?? ''));

        $result = [
            'ok' => false,
            'tested_url' => '',
            'http_status' => '-',
            'message' => 'Connessione non eseguita',
            'detail' => '',
        ];

        if ($base_url === '') {
            $result['message'] = 'Endpoint health non disponibile: central_base_url non configurato.';
            $result['detail'] = 'Imposta prima il campo URL sito centrale.';
            $this->store_test_result($user_id, $result);
            wp_safe_redirect(admin_url('admin.php?page=sos-pbl-settings'));
            exit;
        }

        $test_url = trailingslashit($base_url) . 'wp-json/sos-pg/v1/health';
        $result['tested_url'] = $test_url;

        $response = wp_remote_get($test_url, [
            'timeout' => 10,
            'redirection' => 3,
        ]);

        if (is_wp_error($response)) {
            $result['message'] = 'Errore di connessione verso il sito centrale.';
            $result['detail'] = $this->truncate_text($response->get_error_message(), 220);
            $this->store_test_result($user_id, $result);
            wp_safe_redirect(admin_url('admin.php?page=sos-pbl-settings'));
            exit;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $result['http_status'] = (string) $status;

        if ($status >= 200 && $status < 300) {
            $result['ok'] = true;
            $result['message'] = 'Connessione riuscita: endpoint centrale raggiungibile.';
            $result['detail'] = $this->truncate_text($body !== '' ? $body : 'Nessun body restituito.', 220);
        } elseif ($status === 403) {
            $result['message'] = 'Accesso negato (403) - Verificare configurazione';
            $result['detail'] = 'Endpoint raggiunto ma accesso negato. Possibili cause: partner_id non riconosciuto, shared_secret errato, o il sito centrale ha policy di blocco. ' . $this->truncate_text($body !== '' ? $body : '', 120);
        } elseif ($status === 404) {
            $result['message'] = 'Endpoint health non trovato (404)';
            $result['detail'] = 'URL centrale non corretto o plugin SOS Gateway non installato. Verificare il campo "URL sito centrale" e che il plugin sia attivo sul sito centrale.';
        } else {
            $result['message'] = 'Endpoint raggiunto ma risposta non OK (HTTP ' . $status . ')';
            $result['detail'] = $this->truncate_text($body !== '' ? $body : 'Risposta senza body.', 220);
        }

        $this->store_test_result($user_id, $result);
        wp_safe_redirect(admin_url('admin.php?page=sos-pbl-settings'));
        exit;
    }

    private function store_test_result($user_id, array $result) {
        if ($user_id <= 0) {
            return;
        }
        set_transient('sos_pbl_test_connection_result_' . $user_id, $result, 120);
    }

    private function consume_test_result() {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return null;
        }

        $key = 'sos_pbl_test_connection_result_' . $user_id;
        $result = get_transient($key);
        if ($result !== false) {
            delete_transient($key);
        }

        return is_array($result) ? $result : null;
    }

    private function truncate_text($text, $max_len) {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        if (strlen($text) <= $max_len) {
            return $text;
        }

        return substr($text, 0, $max_len) . '...';
    }

    public function handle_test_handoff() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed');
        }

        check_admin_referer('sos_pbl_test_handoff');

        $settings = $this->config->get();
        $user_id = get_current_user_id();
        $base_url = trim((string) ($settings['central_base_url'] ?? ''));
        $partner_id = trim((string) ($settings['partner_id'] ?? ''));
        $shared_secret = (string) ($settings['shared_secret'] ?? '');

        $result = [
            'ok' => false,
            'tested_url' => '',
            'http_status' => '-',
            'message' => 'Test non eseguito',
            'detail' => '',
        ];

        if ($base_url === '') {
            $result['message'] = 'Test handoff non eseguito: central_base_url non configurato.';
            $result['detail'] = 'Imposta prima il campo URL sito centrale.';
            $this->store_handoff_result($user_id, $result);
            wp_safe_redirect(admin_url('admin.php?page=sos-pbl-settings'));
            exit;
        }

        if ($partner_id === '') {
            $result['message'] = 'Test handoff non eseguito: partner_id non configurato.';
            $result['detail'] = 'Imposta prima il campo Partner ID.';
            $this->store_handoff_result($user_id, $result);
            wp_safe_redirect(admin_url('admin.php?page=sos-pbl-settings'));
            exit;
        }

        $test_url = trailingslashit($base_url) . 'wp-json/sos-pg/v1/handoff/' . rawurlencode($partner_id);
        $result['tested_url'] = $test_url;

        $response = wp_remote_get($test_url, [
            'timeout' => 10,
            'redirection' => 3,
            'headers' => [
                'X-SOS-Partner-ID' => $partner_id,
                'X-SOS-Partner-Token' => $shared_secret,
            ],
        ]);

        if (is_wp_error($response)) {
            $result['message'] = 'Errore di connessione verso il sito centrale.';
            $result['detail'] = $this->truncate_text($response->get_error_message(), 220);
            $this->store_handoff_result($user_id, $result);
            wp_safe_redirect(admin_url('admin.php?page=sos-pbl-settings'));
            exit;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $result['http_status'] = (string) $status;

        $json = json_decode($body, true);
        $rest_code = is_array($json) ? (string) ($json['code'] ?? '') : '';

        if ($status === 403 && $rest_code === 'sos_pg_handoff_forbidden') {
            // Expected best result: endpoint reachable, context valid, only user auth is missing.
            $result['ok'] = true;
            $result['message'] = 'Flusso handoff raggiungibile. Autenticazione utente richiesta (comportamento atteso nel test).';
            $result['detail'] = 'HTTP 403 "Autenticazione richiesta": il sito centrale ha riconosciuto l\'endpoint e il contesto partner. In produzione, la chiamata arriva da un utente autenticato.';
        } elseif ($status === 403) {
            $rest_msg = is_array($json) ? (string) ($json['message'] ?? '') : '';
            $result['message'] = 'Endpoint raggiunto ma risposta 403 non prevista.';
            $result['detail'] = $this->truncate_text($rest_msg !== '' ? $rest_msg : $body, 220);
        } elseif ($status === 404) {
            $result['message'] = 'Endpoint handoff non trovato sul sito centrale (404).';
            $result['detail'] = 'Verificare URL sito centrale e che il plugin SOS Gateway sia installato e attivo.';
        } elseif ($status >= 200 && $status < 300) {
            $result['ok'] = true;
            $result['message'] = 'Endpoint raggiunto con risposta ' . $status . ' (inatteso senza utente, ma positivo).';
            $result['detail'] = $this->truncate_text($body !== '' ? $body : 'Nessun body.', 220);
        } else {
            $result['message'] = 'Risposta HTTP ' . $status . ' dal sito centrale.';
            $result['detail'] = $this->truncate_text($body !== '' ? $body : 'Nessun body.', 220);
        }

        $this->store_handoff_result($user_id, $result);
        wp_safe_redirect(admin_url('admin.php?page=sos-pbl-settings'));
        exit;
    }

    private function store_handoff_result($user_id, array $result) {
        if ($user_id <= 0) {
            return;
        }
        set_transient('sos_pbl_test_handoff_result_' . $user_id, $result, 120);
    }

    private function consume_handoff_result() {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return null;
        }

        $key = 'sos_pbl_test_handoff_result_' . $user_id;
        $result = get_transient($key);
        if ($result !== false) {
            delete_transient($key);
        }

        return is_array($result) ? $result : null;
    }

    public function handle_test_callback() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed');
        }

        check_admin_referer('sos_pbl_test_callback');

        $settings = $this->config->get();
        $user_id = get_current_user_id();
        $base_url = trim((string) ($settings['central_base_url'] ?? ''));
        $partner_id = trim((string) ($settings['partner_id'] ?? ''));
        $shared_secret = (string) ($settings['shared_secret'] ?? '');
        $callback_path = trim((string) ($settings['payment_callback_path'] ?? ''));
        if ($callback_path === '') {
            $callback_path = '/partner-payment-callback';
        }

        $result = [
            'ok' => false,
            'tested_url' => '',
            'http_status' => '-',
            'message' => 'Test non eseguito',
            'detail' => '',
        ];

        if ($base_url === '') {
            $result['message'] = 'Test callback non eseguito: central_base_url non configurato.';
            $result['detail'] = 'Imposta prima il campo URL sito centrale.';
            $this->store_callback_result($user_id, $result);
            wp_safe_redirect(admin_url('admin.php?page=sos-pbl-settings'));
            exit;
        }

        if ($partner_id === '') {
            $result['message'] = 'Test callback non eseguito: partner_id non configurato.';
            $result['detail'] = 'Imposta prima il campo Partner ID.';
            $this->store_callback_result($user_id, $result);
            wp_safe_redirect(admin_url('admin.php?page=sos-pbl-settings'));
            exit;
        }

        if ($shared_secret === '') {
            $result['message'] = 'Test callback non eseguito: shared_secret non configurato.';
            $result['detail'] = 'La firma HMAC richiede il shared_secret. Imposta il campo prima di eseguire il test.';
            $this->store_callback_result($user_id, $result);
            wp_safe_redirect(admin_url('admin.php?page=sos-pbl-settings'));
            exit;
        }

        $test_url = rtrim($base_url, '/') . '/' . ltrim($callback_path, '/');
        $result['tested_url'] = $test_url;

        $payload = [
            'booking_id' => 0,
            'transaction_id' => 'test-admin-check',
            'partner_id' => $partner_id,
            'amount_paid' => 0,
            'currency' => 'EUR',
            'payment_provider' => 'test',
        ];
        $body = (string) wp_json_encode($payload);
        $signature = hash_hmac('sha256', $body, $shared_secret);

        $response = wp_remote_post($test_url, [
            'timeout' => 10,
            'redirection' => 3,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-SOSPG-Signature' => $signature,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            $result['message'] = 'Errore di connessione verso il sito centrale.';
            $result['detail'] = $this->truncate_text($response->get_error_message(), 220);
            $this->store_callback_result($user_id, $result);
            wp_safe_redirect(admin_url('admin.php?page=sos-pbl-settings'));
            exit;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body_resp = (string) wp_remote_retrieve_body($response);
        $result['http_status'] = (string) $status;

        if ($status === 400) {
            // Expected best result: context OK, secret configured, HMAC accepted.
            // booking_id=0 correctly rejected as "Dati mancanti". No real booking needed.
            $result['ok'] = true;
            $result['message'] = 'Endpoint callback raggiungibile e firma HMAC accettata (comportamento atteso nel test).';
            $result['detail'] = 'HTTP 400 "Dati mancanti": il sito centrale ha riconosciuto l\'endpoint, validato la firma e iniziato l\'elaborazione. In produzione, il payload conterra un booking_id reale.';
        } elseif ($status === 401) {
            $result['message'] = 'Firma HMAC non accettata dal sito centrale.';
            $result['detail'] = 'Il shared_secret del plugin partner non corrisponde al payment_callback_secret configurato sul sito centrale.';
        } elseif ($status === 403) {
            $body_text = trim($body_resp);
            if ($body_text === 'Callback non attivato') {
                $result['message'] = 'Callback non attivato sul sito centrale.';
                $result['detail'] = 'Il campo "Secret callback pagamento" e vuoto nelle impostazioni del plugin centrale. Configurarlo per attivare il flusso.';
            } elseif ($body_text === 'Contesto partner non valido') {
                $result['message'] = 'Contesto partner non valido sul sito centrale.';
                $result['detail'] = 'Il path "' . $callback_path . '" non corrisponde allo slug callback configurato nel plugin centrale. Verificare il campo payment_callback_path.';
            } else {
                $result['message'] = 'Accesso negato dal sito centrale (403).';
                $result['detail'] = $this->truncate_text($body_text !== '' ? $body_text : 'Risposta senza body.', 220);
            }
        } elseif ($status === 404) {
            $result['message'] = 'Endpoint callback non trovato (404).';
            $result['detail'] = 'Verificare URL sito centrale e che il plugin SOS Gateway sia installato e attivo. Path usato: ' . $callback_path;
        } elseif ($status >= 200 && $status < 300) {
            $result['ok'] = true;
            $result['message'] = 'Endpoint raggiunto con risposta ' . $status . ' (inatteso nel test, ma positivo).';
            $result['detail'] = $this->truncate_text($body_resp !== '' ? $body_resp : 'Nessun body.', 220);
        } else {
            $result['message'] = 'Risposta HTTP ' . $status . ' dal sito centrale.';
            $result['detail'] = $this->truncate_text($body_resp !== '' ? $body_resp : 'Nessun body.', 220);
        }

        $this->store_callback_result($user_id, $result);
        wp_safe_redirect(admin_url('admin.php?page=sos-pbl-settings'));
        exit;
    }

    private function store_callback_result($user_id, array $result) {
        if ($user_id <= 0) {
            return;
        }
        set_transient('sos_pbl_test_callback_result_' . $user_id, $result, 120);
    }

    private function consume_callback_result() {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return null;
        }

        $key = 'sos_pbl_test_callback_result_' . $user_id;
        $result = get_transient($key);
        if ($result !== false) {
            delete_transient($key);
        }

        return is_array($result) ? $result : null;
    }
}
