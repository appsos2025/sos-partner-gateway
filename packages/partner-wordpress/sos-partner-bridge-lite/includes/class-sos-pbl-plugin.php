<?php

if (!defined('ABSPATH')) {
    exit;
}

class SOS_PBL_Plugin {
    private static $instance = null;

    /** @var SOS_PBL_Config */
    private $config;

    /** @var SOS_PBL_Central_Client */
    private $client;

    /** @var SOS_PBL_Settings_Page */
    private $settings_page;

    /** @var SOS_PBL_Handoff_Service */
    private $handoff_service;

    /** @var SOS_PBL_Payment_Callback */
    private $payment_callback;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->config = new SOS_PBL_Config();
        $this->client = new SOS_PBL_Central_Client($this->config);
        $this->settings_page = new SOS_PBL_Settings_Page($this->config);
        $this->handoff_service = new SOS_PBL_Handoff_Service($this->config, $this->client);
        $this->payment_callback = new SOS_PBL_Payment_Callback($this->config, $this->client);

        register_activation_hook(SOS_PBL_FILE, [$this, 'activate']);

        add_action('init', [$this, 'register_routes_placeholder']);
        add_action('template_redirect', [$this, 'handle_frontend_action']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_shortcode('sos_pbl_handoff_button', [$this, 'render_handoff_shortcode']);
        add_shortcode('sos_pbl_embedded_booking', [$this, 'render_embedded_shortcode']);
    }

    public function activate() {
        $this->config->ensure_defaults();
    }

    public function register_routes_placeholder() {
        // Placeholder only: route registration will be introduced in next implementation phase.
    }

    public function render_handoff_shortcode($atts = []) {
        $atts = shortcode_atts([
            'label' => 'Accedi al flusso partner',
            'class' => 'button sos-pbl-handoff-button',
            'text_before' => '',
            'text_after' => '',
            'fallback_message' => '',
        ], (array) $atts, 'sos_pbl_handoff_button');

        $validation = $this->validate_handoff_config();
        if (!$validation['ok']) {
            if (current_user_can('manage_options')) {
                return '<div class="sos-pbl-shortcode-error">SOS Partner Bridge Lite non configurato correttamente</div>';
            }

            return $atts['fallback_message'] !== ''
                ? '<div class="sos-pbl-shortcode-message">' . esc_html((string) $atts['fallback_message']) . '</div>'
                : '';
        }

        $return_url = $this->get_current_frontend_url();

        $local_action_url = add_query_arg([
            'sos_pbl_action' => 'handoff',
            'sos_pbl_nonce' => wp_create_nonce('sos_pbl_frontend_handoff'),
            'sos_pbl_return_to' => $return_url,
        ], home_url('/'));

        $html = '<div class="sos-pbl-handoff-wrapper">';
        if ($atts['text_before'] !== '') {
            $html .= '<p class="sos-pbl-handoff-text-before">' . esc_html((string) $atts['text_before']) . '</p>';
        }
        $html .= '<a href="' . esc_url($local_action_url) . '" class="' . esc_attr((string) $atts['class']) . '">' . esc_html((string) $atts['label']) . '</a>';
        if ($atts['text_after'] !== '') {
            $html .= '<p class="sos-pbl-handoff-text-after">' . esc_html((string) $atts['text_after']) . '</p>';
        }
        $html .= '</div>';

        return $html;
    }

    public function handle_frontend_action() {
        $action = isset($_GET['sos_pbl_action']) ? sanitize_key((string) wp_unslash($_GET['sos_pbl_action'])) : '';

        if ($action === 'completion_message_debug') {
            $this->handle_completion_message_debug();
            return;
        }

        if ($action === 'embedded_create') {
            $this->handle_frontend_embedded_create_proxy();
            return;
        }

        if ($action === 'embedded') {
            $this->handle_frontend_embedded_action();
            return;
        }

        if ($action !== 'handoff') {
            return;
        }

        $nonce = isset($_GET['sos_pbl_nonce']) ? sanitize_text_field((string) wp_unslash($_GET['sos_pbl_nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'sos_pbl_frontend_handoff')) {
            $this->log_debug('frontend handoff blocked: invalid nonce');
            $this->safe_frontend_redirect('Configurazione non disponibile al momento.', $this->resolve_frontend_return_url());
        }

        $validation = $this->validate_handoff_config();
        if (!$validation['ok']) {
            $this->log_debug('frontend handoff blocked: invalid settings - ' . implode(',', $validation['missing']));
            $this->safe_frontend_redirect('Configurazione non disponibile al momento.', $this->resolve_frontend_return_url());
        }

        $current_user = wp_get_current_user();
        $email = ($current_user instanceof WP_User && !empty($current_user->user_email)) ? (string) $current_user->user_email : '';
        $return_url = $this->resolve_frontend_return_url();
        $this->log_debug('[SOS SSO] partner return_url selected=' . $return_url);

        $payload = $this->handoff_service->build_handoff_payload($email, (string) $return_url);
        if (is_wp_error($payload)) {
            $this->log_debug('frontend handoff signing error: ' . $payload->get_error_message());
            $this->safe_frontend_redirect('Servizio temporaneamente non disponibile.', $return_url);
        }

        $response = $this->handoff_service->send_handoff_request($payload);

        if (is_wp_error($response)) {
            $this->log_debug('frontend handoff error: ' . $response->get_error_message());
            $this->safe_frontend_redirect('Servizio temporaneamente non disponibile.', $return_url);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw_body, true);

        if ($this->config->is_debug_enabled()) {
            $decoded_log = is_array($decoded) ? wp_json_encode($decoded) : 'not_json';
            $this->log_debug('frontend handoff response status=' . $status);
            $this->log_debug('frontend handoff response location=' . (string) wp_remote_retrieve_header($response, 'location'));
            $this->log_debug('frontend handoff response raw_body=' . $this->truncate_for_log($raw_body, 700));
            $this->log_debug('frontend handoff response json=' . $decoded_log);
        }

        $redirect_target = $this->extract_handoff_redirect_target($response);

        if ($this->config->is_debug_enabled()) {
            $this->log_debug('frontend handoff extracted redirect_target=' . ($redirect_target !== '' ? $redirect_target : 'empty'));
        }

        if (($status >= 200 && $status < 400) && $redirect_target !== '') {
            $this->log_debug('frontend handoff redirect to central target');
            if (wp_http_validate_url($redirect_target)) {
                wp_redirect($redirect_target);
                exit;
            }

            $this->log_debug('frontend handoff fallback reason=invalid_redirect_url_format');
        }

        if ($this->config->is_debug_enabled()) {
            $body_lower = strtolower(trim($raw_body));
            $detail_reason = 'unknown';
            if ($status === 405) {
                $detail_reason = 'central_rejected_method';
            } elseif ($status === 400 && strpos($body_lower, 'firma') !== false) {
                $detail_reason = 'missing_or_invalid_signature';
            } elseif ($status === 400 && strpos($body_lower, 'email') !== false) {
                $detail_reason = 'invalid_payload_email';
            } elseif ($status === 400 && strpos($body_lower, 'nonce') !== false) {
                $detail_reason = 'missing_nonce';
            } elseif ($status === 403 && strpos($body_lower, 'scaduta') !== false) {
                $detail_reason = 'expired_timestamp';
            } elseif ($status === 403 && strpos($body_lower, 'contesto partner non valido') !== false) {
                $detail_reason = 'invalid_partner_context';
            }

            $this->log_debug('frontend handoff fallback detail_reason=' . $detail_reason);
        }

        // TODO: Define the final central response contract and parse extra fields if needed.
        $this->log_debug('frontend handoff fallback reason=no_valid_redirect_target status=' . $status);
        $this->safe_frontend_redirect('Servizio temporaneamente non disponibile.', $return_url);
    }

    public function render_embedded_shortcode($atts = []) {
        $atts = shortcode_atts([
            'mode' => 'button',
            'label' => 'Prenota ora',
            'class' => 'button sos-pbl-embedded-button',
            'text_before' => '',
            'text_after' => '',
            'iframe_height' => '900',
            'fallback_message' => '',
            'validation_token' => '',
            'validation_token_type' => '',
            'external_reference' => '',
        ], (array) $atts, 'sos_pbl_embedded_booking');

        $mode = strtolower(sanitize_key((string) $atts['mode']));
        if (!in_array($mode, ['button', 'iframe'], true)) {
            $mode = 'button';
        }

        $label = sanitize_text_field((string) $atts['label']);
        $css_class = sanitize_text_field((string) $atts['class']);
        $text_before = sanitize_text_field((string) $atts['text_before']);
        $text_after = sanitize_text_field((string) $atts['text_after']);
        $iframe_height = absint($atts['iframe_height']);
        if ($iframe_height < 200) {
            $iframe_height = 900;
        }
        $fallback_message = sanitize_text_field((string) $atts['fallback_message']);

        $validation = $this->validate_embedded_config();
        $this->log_debug('embedded shortcode config=' . ($validation['ok'] ? 'valid' : 'invalid'));
        if (!$validation['ok']) {
            if (current_user_can('manage_options')) {
                return '<div class="sos-pbl-shortcode-error">SOS Partner Bridge Lite non configurato correttamente per il flusso embedded</div>';
            }

            return $fallback_message !== ''
                ? '<div class="sos-pbl-shortcode-message">' . esc_html($fallback_message) . '</div>'
                : '';
        }

        $return_url = $this->get_current_frontend_url();

        $create_url = add_query_arg([
            'sos_pbl_action' => 'embedded_create',
            'sos_pbl_nonce' => wp_create_nonce('sos_pbl_frontend_embedded_create'),
            'sos_pbl_return_to' => $return_url,
        ], home_url('/'));
        if ($create_url === '') {
            $this->log_debug('embedded shortcode invalid target URL');
            if (current_user_can('manage_options')) {
                return '<div class="sos-pbl-shortcode-error">SOS Partner Bridge Lite non configurato correttamente per il flusso embedded</div>';
            }

            return $fallback_message !== ''
                ? '<div class="sos-pbl-shortcode-message">' . esc_html($fallback_message) . '</div>'
                : '';
        }

        $this->log_debug('embedded shortcode mode=' . $mode);
        $this->log_debug('embedded shortcode create_url=' . $create_url);

        $button_id = 'sosPblEmbeddedButton' . wp_generate_uuid4();
        $status_id = 'sosPblEmbeddedStatus' . wp_generate_uuid4();
        $popup_name = 'sosPblEmbeddedPopup' . wp_generate_password(8, false, false);
        $completion_message_debug_url = add_query_arg([
            'sos_pbl_action' => 'completion_message_debug',
            'sos_pbl_nonce' => wp_create_nonce('sos_pbl_completion_message_debug'),
        ], home_url('/'));
        $partner_id = sanitize_text_field((string) ($this->config->get()['partner_id'] ?? ''));
        $central_base_url = esc_url_raw((string) ($this->config->get()['central_base_url'] ?? ''));
        $current_user = wp_get_current_user();
        $email = ($current_user instanceof WP_User && !empty($current_user->user_email)) ? (string) $current_user->user_email : '';
        $validation_token = sanitize_text_field((string) $atts['validation_token']);
        $validation_token_type = sanitize_text_field((string) $atts['validation_token_type']);
        $external_reference = sanitize_text_field((string) $atts['external_reference']);

        $html = '<div class="sos-pbl-embedded-wrapper">';
        if ($text_before !== '') {
            $html .= '<p class="sos-pbl-embedded-text-before">' . esc_html($text_before) . '</p>';
        }
        $html .= '<button type="button" id="' . esc_attr($button_id) . '" class="' . esc_attr($css_class) . '">' . esc_html($label) . '</button>';
        $html .= '<p id="' . esc_attr($status_id) . '" class="sos-pbl-embedded-status" style="display:none;"></p>';
        if ($text_after !== '') {
            $html .= '<p class="sos-pbl-embedded-text-after">' . esc_html($text_after) . '</p>';
        }
        $html .= '</div>';

        $html .= '<script>(function(){'
            . 'var button=document.getElementById(' . wp_json_encode($button_id) . ');'
            . 'var statusNode=document.getElementById(' . wp_json_encode($status_id) . ');'
            . 'var createUrl=' . wp_json_encode($create_url) . ';'
            . 'var partnerId=' . wp_json_encode($partner_id) . ';'
            . 'var email=' . wp_json_encode($email) . ';'
            . 'var validationToken=' . wp_json_encode($validation_token) . ';'
            . 'var validationTokenType=' . wp_json_encode($validation_token_type) . ';'
            . 'var externalReference=' . wp_json_encode($external_reference) . ';'
            . 'var popupName=' . wp_json_encode($popup_name) . ';'
            . 'var completionMessageDebugUrl=' . wp_json_encode($completion_message_debug_url) . ';'
            . 'var centralBaseUrl=' . wp_json_encode($central_base_url) . ';'
            . 'var returnUrl=' . wp_json_encode($return_url) . ';'
            . 'var popupWindow=null;'
            . 'var expectedPopupOrigin=null;'
            . 'var popupPollTimer=0;'
            . 'var completionMessageReceived=false;'
            . 'var completionHandled=false;'
            . 'function showStatus(message){if(statusNode){statusNode.style.display="block";statusNode.textContent=message;}}'
            . 'function clearStatus(){if(statusNode){statusNode.style.display="none";statusNode.textContent="";}}'
            . 'function logSso(message,details){if(window.console&&typeof window.console.log==="function"){if(typeof details!=="undefined"){window.console.log("[SOS SSO] "+message,details);return;}window.console.log("[SOS SSO] "+message);}}'
            . 'function logPopupError(message){if(window.console&&typeof window.console.error==="function"){window.console.error("[SOS SSO] " + message);}}'
            . 'function parseUrl(value){try{return new window.URL(String(value||""),window.location.href);}catch(error){return null;}}'
            . 'function normalizeBookingId(value){var stringValue=String(typeof value==="undefined"||value===null?"":value).trim();if(!/^\d+$/.test(stringValue)){return "";}return String(parseInt(stringValue,10));}'
            . 'function extractQueryPayload(value){var parsed=parseUrl(value);var payload={};if(!parsed){return payload;}parsed.searchParams.forEach(function(paramValue,paramKey){payload[paramKey]=paramValue;});return payload;}'
            . 'function extractOrigin(value){var parsed=parseUrl(value);return parsed?parsed.origin:"";}'
            . 'function isSameOriginUrl(value){var parsed=parseUrl(value);return !!parsed&&parsed.origin===window.location.origin;}'
            . 'function stopPopupPolling(){if(popupPollTimer){window.clearInterval(popupPollTimer);popupPollTimer=0;}}'
            . 'function logCompletionMessageDebug(details){if(!completionMessageDebugUrl){return;}try{window.fetch(completionMessageDebugUrl,{method:"POST",headers:{"Content-Type":"application/json","Accept":"application/json"},credentials:"same-origin",body:JSON.stringify(details||{})}).catch(function(){});}catch(error){}}'
            . 'function isStrongCompletionBookingId(value){var normalized=normalizeBookingId(value);var numericValue=normalized?parseInt(normalized,10):0;return !!normalized&&numericValue>1000;}'
            . 'function resolveMessageBookingId(payload){if(!payload||typeof payload!=="object"){return "";}return normalizeBookingId(payload.booking_id||payload.bookingId||"");}'
            . 'function extractBookingIdFromReturnUrl(value){var parsed=parseUrl(value);if(!parsed){return "";}return normalizeBookingId(parsed.searchParams.get("booking_id")||"");}'
            . 'function resolveCompletionTarget(payload){var payloadTarget=payload&&payload.returnUrl?String(payload.returnUrl):"";var finalBookingId=resolveMessageBookingId(payload);var target=null;if(!finalBookingId){if(window.console&&typeof window.console.warn==="function"){window.console.warn("[SOS DEBUG] abort final redirect: strong booking_id missing",payload||null);}return "";}if(window.console&&typeof window.console.log==="function"){window.console.log("[SOS DEBUG] final booking_id selected:",finalBookingId);}if(isSameOriginUrl(payloadTarget)){target=parseUrl(payloadTarget);}else if(isSameOriginUrl(returnUrl)){target=parseUrl(returnUrl);}if(!target){return "";}target.searchParams.set("booking_id",finalBookingId);return target.toString();}'
            . 'function shouldDebugHold(target){var parsedTarget=parseUrl(target||returnUrl||window.location.href);var currentUrl=parseUrl(window.location.href);var holdValue="";if(parsedTarget){holdValue=String(parsedTarget.searchParams.get("debug_hold")||"");}if(!holdValue&&currentUrl){holdValue=String(currentUrl.searchParams.get("debug_hold")||"");}return String(partnerId||"").toLowerCase()==="caf"&&holdValue==="1";}'
            . 'function shouldRejectCafCompletionMessage(payload,payloadBookingId){var payloadSource=payload&&typeof payload.source==="string"?payload.source:"";var payloadReturnUrl=payload&&payload.returnUrl?String(payload.returnUrl):"";var returnUrlBookingId=extractBookingIdFromReturnUrl(payloadReturnUrl);if(String(partnerId||"").toLowerCase()!=="caf"){return false;}if(payloadSource==="latepoint_success_monitor_fallback"){return true;}if(!isStrongCompletionBookingId(payloadBookingId)){return true;}if(returnUrlBookingId&&(!isStrongCompletionBookingId(returnUrlBookingId)||returnUrlBookingId!==normalizeBookingId(payloadBookingId))){return true;}return false;}'
            . 'function forceOpenerReturn(trigger,payload){var target;var effectiveTarget;var queryPayload;var payloadBookingId;var payloadSource;if(completionHandled){return;}payloadBookingId=resolveMessageBookingId(payload);payloadSource=payload&&typeof payload.source==="string"?payload.source:"";target=resolveCompletionTarget(payload);effectiveTarget=target||window.location.href;queryPayload=extractQueryPayload(effectiveTarget);if(shouldRejectCafCompletionMessage(payload,payloadBookingId)){logCompletionMessageDebug({decision:"abort_caf_weak",message_payload:payload||null,booking_id:payloadBookingId,source:payloadSource,received_return_url:payload&&payload.returnUrl?String(payload.returnUrl):"",final_return_url:target||"",current_url:window.location.href});completionHandled=true;stopPopupPolling();return;}logCompletionMessageDebug({decision:String(partnerId||"").toLowerCase()==="caf"?"accept_caf_strong":"debug",message_payload:payload||null,booking_id:payloadBookingId,source:payloadSource,received_return_url:payload&&payload.returnUrl?String(payload.returnUrl):"",final_return_url:target||"",current_url:window.location.href});if(!target){completionHandled=true;stopPopupPolling();return;}if(shouldDebugHold(target)){completionHandled=true;stopPopupPolling();showStatus("Debug hold CAF attivo: redirect sospeso.");return;}completionHandled=true;stopPopupPolling();if(window.console&&typeof window.console.log==="function"){window.console.log("[SOS DEBUG] returnUrl BEFORE redirect:",effectiveTarget);}logSso("forced opener refresh/redirect",{trigger:trigger,target:effectiveTarget,completionQueryPayload:queryPayload});if(target&&target!==window.location.href){window.location.assign(target);return;}window.location.reload();}'
            . 'function startPopupPolling(){stopPopupPolling();popupPollTimer=window.setInterval(function(){if(!popupWindow){stopPopupPolling();return;}if(popupWindow.closed){logSso("popup closed detected by opener");stopPopupPolling();if(!completionMessageReceived){forceOpenerReturn("popup_closed_without_message",null);}}},700);}'
            . 'function buildAndSubmit(responseData,targetName){var form=document.createElement("form");var returnInput=document.createElement("input");var openerOriginInput=document.createElement("input");var flowInput=document.createElement("input");form.method="POST";form.action=responseData.redirect_url;form.target=targetName;Object.keys(responseData.handoff||{}).forEach(function(key){var input=document.createElement("input");input.type="hidden";input.name=key;input.value=String(typeof responseData.handoff[key] === "undefined" ? "" : responseData.handoff[key]);form.appendChild(input);});returnInput.type="hidden";returnInput.name="return_url";returnInput.value=String(returnUrl||"");form.appendChild(returnInput);openerOriginInput.type="hidden";openerOriginInput.name="opener_origin";openerOriginInput.value=String(window.location.origin||"");form.appendChild(openerOriginInput);flowInput.type="hidden";flowInput.name="sos_pg_flow_context";flowInput.value="partner_wordpress_popup";form.appendChild(flowInput);document.body.appendChild(form);form.submit();document.body.removeChild(form);}'
            . 'function openPopup(responseData){var popupTarget=popupName+"_"+Date.now()+"_"+Math.random().toString(36).slice(2);var responseOrigin=extractOrigin(responseData&&responseData.redirect_url?responseData.redirect_url:centralBaseUrl);if(responseOrigin){expectedPopupOrigin=responseOrigin;}popupWindow=window.open("", popupTarget, "popup=yes,width=1280,height=900,resizable=yes,scrollbars=yes");if(popupWindow){logSso("popup opened",{expectedCentralOrigin:expectedPopupOrigin||extractOrigin(centralBaseUrl),partnerOpenerOrigin:window.location.origin,target:popupTarget});showStatus("Apertura finestra di prenotazione...");buildAndSubmit(responseData,popupTarget);startPopupPolling();return true;}showStatus("Il browser ha bloccato il popup. Abilita i popup e riprova.");logPopupError("popup blocked or open failed");return false;}'
            . 'function handleSuccess(responseData){if(openPopup(responseData)){return;}button.disabled=false;}'
            . 'window.addEventListener("message",function(event){var data=event&&event.data&&typeof event.data==="object"?event.data:null;var allowedOrigin=expectedPopupOrigin||extractOrigin(centralBaseUrl);var messageType="";var legacyType="";var returnPayload={};if(!data){return;}messageType=typeof data.type==="string"?data.type:"";legacyType=typeof data.legacyType==="string"?data.legacyType:"";if(messageType!=="sos_partner_login_complete"&&messageType!=="sos_pg_completion"&&messageType!=="SOS_BOOKING_COMPLETED"&&legacyType!=="sos_pg_completion"){return;}if(allowedOrigin&&event.origin!==allowedOrigin){logPopupError("completion postMessage ignored due to origin mismatch expected="+String(allowedOrigin||"")+" received="+String(event.origin||""));return;}completionMessageReceived=true;returnPayload=extractQueryPayload(data.returnUrl||"");if(window.console&&typeof window.console.log==="function"){window.console.log("[SOS DEBUG] message received:",data);}logSso("completion postMessage received",{expectedCentralOrigin:allowedOrigin,receivedOrigin:event.origin,messageType:messageType||legacyType,returnUrl:data.returnUrl||"",completionQueryPayload:returnPayload});forceOpenerReturn("completion_postmessage",data);});'
            . 'async function startEmbedded(){if(!button){return;}button.disabled=true;showStatus("Preparazione prenotazione...");try{var requestBody={partner_id:partnerId,email:email};if(validationToken){requestBody.validation_token=validationToken;requestBody.validation_token_type=validationTokenType||"passthrough";}if(externalReference){requestBody.external_reference=externalReference;}var response=await window.fetch(createUrl,{method:"POST",headers:{"Content-Type":"application/json","Accept":"application/json"},credentials:"same-origin",body:JSON.stringify(requestBody)});var data=await response.json();if(!response.ok||!data||data.success!==true||!data.redirect_url||!data.handoff){logPopupError("embedded_create_failed status="+String(response.status||0)+" message="+String(data&&data.message?data.message:"")+" code="+String(data&&data.code?data.code:""));throw new Error("embedded_create_failed");}handleSuccess(data);}catch(error){showStatus("Servizio temporaneamente non disponibile.");button.disabled=false;return;}button.disabled=false;}'
            . 'if(button){button.addEventListener("click",function(event){event.preventDefault();clearStatus();startEmbedded();});}'
        . '})();</script>';

        return $html;
    }

    private function handle_completion_message_debug() {
        $nonce = isset($_GET['sos_pbl_nonce']) ? sanitize_text_field((string) wp_unslash($_GET['sos_pbl_nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'sos_pbl_completion_message_debug')) {
            status_header(403);
            exit;
        }

        $raw_body = file_get_contents('php://input');
        $payload = json_decode((string) $raw_body, true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $log_payload = [
            'message_payload' => isset($payload['message_payload']) && is_array($payload['message_payload']) ? $payload['message_payload'] : [],
            'booking_id' => isset($payload['booking_id']) ? sanitize_text_field((string) $payload['booking_id']) : '',
            'source' => isset($payload['source']) ? sanitize_text_field((string) $payload['source']) : '',
            'received_return_url' => isset($payload['received_return_url']) ? esc_url_raw((string) $payload['received_return_url']) : '',
            'final_return_url' => isset($payload['final_return_url']) ? esc_url_raw((string) $payload['final_return_url']) : '',
            'current_url' => isset($payload['current_url']) ? esc_url_raw((string) $payload['current_url']) : '',
        ];

        if (($payload['decision'] ?? '') === 'abort_caf_weak') {
            error_log('SOS_PBL_ABORT_CAF_WEAK_COMPLETION_MESSAGE ' . wp_json_encode($log_payload));
        } elseif (($payload['decision'] ?? '') === 'accept_caf_strong') {
            error_log('SOS_PBL_ACCEPT_CAF_STRONG_COMPLETION_MESSAGE ' . wp_json_encode($log_payload));
        } else {
            error_log('SOS_PBL_COMPLETION_MESSAGE_DEBUG ' . wp_json_encode($log_payload));
        }

        status_header(204);
        exit;
    }

    private function handle_frontend_embedded_create_proxy() {
        error_log('[SOS SSO EMBEDDED] proxy entry method=' . strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) . ' action=' . sanitize_key((string) ($_GET['sos_pbl_action'] ?? '')) . ' nonce_present=' . (isset($_GET['sos_pbl_nonce']) ? '1' : '0') . ' return_to_present=' . (!empty($_GET['sos_pbl_return_to']) ? '1' : '0'));

        $nonce = isset($_GET['sos_pbl_nonce']) ? sanitize_text_field((string) wp_unslash($_GET['sos_pbl_nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'sos_pbl_frontend_embedded_create')) {
            error_log('[SOS SSO EMBEDDED] proxy error reason=invalid_nonce');
            wp_send_json(['success' => false, 'message' => 'Nonce non valido'], 403);
        }

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            error_log('[SOS SSO EMBEDDED] proxy error reason=invalid_method');
            wp_send_json(['success' => false, 'message' => 'Metodo non consentito'], 405);
        }

        $validation = $this->validate_embedded_config();
        if (!$validation['ok']) {
            error_log('[SOS SSO EMBEDDED] proxy error reason=invalid_embedded_config missing=' . wp_json_encode($validation['missing']));
            wp_send_json([
                'success' => false,
                'message' => 'Configurazione embedded non valida',
                'missing' => $validation['missing'],
            ], 400);
        }

        $settings = $this->config->get();
        $partner_id = sanitize_text_field((string) ($settings['partner_id'] ?? ''));
        $create_path = '/' . ltrim((string) ($settings['embedded_entrypoint_path'] ?? ''), '/');
        $raw_body = file_get_contents('php://input');
        error_log('[SOS SSO EMBEDDED] proxy request body_raw=' . $this->truncate_for_log((string) $raw_body, 700));
        $request_body = json_decode((string) $raw_body, true);
        if (!is_array($request_body)) {
            $request_body = [];
        }

        $current_user = wp_get_current_user();
        $request_email = sanitize_email((string) ($request_body['email'] ?? ''));
        $email = ($current_user instanceof WP_User && !empty($current_user->user_email)) ? (string) $current_user->user_email : '';
        if ($email === '' && $request_email !== '') {
            $email = $request_email;
        }
        $validation_token = sanitize_text_field((string) ($request_body['validation_token'] ?? ''));
        $validation_token_type = sanitize_text_field((string) ($request_body['validation_token_type'] ?? ''));
        $external_reference = sanitize_text_field((string) ($request_body['external_reference'] ?? ''));

        error_log('[SOS SSO EMBEDDED] proxy payload partner_id_present=' . ($partner_id !== '' ? '1' : '0') . ' email_present=' . ($email !== '' ? '1' : '0') . ' request_email_present=' . ($request_email !== '' ? '1' : '0') . ' validation_token_present=' . ($validation_token !== '' ? '1' : '0') . ' validation_token_type=' . ($validation_token_type !== '' ? $validation_token_type : '') . ' create_path=' . $create_path);

        $body = [
            'partner_id' => $partner_id,
            'email' => $email,
        ];

        if ($validation_token !== '') {
            $body['validation_token'] = $validation_token;
            $body['validation_token_type'] = $validation_token_type !== '' ? $validation_token_type : 'passthrough';
        }

        if ($external_reference !== '') {
            $body['external_reference'] = $external_reference;
        }

        $response = $this->client->post($create_path, $body);
        if (is_wp_error($response)) {
            error_log('[SOS SSO EMBEDDED] proxy error reason=client_post_wp_error message=' . $response->get_error_message());
            wp_send_json([
                'success' => false,
                'message' => 'Servizio temporaneamente non disponibile.',
                'error' => $response->get_error_message(),
            ], 502);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);
        error_log('[SOS SSO EMBEDDED] proxy central response status=' . $status . ' body=' . $this->truncate_for_log($raw_body, 700));
        $decoded = json_decode($raw_body, true);

        if (is_array($decoded)) {
            $response_message = isset($decoded['message']) ? (string) $decoded['message'] : '';
            $response_code = isset($decoded['code']) ? (string) $decoded['code'] : '';
            $response_success = isset($decoded['success']) ? ($decoded['success'] ? '1' : '0') : 'n/a';
            error_log('[SOS SSO EMBEDDED] proxy central decoded success=' . $response_success . ' code=' . $response_code . ' message=' . $response_message . ' redirect_url_present=' . (!empty($decoded['redirect_url']) ? '1' : '0') . ' handoff_present=' . (!empty($decoded['handoff']) ? '1' : '0'));
            wp_send_json($decoded, $status > 0 ? $status : 200);
        }

        error_log('[SOS SSO EMBEDDED] proxy error reason=central_response_not_json');
        wp_send_json([
            'success' => false,
            'message' => 'Risposta non valida dal centrale',
        ], $status > 0 ? $status : 502);
    }

    private function build_embedded_create_url() {
        $settings = $this->config->get();
        $base = rtrim((string) ($settings['central_base_url'] ?? ''), '/');
        $path = '/' . ltrim((string) ($settings['embedded_entrypoint_path'] ?? ''), '/');

        if ($base === '' || $path === '/') {
            return '';
        }

        return esc_url_raw($base . $path);
    }

    private function handle_frontend_embedded_action() {
        $nonce = isset($_GET['sos_pbl_nonce']) ? sanitize_text_field((string) wp_unslash($_GET['sos_pbl_nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'sos_pbl_frontend_embedded')) {
            $this->log_debug('frontend embedded blocked: invalid nonce');
            $this->safe_frontend_redirect('Configurazione non disponibile al momento.', $this->resolve_frontend_return_url());
        }

        $validation = $this->validate_embedded_config();
        if (!$validation['ok']) {
            $this->log_debug('frontend embedded blocked: invalid settings - ' . implode(',', $validation['missing']));
            $this->safe_frontend_redirect('Configurazione non disponibile al momento.', $this->resolve_frontend_return_url());
        }

        $target_url = $this->build_embedded_target_url();
        $settings = $this->config->get();
        $bridge_path = (string) ($settings['handoff_endpoint_path'] ?? '/partner-login/');
        $bridge_url = '';
        $base = rtrim((string) ($settings['central_base_url'] ?? ''), '/');
        if ($base !== '') {
            $bridge_url = esc_url_raw($base . '/' . ltrim($bridge_path, '/'));
        }

        if ($bridge_url === '' || !wp_http_validate_url($bridge_url)) {
            $this->log_debug('frontend embedded route error: invalid bridge URL');
            $this->safe_frontend_redirect('Servizio temporaneamente non disponibile.', $this->resolve_frontend_return_url());
        }

        $forwarded_partner_id = sanitize_text_field((string) ($this->config->get()['partner_id'] ?? ''));
        error_log('SOS_PBL EMBEDDED HANDOFF source_url=' . (string) ($_SERVER['REQUEST_URI'] ?? ''));
        error_log('SOS_PBL EMBEDDED HANDOFF source_partner_id=' . $forwarded_partner_id);
        error_log('SOS_PBL EMBEDDED HANDOFF forwarded_create_url=' . $target_url);
        error_log('SOS_PBL EMBEDDED HANDOFF forwarded_bridge_url=' . $bridge_url);

        $current_user = wp_get_current_user();
        $email = ($current_user instanceof WP_User && !empty($current_user->user_email)) ? (string) $current_user->user_email : '';
        $return_url = $this->resolve_frontend_return_url();
        $this->log_debug('[SOS SSO] partner return_url selected=' . $return_url);

        $payload = $this->handoff_service->build_handoff_payload($email, (string) $return_url);
        if (is_wp_error($payload)) {
            $this->log_debug('frontend embedded handoff signing error: ' . $payload->get_error_message());
            $this->safe_frontend_redirect('Servizio temporaneamente non disponibile.', $return_url);
        }

        status_header(200);
        echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Reindirizzamento...</title></head><body>';
        echo '<form id="sosPblEmbeddedHandoffForm" action="' . esc_url($bridge_url) . '" method="POST">';
        foreach ($payload as $key => $value) {
            echo '<input type="hidden" name="' . esc_attr((string) $key) . '" value="' . esc_attr((string) $value) . '">';
        }
        echo '</form>';
        echo '<script>document.getElementById("sosPblEmbeddedHandoffForm").submit();</script>';
        echo '</body></html>';
        exit;
    }

    private function validate_embedded_config() {
        $settings = $this->config->get();
        $missing = [];

        $partner_id = trim((string) ($settings['partner_id'] ?? ''));
        $central_base_url = trim((string) ($settings['central_base_url'] ?? ''));
        $integration_mode = (string) ($settings['integration_mode'] ?? '');
        $embedded_entrypoint_path = trim((string) ($settings['embedded_entrypoint_path'] ?? ''));

        if ($partner_id === '') {
            $missing[] = 'partner_id';
        }
        if ($central_base_url === '') {
            $missing[] = 'central_base_url';
        }
        if (!in_array($integration_mode, ['embedded_booking', 'combined'], true)) {
            $missing[] = 'integration_mode';
        }
        if ($embedded_entrypoint_path === '') {
            $missing[] = 'embedded_entrypoint_path';
        }

        return [
            'ok' => empty($missing),
            'missing' => $missing,
        ];
    }

    private function build_embedded_target_url() {
        $settings = $this->config->get();
        $base = rtrim((string) ($settings['central_base_url'] ?? ''), '/');
        $path = '/' . ltrim((string) ($settings['embedded_entrypoint_path'] ?? ''), '/');
        $partner_id = sanitize_text_field((string) ($settings['partner_id'] ?? ''));

        if ($base === '' || $path === '/' || $partner_id === '') {
            return '';
        }

        $target_url = $base . $path;

        // For direct navigation/iframe mode we provide partner context as query fallback.
        if (strpos($target_url, '?') === false) {
            $target_url .= '?partner_id=' . rawurlencode($partner_id);
        } else {
            $target_url .= '&partner_id=' . rawurlencode($partner_id);
        }

        return esc_url_raw($target_url);
    }

    private function is_embedded_iframe_compatible($target_url) {
        $path = (string) parse_url((string) $target_url, PHP_URL_PATH);
        if ($path === '') {
            return false;
        }

        // REST create endpoints generally expect POST + CORS and are not safe for direct iframe GET.
        if (strpos($path, '/wp-json/') !== false || strpos($path, '/embedded-booking/create') !== false) {
            return false;
        }

        return true;
    }

    private function validate_handoff_config() {
        $settings = $this->config->get();
        $missing = [];

        $partner_id = trim((string) ($settings['partner_id'] ?? ''));
        $central_base_url = trim((string) ($settings['central_base_url'] ?? ''));
        $integration_mode = (string) ($settings['integration_mode'] ?? '');
        $shared_secret = trim((string) ($settings['shared_secret'] ?? ''));
        $private_key_path = trim((string) ($settings['private_key_path'] ?? ''));
        $handoff_endpoint_path = trim((string) ($settings['handoff_endpoint_path'] ?? ''));

        if ($partner_id === '') {
            $missing[] = 'partner_id';
        }
        if ($central_base_url === '') {
            $missing[] = 'central_base_url';
        }

        if (!in_array($integration_mode, ['handoff_login', 'combined'], true)) {
            $missing[] = 'integration_mode';
        }

        if ($shared_secret === '') {
            $missing[] = 'shared_secret';
        }

        if ($private_key_path === '') {
            $missing[] = 'private_key_path';
        }

        if ($handoff_endpoint_path === '') {
            $missing[] = 'handoff_endpoint_path';
        }

        return [
            'ok' => empty($missing),
            'missing' => $missing,
        ];
    }

    private function extract_handoff_redirect_target($response) {
        $location_header = wp_remote_retrieve_header($response, 'location');
        if (is_string($location_header) && $location_header !== '') {
            return esc_url_raw($location_header);
        }

        $body = (string) wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return '';
        }

        $candidates = [
            isset($json['redirect_url']) ? $json['redirect_url'] : '',
            isset($json['redirect']) ? $json['redirect'] : '',
            isset($json['url']) ? $json['url'] : '',
            isset($json['handoff_url']) ? $json['handoff_url'] : '',
            isset($json['login_url']) ? $json['login_url'] : '',
            isset($json['data']['redirect']) ? $json['data']['redirect'] : '',
            isset($json['data']['redirect_url']) ? $json['data']['redirect_url'] : '',
            isset($json['data']['url']) ? $json['data']['url'] : '',
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return esc_url_raw((string) $candidate);
            }
        }

        return '';
    }

    private function safe_frontend_redirect($message, $preferred_target = '') {
        $target = $this->sanitize_local_return_url($preferred_target);
        if ($target === '') {
            $target = home_url('/');
        }

        $target = add_query_arg('sos_pbl_notice', (string) $message, $target);
        wp_safe_redirect($target);
        exit;
    }

    private function get_current_frontend_url() {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_HOST'])) : '';
        if ($host === '') {
            return home_url('/');
        }

        $scheme = is_ssl() ? 'https://' : 'http://';
        $url = $scheme . $host . $request_uri;
        $url = remove_query_arg(['sos_pbl_action', 'sos_pbl_nonce', 'sos_pbl_return_to', 'sos_pbl_notice'], $url);
        $url = $this->sanitize_local_return_url($url);

        return $url !== '' ? $url : home_url('/');
    }

    private function resolve_frontend_return_url() {
        $candidate = isset($_REQUEST['sos_pbl_return_to']) ? sanitize_text_field((string) wp_unslash($_REQUEST['sos_pbl_return_to'])) : '';
        $candidate = $this->sanitize_local_return_url($candidate);
        if ($candidate !== '') {
            return $candidate;
        }

        return home_url('/');
    }

    private function sanitize_local_return_url($url) {
        $url = esc_url_raw((string) $url);
        if ($url === '' || !wp_http_validate_url($url)) {
            return '';
        }

        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $url_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($home_host === '' || $url_host === '' || $home_host !== $url_host) {
            return '';
        }

        return $url;
    }

    private function log_debug($message) {
        if ($this->config->is_debug_enabled()) {
            error_log('SOS_PBL: ' . (string) $message);
        }
    }

    private function truncate_for_log($text, $max_len) {
        $text = trim((string) $text);
        if ($text === '' || strlen($text) <= $max_len) {
            return $text;
        }

        return substr($text, 0, $max_len) . '...';
    }

    public function register_admin_menu() {
        add_menu_page(
            'SOS Partner',
            'SOS Partner',
            'manage_options',
            'sos-pbl-settings',
            [$this, 'render_settings_page'],
            'dashicons-admin-generic',
            56
        );
    }

    public function render_settings_page() {
        if ($this->settings_page instanceof SOS_PBL_Settings_Page) {
            $this->settings_page->render_page();
            return;
        }

        if (class_exists('SOS_PBL_Settings_Page')) {
            $page = new SOS_PBL_Settings_Page($this->config);
            $page->render_page();
            return;
        }

        echo '<div class="wrap"><h1>Errore: Settings non caricati</h1></div>';
    }
}
