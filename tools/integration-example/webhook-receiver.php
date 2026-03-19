<?php
/**
 * SOS Partner Gateway — Esempio integrazione lato partner (standalone, senza WordPress)
 *
 * Questo file mostra come un partner che NON usa WordPress può:
 *   1. Ricevere il webhook booking_created dal gateway SOS
 *   2. Verificare la firma HMAC
 *   3. Salvare i dati della prenotazione
 *   4. Inviare il callback di pagamento al gateway
 *
 * Adatta questo esempio alla tua piattaforma (Node.js, Python, Laravel, ecc.)
 *
 * ─────────────────────────────────────────────────────
 * CONFIGURAZIONE — modifica questi valori
 * ─────────────────────────────────────────────────────
 */

define('SOS_WEBHOOK_SECRET', 'il-tuo-secret-condiviso-con-il-gateway');
define('SOS_PAYMENT_CALLBACK_URL', 'https://<tuo-dominio-gateway>/partner-payment-callback/');
define('SOS_PAYMENT_CALLBACK_SECRET', 'il-tuo-secret-per-il-callback-pagamento');

// File di storage semplice per questa demo. In produzione usa un database.
define('SOS_BOOKINGS_FILE', __DIR__ . '/received-bookings.json');

// ─────────────────────────────────────────────────────
// ROUTING SEMPLICE
// ─────────────────────────────────────────────────────

$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'webhook':
        handle_incoming_webhook();
        break;

    case 'pay':
        handle_send_payment();
        break;

    default:
        render_dashboard();
        break;
}

// ─────────────────────────────────────────────────────
// 1. RICEVI WEBHOOK dal gateway SOS
//    URL da configurare nel gateway: https://<tuo-dominio>/webhook-receiver.php?action=webhook
// ─────────────────────────────────────────────────────

function handle_incoming_webhook() {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'empty body']);
        exit;
    }

    // Verifica firma HMAC
    $sig_received = $_SERVER['HTTP_X_SOSPG_SIGNATURE'] ?? '';
    $sig_expected = hash_hmac('sha256', $raw, SOS_WEBHOOK_SECRET);

    if (!hash_equals($sig_expected, $sig_received)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'invalid signature']);
        exit;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['booking_id'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'missing booking_id']);
        exit;
    }

    // Salva la prenotazione ricevuta
    save_booking($data);

    // Prenotazione gratuita (sconto 100%, totale 0): nessun pagamento richiesto.
    // Il gateway non aggiorna lo stato finché non riceve il callback di conferma, quindi
    // lo inviamo immediatamente — è sicuro perché non c'è alcuna transazione finanziaria da verificare.
    $total = (float) ($data['total'] ?? -1);
    if ($total === 0.0) {
        send_payment_confirmation((int) $data['booking_id'], 'FREE-' . $data['booking_id'], $data['partner_id'] ?? '');
        mark_booking_paid((int) $data['booking_id'], 'auto-free');
    }

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// ─────────────────────────────────────────────────────
// 2. INVIA CALLBACK PAGAMENTO al gateway SOS
//    Chiamato dopo che l'utente ha pagato sulla tua piattaforma
// ─────────────────────────────────────────────────────

function send_payment_confirmation($booking_id, $transaction_id = '', $partner_id = '') {
    $payload = [
        'booking_id' => (int) $booking_id,
    ];
    if ($transaction_id !== '') {
        $payload['transaction_id'] = $transaction_id;
    }
    if ($partner_id !== '') {
        $payload['partner_id'] = $partner_id;
    }

    $body = json_encode($payload);
    $sig  = hash_hmac('sha256', $body, SOS_PAYMENT_CALLBACK_SECRET);

    $ch = curl_init(SOS_PAYMENT_CALLBACK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-SOSPG-Signature: ' . $sig,
        ],
    ]);

    $response  = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error     = curl_error($ch);
    curl_close($ch);

    return [
        'http_code' => $http_code,
        'response'  => $response,
        'error'     => $error,
    ];
}

function handle_send_payment() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?action=list');
        exit;
    }

    $booking_id    = (int) ($_POST['booking_id'] ?? 0);
    $transaction_id = trim((string) ($_POST['transaction_id'] ?? ''));
    $partner_id     = trim((string) ($_POST['partner_id'] ?? ''));

    if (!$booking_id) {
        header('Location: ?action=list&error=missing_booking_id');
        exit;
    }

    $result = send_payment_confirmation($booking_id, $transaction_id, $partner_id);

    $ok = $result['http_code'] >= 200 && $result['http_code'] < 300;
    if ($ok) {
        mark_booking_paid($booking_id, $transaction_id);
    }
    header('Location: ?action=list&paid=' . ($ok ? '1' : '0') . '&booking_id=' . $booking_id . '&http=' . $result['http_code']);
    exit;
}

// ─────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────

function save_booking(array $data) {
    $bookings = load_bookings();
    $id = (int) $data['booking_id'];
    $bookings[$id] = array_merge($bookings[$id] ?? [], $data, ['received_at' => gmdate('Y-m-d H:i:s')]);
    file_put_contents(SOS_BOOKINGS_FILE, json_encode($bookings, JSON_PRETTY_PRINT));
}

function mark_booking_paid($booking_id, $transaction_id = '') {
    $bookings = load_bookings();
    $id = (int) $booking_id;
    if (isset($bookings[$id])) {
        $bookings[$id]['payment_sent']    = true;
        $bookings[$id]['transaction_id']  = $transaction_id;
        $bookings[$id]['payment_sent_at'] = gmdate('Y-m-d H:i:s');
        file_put_contents(SOS_BOOKINGS_FILE, json_encode($bookings, JSON_PRETTY_PRINT));
    }
}

function load_bookings() {
    if (!file_exists(SOS_BOOKINGS_FILE)) {
        return [];
    }
    $json = file_get_contents(SOS_BOOKINGS_FILE);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// ─────────────────────────────────────────────────────
// DASHBOARD semplice per visualizzare le prenotazioni
// ─────────────────────────────────────────────────────

function render_dashboard() {
    $bookings = load_bookings();
    arsort($bookings);

    $msg_paid = isset($_GET['paid']) ? (int) $_GET['paid'] : -1;
    $msg_bid  = (int) ($_GET['booking_id'] ?? 0);
    $msg_http = (int) ($_GET['http'] ?? 0);
    $error    = $_GET['error'] ?? '';
    ?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>SOS Partner — Prenotazioni ricevute</title>
    <style>
        body { font-family: sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; color: #222; }
        h1   { font-size: 1.4em; border-bottom: 2px solid #0073aa; padding-bottom: 8px; }
        h2   { font-size: 1.1em; margin-top: 32px; }
        .notice { padding: 10px 16px; border-radius: 4px; margin-bottom: 16px; }
        .ok     { background: #d4edda; border: 1px solid #c3e6cb; }
        .err    { background: #f8d7da; border: 1px solid #f5c6cb; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #ddd; font-size: .9em; }
        th { background: #f5f5f5; font-weight: 600; }
        .btn { display: inline-block; padding: 6px 14px; background: #0073aa; color: #fff; border: none;
               border-radius: 3px; cursor: pointer; font-size: .9em; text-decoration: none; }
        .btn:hover { background: #005d8c; }
        code { background: #f0f0f0; padding: 2px 5px; border-radius: 3px; font-size: .85em; }
        .webhook-url { background: #fffbe6; border: 1px solid #ffe58f; padding: 10px 16px; border-radius: 4px; margin-bottom: 20px; }
        .note { background: #e3f2fd; border: 1px solid #90caf9; padding: 10px 16px; border-radius: 4px; margin-bottom: 16px; font-size: .9em; }
    </style>
</head>
<body>
<h1>SOS Partner Gateway — Integrazione partner (esempio)</h1>
<div class="note">
    <strong>Come funziona:</strong>
    Il gateway invia un webhook <code>booking_created</code> a questo URL ogni volta che un cliente prenota.
    Se il totale &egrave; <strong>0 &euro;</strong> (sconto 100%), la conferma viene inviata automaticamente.
    Se il totale &egrave; positivo, il pagamento avviene sulla tua piattaforma e devi cliccare <em>Conferma pagamento</em>
    per comunicare al gateway che il pagamento &egrave; andato a buon fine.
</div>

<div class="webhook-url">
    <strong>URL webhook da configurare nel gateway SOS:</strong><br>
    <code><?= h((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'tuo-dominio') . strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?action=webhook') ?></code>
</div>

<?php if ($msg_paid === 1): ?>
    <div class="notice ok">Callback pagamento inviato per prenotazione #<?= h($msg_bid) ?> — HTTP <?= h($msg_http) ?></div>
<?php elseif ($msg_paid === 0): ?>
    <div class="notice err">Errore invio callback pagamento per #<?= h($msg_bid) ?> — HTTP <?= h($msg_http) ?></div>
<?php elseif ($error !== ''): ?>
    <div class="notice err">Errore: <?= h($error) ?></div>
<?php endif; ?>

<h2>Prenotazioni ricevute (<?= count($bookings) ?>)</h2>

<?php if (empty($bookings)): ?>
    <p>Nessuna prenotazione ricevuta. Configura l'URL webhook nel gateway SOS e attendi la prima prenotazione.</p>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Ricevuta</th>
            <th>Partner</th>
            <th>Location</th>
            <th>Data / Ora</th>
            <th>Totale</th>
            <th>Email cliente</th>
            <th>Stato</th>
            <th>Pagamento</th>
            <th>Azione</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($bookings as $bid => $b): ?>
        <?php
            $is_free_row    = (float)($b['total'] ?? -1) === 0.0;
            $is_paid_row    = !empty($b['payment_sent']);
            $row_total_fmt  = $is_free_row ? '<span style="color:#2e7d32;font-weight:600;">Gratuita</span>' : h(number_format((float)($b['total'] ?? 0), 2)) . ' &euro;';
        ?>
        <tr>
            <td><?= h($bid) ?></td>
            <td><?= h($b['received_at'] ?? '') ?></td>
            <td><?= h($b['partner_id'] ?? '') ?></td>
            <td><?= h($b['location_id'] ?? '') ?></td>
            <td><?= h($b['start_date'] ?? '') ?> <?= h($b['start_time'] ?? '') ?></td>
            <td><?= $row_total_fmt ?></td>
            <td><?= h($b['customer_email'] ?? '') ?></td>
            <td><?= h($b['status'] ?? '') ?></td>
            <td>
                <?php if ($is_paid_row): ?>
                    <span style="color:#2e7d32;">&#10004; Confermato</span><br>
                    <small style="color:#888;"><?= h($b['payment_sent_at'] ?? '') ?></small>
                <?php else: ?>
                    <span style="color:#e65100;">In attesa</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($is_paid_row): ?>
                    <span style="color:#aaa;font-size:.85em;">&#10004; Callback inviato</span>
                <?php else: ?>
                    <form method="post" action="?action=pay">
                        <input type="hidden" name="booking_id" value="<?= h($bid) ?>">
                        <input type="hidden" name="partner_id" value="<?= h($b['partner_id'] ?? '') ?>">
                        <input type="hidden" name="transaction_id" value="PAY-<?= h($bid) ?>-<?= time() ?>">
                        <button class="btn" type="submit"><?= $is_free_row ? 'Conferma (gratuita)' : 'Conferma pagamento' ?></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

</body>
</html>
    <?php
}
