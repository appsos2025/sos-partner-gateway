<?php
// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

add_filter("auto_update_plugin", "__return_false");
add_filter("auto_update_theme", "__return_false");

// BEGIN ENQUEUE PARENT ACTION
// AUTO GENERATED - Do not modify or remove comment markers above or below:

if ( !function_exists( 'chld_thm_cfg_locale_css' ) ):
    function chld_thm_cfg_locale_css( $uri ){
        if ( empty( $uri ) && is_rtl() && file_exists( get_template_directory() . '/rtl.css' ) ) {
            $uri = get_template_directory_uri() . '/rtl.css';
        }
        return $uri;
    }
endif;
add_filter( 'locale_stylesheet_uri', 'chld_thm_cfg_locale_css' );

if ( !function_exists( 'child_theme_configurator_css' ) ):
    function child_theme_configurator_css() {
        wp_enqueue_style(
            'chld_thm_cfg_child',
            trailingslashit( get_stylesheet_directory_uri() ) . 'style.css',
            array( 'astra-theme-css','astra-learndash','woocommerce-layout','woocommerce-smallscreen','woocommerce-general' )
        );
    }
endif;
add_action( 'wp_enqueue_scripts', 'child_theme_configurator_css', 10 );

// END ENQUEUE PARENT ACTION

if ( ! function_exists( 'myCourtesyPage' ) ) {
    function myCourtesyPage() {
        $idCourtesyPage = 35; // pagina di cortesia
        $allowed_page_ids = array(35, 15395); // cortesia, partner-login

        // consenti sempre admin e ajax
        if ( is_admin() || wp_doing_ajax() ) {
            return;
        }

        // consenti sempre la REST API, utile se qualche plugin la usa
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if ( strpos($request_uri, '/wp-json/') === 0 ) {
            return;
        }

        // se utente NON loggato
        if ( ! is_user_logged_in() ) {
            if ( is_page($allowed_page_ids) ) {
                return;
            }

            wp_safe_redirect( get_permalink( $idCourtesyPage ) );
            exit;
        }
    }
}
add_action( 'template_redirect', 'myCourtesyPage' );

function redirect_after_login_to_homepage( $redirect, $user ) {
    return home_url();
}

add_action( 'wp', 'astra_remove_header' );
function astra_remove_header() {
    remove_action( 'astra_masthead', 'astra_masthead_primary_template' );
}

add_action( 'wp' , 'astra_remove_new_header' );
function astra_remove_new_header() {
    remove_action( 'astra_primary_header', array( Astra_Builder_Header::get_instance(), 'primary_header' ) );
    remove_action( 'astra_mobile_primary_header', array( Astra_Builder_Header::get_instance(), 'mobile_primary_header' ) );
}

// 1. Aggiungere i campi personalizzati nel profilo utente nella dashboard di WordPress
add_action( 'show_user_profile', 'add_custom_user_fields' );
add_action( 'edit_user_profile', 'add_custom_user_fields' );

function add_custom_user_fields( $user ) {
    $custom_fields = [
        'billing_partita_iva' => 'Partita IVA',
        'billing_codice_fiscale' => 'Codice Fiscale',
        'billing_codice_univoco' => 'Codice Univoco Destinatario',
        'billing_pec' => 'Indirizzo PEC',
        'billing_referente' => 'Referente'
    ];
    ?>
    <h3><?php _e('Informazioni di Fatturazione', 'woocommerce'); ?></h3>
    <table class="form-table">
        <?php foreach ($custom_fields as $key => $label) { ?>
            <tr>
                <th><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                <td>
                    <input
                        type="text"
                        name="<?php echo esc_attr($key); ?>"
                        id="<?php echo esc_attr($key); ?>"
                        value="<?php echo esc_attr( get_the_author_meta( $key, $user->ID ) ); ?>"
                        class="regular-text"
                    />
                </td>
            </tr>
        <?php } ?>
    </table>
    <?php
}

// 2. Salvare i dati quando vengono aggiornati dal profilo utente
add_action( 'personal_options_update', 'save_custom_user_fields' );
add_action( 'edit_user_profile_update', 'save_custom_user_fields' );

function save_custom_user_fields( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        return false;
    }

    $custom_fields = ['billing_partita_iva', 'billing_codice_fiscale', 'billing_codice_univoco', 'billing_pec', 'billing_referente'];

    foreach ($custom_fields as $field) {
        if ( isset( $_POST[$field] ) ) {
            update_user_meta( $user_id, $field, sanitize_text_field( wp_unslash( $_POST[$field] ) ) );
        }
    }
}

/**
 * Partner login endpoint
 */
add_action('init', function () {

    $request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

    if ($request_path !== '/partner-login/' && $request_path !== '/partner-login') {
        return;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    // Ban temporaneo IP
    $ban_key = 'partner_login_ban_' . md5($ip);
    if (get_transient($ban_key)) {
        error_log("PARTNER LOGIN BLOCKED | IP: {$ip} | UA: {$user_agent} | motivo: IP in ban temporaneo");
        status_header(429);
        exit('Troppi tentativi. Riprova più tardi.');
    }

    // Contatori errori
    $fail_short_key = 'partner_login_fail_short_' . md5($ip); // 10 minuti
    $fail_long_key  = 'partner_login_fail_long_' . md5($ip);  // 24 ore

    $register_fail = function(string $reason) use ($ip, $user_agent, $fail_short_key, $fail_long_key, $ban_key) {
        $short = (int) get_transient($fail_short_key);
        $long  = (int) get_transient($fail_long_key);

        $short++;
        $long++;

        set_transient($fail_short_key, $short, 10 * MINUTE_IN_SECONDS);
        set_transient($fail_long_key, $long, DAY_IN_SECONDS);

        if ($short >= 10) {
            set_transient($ban_key, 1, HOUR_IN_SECONDS);
            error_log("PARTNER LOGIN BAN | IP: {$ip} | UA: {$user_agent} | motivo: {$reason} | durata: 1 ora");
        } elseif ($long >= 25) {
            set_transient($ban_key, 1, DAY_IN_SECONDS);
            error_log("PARTNER LOGIN BAN | IP: {$ip} | UA: {$user_agent} | motivo: {$reason} | durata: 24 ore");
        } else {
            error_log("PARTNER LOGIN FAIL | IP: {$ip} | UA: {$user_agent} | motivo: {$reason}");
        }
    };

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $register_fail('metodo non consentito');
        status_header(405);
        exit('Metodo non consentito');
    }

    $public_key_path = get_stylesheet_directory() . '/public.pem';

    if (!file_exists($public_key_path)) {
        error_log("PARTNER LOGIN FAIL | IP: {$ip} | motivo: public.pem non trovato");
        status_header(500);
        exit('Chiave pubblica non trovata');
    }

    $public_key_contents = file_get_contents($public_key_path);
    $publicKey = openssl_pkey_get_public($public_key_contents);

    if (!$publicKey) {
        error_log("PARTNER LOGIN FAIL | IP: {$ip} | motivo: chiave pubblica non valida");
        status_header(500);
        exit('Chiave pubblica non valida');
    }

    $partner_id    = isset($_POST['partner_id']) ? sanitize_text_field(wp_unslash($_POST['partner_id'])) : '';
    $email         = isset($_POST['payload']) ? sanitize_email(wp_unslash($_POST['payload'])) : '';
    $timestamp     = isset($_POST['timestamp']) ? (int) wp_unslash($_POST['timestamp']) : 0;
    $nonce         = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    $signature_b64 = isset($_POST['signature']) ? wp_unslash($_POST['signature']) : '';
    $signature     = base64_decode($signature_b64, true);

    if (empty($partner_id)) {
        $register_fail('partner_id mancante');
        status_header(400);
        exit('Partner non valido');
    }

    if (empty($email) || !is_email($email)) {
        $register_fail("email non valida | partner: {$partner_id}");
        status_header(400);
        exit('Email non valida');
    }

    // 180 secondi per tolleranza orologi server
    if (!$timestamp || abs(time() - $timestamp) > 180) {
        $register_fail("timestamp scaduto | partner: {$partner_id} | email: {$email}");
        status_header(403);
        exit('Richiesta scaduta');
    }

    if (empty($nonce)) {
        $register_fail("nonce mancante | partner: {$partner_id} | email: {$email}");
        status_header(400);
        exit('Nonce mancante');
    }

    if ($signature === false || empty($signature)) {
        $register_fail("firma mancante/non valida | partner: {$partner_id} | email: {$email}");
        status_header(400);
        exit('Firma non valida');
    }

    // ATTENZIONE: anche il partner deve firmare esattamente questa stringa
    $message = $partner_id . '|' . $email . '|' . $timestamp . '|' . $nonce;
    $ok = openssl_verify($message, $signature, $publicKey, OPENSSL_ALGO_SHA256);

    if ($ok !== 1) {
        $register_fail("firma non valida | partner: {$partner_id} | email: {$email}");
        status_header(403);
        exit('Firma non valida');
    }

    $user = get_user_by('email', $email);
    $is_new_user = false;

    if (!$user) {
        $password = wp_generate_password(20, true, true);
        $user_id = wp_create_user($email, $password, $email);

        if (is_wp_error($user_id)) {
            error_log("PARTNER LOGIN FAIL | partner: {$partner_id} | IP: {$ip} | email: {$email} | motivo: errore creazione utente");
            status_header(500);
            exit('Errore creazione utente');
        }

        wp_update_user(array(
            'ID'   => $user_id,
            'role' => 'role_zJrZSu46'
        ));

        $user = get_user_by('id', $user_id);
        $is_new_user = true;
    }

    if (!$user) {
        error_log("PARTNER LOGIN FAIL | partner: {$partner_id} | IP: {$ip} | email: {$email} | motivo: utente non disponibile");
        status_header(500);
        exit('Utente non disponibile');
    }

    // Salva info partner sul profilo utente
    update_user_meta($user->ID, 'partner_id', $partner_id);
    update_user_meta($user->ID, 'partner_last_login', time());

    wp_clear_auth_cookie();
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);
    do_action('wp_login', $user->user_login, $user);

    delete_transient($fail_short_key);
    delete_transient($fail_long_key);
    delete_transient($ban_key);

    $user_status = $is_new_user ? 'new_user' : 'existing_user';

// LIMITA LOG OK (anti-spam intelligente)
$ok_key = 'partner_login_ok_' . md5($email . '|' . $ip);
$last_ok = get_transient($ok_key);

if (!$last_ok) {
    // log primo accesso (o dopo timeout)
    error_log("PARTNER LOGIN OK | partner: {$partner_id} | email: {$email} | user_status: {$user_status} | IP: {$ip} | timestamp: {$timestamp} | nonce: {$nonce} | redirect: /prenotazioni-partner/ | UA: {$user_agent}");

    // blocca nuovi log OK per 5 minuti
    set_transient($ok_key, 1, 5 * MINUTE_IN_SECONDS);
}
    wp_safe_redirect(home_url('/prenotazioni-partner/'));
    exit;
});

/**
 * Restituisce il partner_id dell'utente loggato.
 */
function caf_get_current_partner_id($user_id = 0) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    if (!$user_id) {
        return '';
    }

    $partner_id = get_user_meta($user_id, 'partner_id', true);

    return is_string($partner_id) ? trim($partner_id) : '';
}

/**
 * Mappa partner -> sconto fisso in euro
 */
function caf_partner_discount_defaults(): array {
    return [
        'partner1' => 3.00,
        'partner2' => 5.00,
        'partner3' => 7.00,
    ];
}

function caf_get_partner_discount_amount($partner_id = '') {
    if (!$partner_id) {
        $partner_id = caf_get_current_partner_id();
    }

    // Mappa salvata da admin (pagina Sconti Partner).
    $saved_map = get_option('caf_partner_discounts', []);
    if (!is_array($saved_map)) {
        $saved_map = [];
    }

    // Merge default + salvata.
    $map = array_merge(caf_partner_discount_defaults(), $saved_map);

    return isset($map[$partner_id]) ? (float) $map[$partner_id] : 0.00;
}

// Debug/test helper: forza un partner_id per l'utente loggato passando ?force_partner=partner1
// Rimuovi quando hai finito i test.
add_action('init', function() {
    if (!is_user_logged_in()) {
        return;
    }

    if (empty($_GET['force_partner'])) {
        return;
    }

    $forced = sanitize_text_field(wp_unslash($_GET['force_partner']));

    if (!$forced) {
        return;
    }

    update_user_meta(get_current_user_id(), 'partner_id', $forced);
});

/**
 * Applica uno sconto fisso (in euro) ai partner.
 * - Usa l'id partner salvato nel profilo utente (vedi login partner).
 * - Rende opzionali i parametri del filtro per evitare warning che rompono la
 *   risposta Ajax di LatePoint.
 */
function caf_apply_partner_discount($amount, $booking = null, $apply_coupons = null) {
    $discount = caf_get_partner_discount_amount();

    if ($discount <= 0) {
        return $amount;
    }

    // Evita importi negativi e assicura un float.
    $new_amount = max(0, (float) $amount - $discount);

    return $new_amount;
}

// Applica lo sconto sia sul totale che sull'eventuale deposito.
add_filter('latepoint_full_amount_for_service', 'caf_apply_partner_discount', 20, 3);
add_filter('latepoint_full_amount', 'caf_apply_partner_discount', 20, 3);
add_filter('latepoint_deposit_amount_for_service', 'caf_apply_partner_discount', 20, 3);
add_filter('latepoint_deposit_amount', 'caf_apply_partner_discount', 20, 3);

// Admin page per gestire la mappa sconti partner.
add_action('admin_menu', function() {
    add_options_page(
        'Sconti Partner',
        'Sconti Partner',
        'manage_options',
        'caf-partner-discounts',
        'caf_render_partner_discounts_page'
    );
});

function caf_render_partner_discounts_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Non autorizzato');
    }

    $notice = '';
    $debug_parse = '';
    $debug_post = [];
    $debug_raw = '';

    // Leggi opzione corrente (per debug visuale)
    $option_before = get_option('caf_partner_discounts', []);

    // Salva mappa sconti.
    // Usa isset (il value del submit può essere vuoto).
    if (isset($_POST['caf_partner_discounts_submit'])) {
        check_admin_referer('caf_partner_discounts');

        $debug_post = $_POST;

        $raw = isset($_POST['caf_partner_discounts_text']) ? wp_unslash($_POST['caf_partner_discounts_text']) : '';
        $debug_raw = $raw;
        $lines = explode("\n", (string) $raw);
        $map = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Supporta separatore decimale con punto o virgola e partner_id con lettere, numeri, underscore, dash, punto.
            if (preg_match('/^([A-Za-z0-9._-]+)\s*[:|]\s*([-+]?[0-9]*[\.,]?[0-9]+)/', $line, $m)) {
                $id = $m[1];
                $amount_raw = str_replace(',', '.', $m[2]);
                $amount = (float) $amount_raw;
                $map[$id] = $amount;
            }
        }

        update_option('caf_partner_discounts', $map, false);
        $notice = $map ? 'Sconti salvati.' : 'Nessuna riga valida trovata: usa "partner_id | importo".';
        $debug_parse = 'Parse righe: ' . count($lines) . ' | Salvate: ' . count($map);
    }

    // Aggiorna partner_id per un utente specifico.
    if (!empty($_POST['caf_update_user_partner'])) {
        check_admin_referer('caf_partner_discounts');

        $user_id = isset($_POST['caf_user_id']) ? (int) $_POST['caf_user_id'] : 0;
        $partner_id = isset($_POST['caf_user_partner_id']) ? sanitize_text_field(wp_unslash($_POST['caf_user_partner_id'])) : '';

        if ($user_id > 0) {
            if ($partner_id === '') {
                delete_user_meta($user_id, 'partner_id');
                $notice = 'Partner rimosso per l\'utente ID ' . $user_id . '.';
            } else {
                update_user_meta($user_id, 'partner_id', $partner_id);
                $notice = 'Partner aggiornato per l\'utente ID ' . $user_id . '.';
            }
        }
    }

    $saved_map = get_option('caf_partner_discounts', []);
    if (!is_array($saved_map)) {
        $saved_map = [];
    }

    $option_after = $saved_map;

    // Mappa effettiva (default + salvata) per la tabella.
    $effective_map = array_merge(caf_partner_discount_defaults(), $saved_map);

    $textarea = '';
    foreach ($saved_map as $id => $amount) {
        $textarea .= $id . ' | ' . $amount . "\n";
    }

    // Elenco utenti con meta partner_id.
    $partner_users = get_users([
        'meta_key'   => 'partner_id',
        'meta_compare' => 'EXISTS',
        'number'     => 200,
        'orderby'    => 'display_name',
        'order'      => 'ASC',
    ]);

    echo '<div class="wrap">';
    echo '<h1>Sconti Partner</h1>';
    if ($notice) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    }

    // Debug info: mostra stato opzione prima/dopo e righe parse.
    if ($debug_parse) {
        echo '<p><em>' . esc_html($debug_parse) . '</em></p>';
    }
    echo '<details style="margin-bottom:10px;"><summary>Debug opzione sconti</summary>';
    echo '<p><strong>Prima (get_option):</strong><br><code>' . esc_html(var_export($option_before, true)) . '</code></p>';
    echo '<p><strong>Dopo (get_option):</strong><br><code>' . esc_html(var_export($option_after, true)) . '</code></p>';
    if (!empty($debug_post)) {
        echo '<p><strong>POST:</strong><br><code>' . esc_html(var_export($debug_post, true)) . '</code></p>';
        echo '<p><strong>Textarea raw:</strong><br><code>' . esc_html($debug_raw) . '</code></p>';
    }
    echo '</details>';

    echo '<p>Formato: una riga per partner, <code>partner_id | importo</code> (es. <code>partner_caf | 8.50</code>). L\'importo è uno sconto fisso in euro.</p>';

    // Tabella mappa sconti attiva (fallback + salvati).
    echo '<h2>Mappa sconti attiva (Default + Salvati)</h2>';
    if (empty($effective_map)) {
        echo '<p>Nessuna voce.</p>';
    } else {
        echo '<table class="widefat striped" style="max-width:600px;">';
        echo '<thead><tr><th>partner_id</th><th>Sconto €</th><th>Origine</th></tr></thead><tbody>';
        foreach ($effective_map as $pid => $amt) {
            $origin = array_key_exists($pid, $saved_map) ? 'Salvato' : 'Default';
            echo '<tr>';
            echo '<td>' . esc_html($pid) . '</td>';
            echo '<td>' . esc_html(number_format((float) $amt, 2, ',', '.')) . '</td>';
            echo '<td>' . esc_html($origin) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // Tabella mappa salvata (solo ciò che è stato salvato nel DB).
    echo '<h3>Mappa salvata (solo DB)</h3>';
    if (empty($saved_map)) {
        echo '<p>Nessuna voce salvata (solo default attivi).</p>';
    } else {
        echo '<table class="widefat striped" style="max-width:600px;">';
        echo '<thead><tr><th>partner_id</th><th>Sconto €</th></tr></thead><tbody>';
        foreach ($saved_map as $pid => $amt) {
            echo '<tr>';
            echo '<td>' . esc_html($pid) . '</td>';
            echo '<td>' . esc_html(number_format((float) $amt, 2, ',', '.')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '<form method="post">';
    wp_nonce_field('caf_partner_discounts');
    echo '<textarea name="caf_partner_discounts_text" rows="10" style="width:100%;max-width:640px;font-family:monospace;">' . esc_textarea($textarea) . '</textarea>';
    echo '<p><button type="submit" name="caf_partner_discounts_submit" class="button button-primary">Salva</button></p>';
    echo '</form>';

    echo '<hr style="margin:24px 0;">';
    echo '<h2>Utenti con partner_id</h2>';
    echo '<p>Modifica il partner associato a un utente. Svuota il campo per rimuovere l\'associazione.</p>';

    if (empty($partner_users)) {
        echo '<p>Nessun utente con partner_id.</p>';
    } else {
        echo '<table class="widefat striped" style="max-width:1000px;">';
        echo '<thead><tr><th>User</th><th>Email</th><th>partner_id</th><th>Sconto €</th><th>Azione</th></tr></thead><tbody>';
        foreach ($partner_users as $u) {
            $pid = get_user_meta($u->ID, 'partner_id', true);
            $discount = caf_get_partner_discount_amount($pid);
            echo '<tr>';
            echo '<td>' . esc_html($u->display_name) . ' (ID ' . (int) $u->ID . ')</td>';
            echo '<td>' . esc_html($u->user_email) . '</td>';
            echo '<td>' . esc_html($pid) . '</td>';
            echo '<td>' . esc_html(number_format((float) $discount, 2, ',', '.')) . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:flex;gap:6px;align-items:center;">';
            wp_nonce_field('caf_partner_discounts');
            echo '<input type="hidden" name="caf_update_user_partner" value="1">';
            echo '<input type="hidden" name="caf_user_id" value="' . (int) $u->ID . '">';
            echo '<input type="text" name="caf_user_partner_id" value="' . esc_attr($pid) . '" placeholder="partner_id" style="width:140px;">';
            echo '<button type="submit" class="button">Aggiorna</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '</div>';
}