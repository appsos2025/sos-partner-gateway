# Istruzioni lato partner

## Endpoint login
POST https://videoconsulto.sospediatra.org/partner-login/

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
<form id="partnerLoginForm" action="https://videoconsulto.sospediatra.org/partner-login/" method="POST">
  <input type="hidden" name="partner_id" value="fh">
  <input type="hidden" name="payload" value="mario.rossi@example.com">
  <input type="hidden" name="timestamp" value="1710267000">
  <input type="hidden" name="nonce" value="abc123xyz">
  <input type="hidden" name="signature" value="BASE64_SIGNATURE">
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
  - partner_field = cf_910bA88i

## Callback pagamento (dal partner al gateway)
- Endpoint: /partner-payment-callback (slug configurabile)
- Header: Content-Type: application/json, X-SOSPG-Signature = HMAC SHA256 sul body con secret condiviso.
- Payload minimo accettato:
  - booking_id (obbligatorio)
  - status (facoltativo, altrimenti usa quello configurato su WP)
  - transaction_id (facoltativo)
  - partner_id (facoltativo)
- Effetto: imposta status = payment_success_status configurato e payment_status = paid su LatePoint.
