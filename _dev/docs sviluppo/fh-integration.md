# F+H integration checklist

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
- Callback pagamento: endpoint `/partner-payment-callback` con secret condiviso; imposta stato `payment_success_status` (default `pending`) e `payment_status=paid`