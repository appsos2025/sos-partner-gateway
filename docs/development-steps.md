
# Step di sviluppo da aggiornare sempre

## Fase 1 — Base partner gateway
- [x] Plugin autonomo, non dipendente dal tema
- [x] Endpoint unico `/partner-login/`
- [x] Firma ECC
- [x] Log e sblocco IP
- [x] Protezione pagine partner
- [x] Redirect dinamico per partner

## Fase 2 — Webhook partner e pagamenti
- [x] Webhook per-partner `booking_created` con payload minimo e HMAC header `X-SOSPG-Signature`
- [x] Log dedicati per invio/errore webhook
- [x] Salvataggio `partner_id` e `location_id` (posizione LatePoint) nei meta prenotazione
- [x] Callback pagamento HMAC → stato configurabile + `payment_status=paid`
- [x] Tester callback pagamento da admin
- [x] `location_id` nel payload webhook (sostituisce il campo custom `cf_910bA88i`)

## Fase 4 — Shortcode [sos_partner_prenota] (multi-portale self-service)
- [x] Impostazioni: `self_login_private_key_pem` (chiave privata ECC per firma self-use)
- [x] Impostazioni: `self_login_partner_id` (partner ID di default per lo shortcode)
- [x] Mini-endpoint `/?sos_pg_book_now=1` — accetta POST, firma la richiesta lato server, auto-POST a `/partner-login/`
- [x] Shortcode `[sos_partner_prenota partner_id="..." label="..." email_field="yes/no"]`
  - Mostra campo email se visitatore non loggato
  - Usa email WP se utente già loggato
  - Mostra avviso admin se chiave privata o partner_id non configurati
  - Warning inline se invio fallisce (email non valida, chiave mancante, ecc.)

- [x] Pagina dedicata `/prenotazioni-fh/`
- [x] Stato LatePoint dedicato `F+H`
- [x] Location LatePoint dedicata `F+H` (usata per differenziare partner)
- [x] Servizi video consulto dedicati
- [x] Quota da versare = 0
- [x] Email differenziate per stato/servizio/posizione
- [x] Parametri ingresso da F+H
- [x] Callback booking verso F+H
- [x] Callback esito pagamento da F+H

## Fix — Preservazione campi partner durante cambio ruolo

- [x] `isset()` guard su `partner_webhook_secret`, `partner_callback_url`, `partner_callback_secret` in `handle_save_settings()`: i campi vengono scritti nel database solo se presenti nel POST, evitando l'azzeramento involontario durante il cambio ruolo `main → partner`.
- [x] Redirect post-salvataggio dinamico: punta a `sos-partner-gateway` (modalità partner) o `sos-partner-gateway-settings` (modalità principale), evitando la schermata "Non autorizzato" dopo il cambio ruolo.

