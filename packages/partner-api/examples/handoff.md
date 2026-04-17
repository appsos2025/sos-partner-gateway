# Esempio direct partner-login — Family+Happy

Nel flusso Family+Happy attuale non è necessario chiamare prima l’endpoint embedded booking.

Il backend Family+Happy deve preparare questi valori:

- `partner_id`
- `payload` con l’email utente
- `timestamp`
- `nonce`
- `signature`

La firma deve essere calcolata lato server sul messaggio esatto:

```text
partner_id|email|timestamp|nonce
```

Esempio minimo di POST del browser verso SOS:

```html
<form id="sos-handoff-form" method="post" action="https://central.example.com/partner-login/">
  <input type="hidden" name="partner_id" value="family_happy">
  <input type="hidden" name="payload" value="maria.rossi@example.com">
  <input type="hidden" name="timestamp" value="1776410100">
  <input type="hidden" name="nonce" value="aB12Cd34Ef56">
  <input type="hidden" name="signature" value="BASE64_SIGNATURE">
</form>
<script>
  document.getElementById('sos-handoff-form').submit();
</script>
```

Questo è il comportamento da usare per il direct login Family+Happy → SOS.
