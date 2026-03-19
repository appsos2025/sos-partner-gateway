
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

## Fase 3 — F+H
- [x] Pagina dedicata `/prenotazioni-fh/`
- [x] Stato LatePoint dedicato `F+H`
- [x] Location LatePoint dedicata `F+H` (usata per differenziare partner)
- [x] Servizi video consulto dedicati
- [x] Quota da versare = 0
- [x] Email differenziate per stato/servizio/posizione
- [x] Parametri ingresso da F+H
- [x] Callback booking verso F+H
- [x] Callback esito pagamento da F+H
