<?php
declare(strict_types=1);

/**
 * PARTNER FAKE RECEIVER / CALLBACK SENDER
 *
 * MAIN / GATEWAY:
 *   https://staging.sosmedico.org/
 *
 * PARTNER FAKE:
 *   https://test.sosprenotazioni.org/
 *
 * Questo file:
 * - riceve il webhook booking_created dal main staging
 * - salva l’ultimo payload ricevuto
 * - mostra una UI protetta per ispezionare i dati
 * - invia la callback pagamento firmata verso il main staging
 *
 * NON scrive su LatePoint.
 * Le modifiche su booking/stato avvengono solo quando premi "Invia callback pagamento"
 * e il main staging accetta la callback.
 */

// =========================
// CONFIG
// =========================
const UI_TOKEN = 'hI1seVH4oxEBapmL5cJLC9EZOq3cgmmfGfrM9h1qjTXtKd7yjJpb2wUwXg';
const INBOUND_WEBHOOK_SECRET = 'stgfake_webhook_secret_2026';
const OUTBOUND_CALLBACK_SECRET = 'stgfake_callback_secret_2026';
const OUTBOUND_CALLBACK_URL = 'https://staging.sosmedico.org/partner-payment-callback/';

const STORAGE_FILE = __DIR__ . '/partner-fake-last-booking.json';
const LOG_FILE = __DIR__ . '/partner-fake-log.txt';

// =========================
// HELPERS
// =========================
function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function log_line(string $level, string $message, array $context = []): void {
    $line = [
        'ts' => date('c'),
        'level' => $level,
        'message' => $message,
        'context' => $context,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'host' => $_SERVER['HTTP_HOST'] ?? '',
    ];
    file_put_contents(
        LOG_FILE,
        json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );
}

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_header_value(string $name): string {
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$serverKey]) ? trim((string) $_SERVER[$serverKey]) : '';
}

function verify_hmac(string $rawBody, string $providedSignature, string $secret): bool {
    if ($providedSignature === '' || $secret === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, $providedSignature);
}

function require_ui_token(): void {
    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';
    if (!hash_equals(UI_TOKEN, $token)) {
        log_line('WARN', 'ui_forbidden', ['provided_token' => $token !== '' ? 'present' : 'missing']);
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

function load_last_payload(): ?array {
    if (!file_exists(STORAGE_FILE)) {
        return null;
    }
    $json = file_get_contents(STORAGE_FILE);
    if ($json === false || $json === '') {
        return null;
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

function save_last_payload(array $payload): void {
    file_put_contents(
        STORAGE_FILE,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function is_inbound_webhook_request(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST'
        && get_header_value('X-SOSPG-Signature') !== '';
}

function callback_default_payload(array $latest): array {
    $payload = $latest['payload'] ?? [];
    return [
        'booking_id' => (string) ($payload['booking_id'] ?? ''),
        'partner_id' => (string) ($payload['partner_id'] ?? ''),
        'external_reference' => (string) ($payload['external_reference'] ?? $payload['booking_id'] ?? ''),
        'amount_paid' => (string) ($payload['partner_charge'] ?? $payload['amount'] ?? $payload['total'] ?? ''),
        'transaction_id' => 'fake_tx_' . date('Ymd_His'),
        'status' => '',
        'currency' => 'EUR',
        'payment_provider' => 'partner_fake_receiver',
    ];
}

// =========================
// 1) RICEZIONE WEBHOOK DAL MAIN
// =========================
function handle_inbound_webhook(): never {
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false) {
        log_line('ERROR', 'webhook_body_read_failed');
        json_response(['ok' => false, 'error' => 'cannot_read_body'], 400);
    }

    $signature = get_header_value('X-SOSPG-Signature');

    log_line('INFO', 'webhook_received', [
        'signature_present' => $signature !== '',
        'raw_body' => $rawBody,
    ]);

    if (!verify_hmac($rawBody, $signature, INBOUND_WEBHOOK_SECRET)) {
        log_line('ERROR', 'webhook_invalid_signature', [
            'provided_signature' => $signature,
            'expected_signature' => hash_hmac('sha256', $rawBody, INBOUND_WEBHOOK_SECRET),
        ]);
        json_response(['ok' => false, 'error' => 'invalid_signature'], 403);
    }

    $data = json_decode($rawBody, true);
    if (!is_array($data)) {
        log_line('ERROR', 'webhook_invalid_json', ['raw_body' => $rawBody]);
        json_response(['ok' => false, 'error' => 'invalid_json'], 400);
    }

    $wrapped = [
        'received_at' => date('c'),
        'source_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'headers' => [
            'Content-Type' => $_SERVER['CONTENT_TYPE'] ?? '',
            'X-SOSPG-Signature' => $signature,
        ],
        'payload' => $data,
        'raw_body' => $rawBody,
    ];

    save_last_payload($wrapped);

    log_line('INFO', 'webhook_saved', [
        'booking_id' => $data['booking_id'] ?? null,
        'partner_id' => $data['partner_id'] ?? null,
        'event' => $data['event'] ?? null,
        'amount' => $data['amount'] ?? null,
        'partner_charge' => $data['partner_charge'] ?? null,
        'pay_on_partner' => $data['pay_on_partner'] ?? null,
        'external_reference' => $data['external_reference'] ?? null,
    ]);

    json_response([
        'ok' => true,
        'message' => 'booking_received',
        'booking_id' => $data['booking_id'] ?? null,
        'partner_id' => $data['partner_id'] ?? null,
        'event' => $data['event'] ?? null,
    ]);
}

// =========================
// 2) INVIO CALLBACK VERSO IL MAIN
// =========================
function send_callback_from_latest(array $latest, array $override): array {
    $payload = $latest['payload'] ?? [];
    if (!is_array($payload)) {
        throw new RuntimeException('Payload webhook non valido');
    }

    $bookingId = isset($override['booking_id']) && $override['booking_id'] !== ''
        ? (int) $override['booking_id']
        : (int) ($payload['booking_id'] ?? 0);

    $partnerId = isset($override['partner_id']) && $override['partner_id'] !== ''
        ? (string) $override['partner_id']
        : (string) ($payload['partner_id'] ?? '');

    $externalReference = isset($override['external_reference']) && $override['external_reference'] !== ''
        ? (string) $override['external_reference']
        : (string) ($payload['external_reference'] ?? $payload['booking_id'] ?? '');

    $amountPaid = isset($override['amount_paid']) && $override['amount_paid'] !== ''
        ? (float) $override['amount_paid']
        : (float) ($payload['partner_charge'] ?? $payload['amount'] ?? $payload['total'] ?? 0);

    $transactionId = isset($override['transaction_id']) && $override['transaction_id'] !== ''
        ? (string) $override['transaction_id']
        : 'fake_tx_' . date('Ymd_His');

    $status = isset($override['status']) ? trim((string) $override['status']) : '';
    $currency = isset($override['currency']) && $override['currency'] !== ''
        ? (string) $override['currency']
        : 'EUR';

    if ($bookingId <= 0) {
        throw new RuntimeException('booking_id mancante o non valido');
    }
    if ($partnerId === '') {
        throw new RuntimeException('partner_id mancante');
    }

    $callbackPayload = [
        'booking_id' => $bookingId,
        'partner_id' => $partnerId,
        'transaction_id' => $transactionId,
        'amount_paid' => $amountPaid,
        'currency' => $currency,
        'payment_provider' => 'partner_fake_receiver',
        'external_reference' => $externalReference,
    ];

    if ($status !== '') {
        $callbackPayload['status'] = $status;
    }

    $body = json_encode($callbackPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        throw new RuntimeException('Impossibile serializzare il payload callback');
    }

    $signature = hash_hmac('sha256', $body, OUTBOUND_CALLBACK_SECRET);

    log_line('INFO', 'callback_sending', [
        'callback_url' => OUTBOUND_CALLBACK_URL,
        'payload' => $callbackPayload,
        'signature' => $signature,
    ]);

    $ch = curl_init(OUTBOUND_CALLBACK_URL);
    if ($ch === false) {
        throw new RuntimeException('Impossibile inizializzare cURL');
    }
    log_line('INFO', 'callback_request_headers', [
    'user_agent' => 'SOSPartnerFakeReceiver/1.0 (+https://test.sosprenotazioni.org/)',
    'referer' => 'https://test.sosprenotazioni.org/partner-fake-receiver.php',
    'callback_url' => OUTBOUND_CALLBACK_URL,
    ]);
    
    curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-SOSPG-Signature: ' . $signature,
        'User-Agent: SOSPartnerFakeReceiver/1.0 (+https://test.sosprenotazioni.org/)',
        'Referer: https://test.sosprenotazioni.org/partner-fake-receiver.php',
    ],
    CURLOPT_USERAGENT => 'SOSPartnerFakeReceiver/1.0 (+https://test.sosprenotazioni.org/)',
    CURLOPT_REFERER => 'https://test.sosprenotazioni.org/partner-fake-receiver.php',
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HEADER => false,
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    log_line('INFO', 'callback_response', [
        'http_code' => $httpCode,
        'response_body' => $responseBody,
        'curl_error' => $curlError,
    ]);

    return [
        'callback_payload' => $callbackPayload,
        'callback_signature' => $signature,
        'callback_url' => OUTBOUND_CALLBACK_URL,
        'http_code' => $httpCode,
        'response_body' => $responseBody,
        'curl_error' => $curlError,
    ];
}

// =========================
// 3) ROUTING
// =========================
if (is_inbound_webhook_request()) {
    handle_inbound_webhook();
}

require_ui_token();

$latest = load_last_payload();
$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ui_action']) && $_POST['ui_action'] === 'send_callback') {
    try {
        if ($latest === null) {
            throw new RuntimeException('Nessun payload webhook salvato');
        }

        $result = send_callback_from_latest($latest, [
            'booking_id' => $_POST['booking_id'] ?? '',
            'partner_id' => $_POST['partner_id'] ?? '',
            'external_reference' => $_POST['external_reference'] ?? '',
            'amount_paid' => $_POST['amount_paid'] ?? '',
            'transaction_id' => $_POST['transaction_id'] ?? '',
            'status' => $_POST['status'] ?? '',
            'currency' => $_POST['currency'] ?? '',
        ]);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        log_line('ERROR', 'ui_callback_error', ['error' => $error]);
    }
}

$defaults = $latest ? callback_default_payload($latest) : [
    'booking_id' => '',
    'partner_id' => '',
    'external_reference' => '',
    'amount_paid' => '',
    'transaction_id' => 'fake_tx_' . date('Ymd_His'),
    'status' => '',
    'currency' => 'EUR',
    'payment_provider' => 'partner_fake_receiver',
];

$logTail = '';
if (file_exists(LOG_FILE)) {
    $lines = @file(LOG_FILE, FILE_IGNORE_NEW_LINES);
    if (is_array($lines)) {
        $logTail = implode(PHP_EOL, array_slice($lines, -50));
    }
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Partner Fake Receiver</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 24px; background: #f7f7f9; color: #222; }
    .box { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 16px; margin-bottom: 18px; }
    label { display:block; margin-top:10px; font-weight:600; }
    input, textarea { width:100%; padding:10px; margin-top:6px; box-sizing:border-box; }
    button { margin-top:16px; padding:10px 16px; cursor:pointer; }
    pre { white-space: pre-wrap; word-break: break-word; background: #f3f4f6; padding: 12px; border-radius: 8px; }
    .ok { color: #0a7a20; font-weight: 700; }
    .err { color: #b00020; font-weight: 700; }
    .small { color:#666; font-size: 13px; }
    .grid { display:grid; grid-template-columns: 1fr 1fr; gap: 18px; }
    @media (max-width: 1000px) { .grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>

<div class="box">
  <h2>Partner Fake Receiver – test.sosprenotazioni.org</h2>
  <div class="small">
    Main target: <strong>https://staging.sosmedico.org/</strong><br>
    Questo tool riceve il webhook dal main staging e può inviare la callback pagamento firmata.
  </div>
</div>

<div class="grid">
  <div class="box">
    <h3>Configurazione attiva</h3>
    <pre><?php echo h(json_encode([
        'partner_fake_host' => $_SERVER['HTTP_HOST'] ?? '',
        'main_callback_url' => OUTBOUND_CALLBACK_URL,
        'storage_file' => STORAGE_FILE,
        'log_file' => LOG_FILE,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
  </div>

  <div class="box">
    <h3>Ultimo webhook ricevuto</h3>
    <?php if ($latest === null): ?>
      <div class="err">Nessun webhook ricevuto finora.</div>
    <?php else: ?>
      <div class="ok">Webhook salvato correttamente.</div>
      <pre><?php echo h(json_encode($latest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
    <?php endif; ?>
  </div>
</div>

<div class="box">
  <h3>Invia callback pagamento al main</h3>

  <?php if ($error !== null): ?>
    <div class="err"><?php echo h($error); ?></div>
  <?php endif; ?>

  <?php if ($result !== null): ?>
    <div class="<?php echo ((int)$result['http_code'] >= 200 && (int)$result['http_code'] < 300) ? 'ok' : 'err'; ?>">
      Risposta callback: HTTP <?php echo h((string)$result['http_code']); ?>
    </div>
    <pre><?php echo h(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
  <?php endif; ?>

  <form method="post" action="?token=<?php echo urlencode(UI_TOKEN); ?>">
    <input type="hidden" name="ui_action" value="send_callback">

    <label>booking_id</label>
    <input type="text" name="booking_id" value="<?php echo h($defaults['booking_id']); ?>">

    <label>partner_id</label>
    <input type="text" name="partner_id" value="<?php echo h($defaults['partner_id']); ?>">

    <label>external_reference</label>
    <input type="text" name="external_reference" value="<?php echo h($defaults['external_reference']); ?>">

    <label>amount_paid</label>
    <input type="text" name="amount_paid" value="<?php echo h($defaults['amount_paid']); ?>">

    <label>transaction_id</label>
    <input type="text" name="transaction_id" value="<?php echo h($defaults['transaction_id']); ?>">

    <label>currency</label>
    <input type="text" name="currency" value="<?php echo h($defaults['currency']); ?>">

    <label>status (facoltativo)</label>
    <input type="text" name="status" value="<?php echo h($defaults['status']); ?>">

    <button type="submit">Invia callback pagamento</button>
  </form>
</div>

<div class="box">
  <h3>Log ultimi eventi</h3>
  <pre><?php echo h($logTail !== '' ? $logTail : 'Nessun log disponibile'); ?></pre>
</div>

</body>
</html>