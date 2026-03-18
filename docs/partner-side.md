# Istruzioni lato partner

## Endpoint
`POST https://videoconsulto.sospediatra.org/partner-login/`

## Campi richiesti
- `partner_id`
- `payload` = email utente
- `timestamp`
- `nonce`
- `signature`

## Stringa da firmare
`partner_id|payload|timestamp|nonce`

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