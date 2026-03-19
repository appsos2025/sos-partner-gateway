# Istruzioni lato partner

## Endpoint login
POST https://<tuo-dominio>/partner-login/

## Campi richiesti
- partner_id
- payload = email utente
- timestamp
- nonce
- signature (base64)

## Stringa da firmare
partner_id|payload|timestamp|nonce

## Form HTML esempio
```html
<form id="partnerLoginForm" action="https://<tuo-dominio>/partner-login/" method="POST">
  <input type="hidden" name="partner_id" value="<partner_id>">
  <input type="hidden" name="payload" value="utente@esempio.it">
  <input type="hidden" name="timestamp" value="<unix_timestamp>">
  <input type="hidden" name="nonce" value="<stringa_casuale>">
  <input type="hidden" name="signature" value="<BASE64_FIRMA_ECC>">
</form>
<script>document.getElementById('partnerLoginForm').submit();</script>
```

## Webhook booking_created (dal gateway al partner)
- Configurazione lato WordPress: URL e secret per ogni partner (HMAC SHA256 su body JSON) con header X-SOSPG-Signature.
- Payload inviato:
  - event (sempre booking_created)
  - partner_id, booking_id, status
  - service_id, start_date, start_time, total
  - customer_email
  - location_id = ID della posizione LatePoint associata al partner

## Callback pagamento (dal partner al gateway)
- Endpoint: /partner-payment-callback (slug configurabile nelle impostazioni)
- Header: Content-Type: application/json, X-SOSPG-Signature = HMAC SHA256 sul body con secret condiviso.
- Payload minimo accettato:
  - booking_id (obbligatorio)
  - status (facoltativo, altrimenti usa quello configurato su WP)
  - transaction_id (facoltativo)
  - partner_id (facoltativo)
- Effetto: imposta status = payment_success_status configurato e payment_status = paid su LatePoint.

## Flusso prenotazione gratuita (sconto 100%, total = 0)

Quando il partner ha uno sconto del 100%, il totale nel webhook sarà `total: 0`.
In questo caso il pagamento avviene esclusivamente sul sito del partner (fuori dal gateway SOS),
oppure non è richiesto affatto. **Il partner deve comunque inviare il callback di conferma** al gateway
per aggiornare lo stato della prenotazione su LatePoint.

Il file `tools/integration-example/webhook-receiver.php` gestisce automaticamente questo caso:
se `total == 0`, invia il callback di conferma immediatamente alla ricezione del webhook.

Per implementare lo stesso comportamento sulla tua piattaforma:

```php
// Alla ricezione del webhook
if ((float)$data['total'] === 0.0) {
    send_payment_confirmation($data['booking_id'], 'FREE-' . $data['booking_id'], $data['partner_id']);
}
```

## Utilizzo centralizzato multi-portale (es. sospediatra.org)

Il plugin supporta uno scenario in cui **un unico LatePoint centralizzato** riceve prenotazioni
da portali diversi. Ogni portale ha un proprio `partner_id` e una propria **posizione LatePoint**
(`location_id`) dedicata.

Flusso:
1. Il medico inserisce disponibilità su **un solo LatePoint** (es. su sospediatra.org)
2. Ogni portale partner (es. portale1.it, portale2.it) presenta un pulsante "Prenota"
3. Al click: il portale costruisce un login firmato verso `/partner-login/` di sospediatra.org
4. Il gateway autentica il partner, carica la pagina prenotazione giusta (location dedicata)
5. Il cliente prenota → il webhook arriva al portale di origine
6. Il portale gestisce il pagamento e invia il callback di conferma

Vantaggi:
- Il medico gestisce slot in un solo posto
- Gli slot vengono occupati automaticamente da tutti i portali
- Ogni prenotazione è tracciata con il `partner_id` e `location_id` del portale di origine
- Nessuna gestione manuale su più siti

Ogni portale deve configurare:
- `partner_id` univoco
- Chiave privata ECC per firmare il login
- URL webhook per ricevere le prenotazioni
- Secret HMAC per la verifica firma
- URL e secret per inviare il callback di pagamento
