# F+H integration checklist

## Flusso confermato
1. POST diretto firmato verso `/partner-login/`
2. Prenotazione completata nel browser sulla pagina partner
3. Webhook `booking_created` inviato da SOS al ricevitore F+H
4. Callback pagamento HMAC inviata dal partner a SOS
5. Booking centrale aggiornato a `payment_status=paid` e stato finale configurato, ad esempio `pagato`

## Dominio
`https://videoconsulto.sospediatra.org`

## Endpoint login partner
`/partner-login/`

## Pagina partner prevista
`/prenotazioni-fh/`

## Configurazione pagina WordPress
- Proteggi come pagina partner = sì
- Partner ID = `fh`
- Redirect path = `/prenotazioni-fh/`
- Stato iniziale = `F+H`
- Location = `F+H`
- Webhook partner (booking_created): URL/secret forniti da F+H, payload minimo con booking_id/total/start_date/start_time/customer_email, HMAC header `X-SOSPG-Signature`
- Callback pagamento: endpoint `/partner-payment-callback` con secret condiviso; imposta `payment_status=paid` e lo stato finale configurato sul centrale, ad esempio `pagato`

## Campi minimi richiesti per il login partner diretto
- `partner_id`
- `payload` (email)
- `timestamp`
- `nonce`
- `signature`

Stringa firmata richiesta:
`partner_id|email|timestamp|nonce`

## Non richiesto per questo partner
- `embedded-booking/create`
- `validation_token_strategy=passthrough`
- campi identità aggiuntivi oltre all'email