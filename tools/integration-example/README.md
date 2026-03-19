# Esempio integrazione lato partner (standalone)

Questo esempio mostra come integrare il gateway SOS su **qualsiasi piattaforma**, senza bisogno di WordPress.

## File inclusi

| File | Scopo |
|---|---|
| `webhook-receiver.php` | Riceve webhook `booking_created`, mostra le prenotazioni, invia callback pagamento |

## Come funziona

```
Gateway SOS                    Piattaforma partner
     │                                │
     │  POST /webhook?action=webhook  │
     │  payload booking_created       │
     │  + header X-SOSPG-Signature    │
     │ ─────────────────────────────► │  1. Riceve e verifica HMAC
     │                                │  2. Salva la prenotazione
     │                                │  3. Mostra all'operatore
     │                                │  4. Operatore accetta pagamento
     │  POST /partner-payment-callback│
     │  { booking_id, transaction_id }│
     │  + header X-SOSPG-Signature    │
     │ ◄───────────────────────────── │  5. Invia conferma pagamento
     │
     │  Aggiorna stato booking        │
     │  payment_status = paid         │
```

## Setup rapido

1. Copia `webhook-receiver.php` sul tuo server (PHP 7.4+, estensione `curl`)
2. Modifica le costanti di configurazione in cima al file:
   ```php
   define('SOS_WEBHOOK_SECRET', 'il-tuo-secret-condiviso-con-il-gateway');
   define('SOS_PAYMENT_CALLBACK_URL', 'https://<dominio-gateway>/partner-payment-callback/');
   define('SOS_PAYMENT_CALLBACK_SECRET', 'il-tuo-secret-per-il-callback-pagamento');
   ```
3. Configura nel gateway SOS (**Pagine Partner → Webhook partner**) l'URL:
   ```
   https://tuo-dominio/webhook-receiver.php?action=webhook
   ```
4. Accedi a `https://tuo-dominio/webhook-receiver.php` per vedere la dashboard con le prenotazioni ricevute e il pulsante **Conferma pagamento**

## Adattamento ad altre piattaforme

Il meccanismo è semplice e si adatta a qualsiasi linguaggio:

### Verifica firma HMAC (Node.js)
```js
const crypto = require('crypto');
const sig = req.headers['x-sospg-signature'];
const expected = crypto.createHmac('sha256', WEBHOOK_SECRET).update(rawBody).digest('hex');
const valid = crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected));
```

### Verifica firma HMAC (Python)
```python
import hmac, hashlib
sig = request.headers.get('X-SOSPG-Signature', '')
expected = hmac.new(WEBHOOK_SECRET.encode(), raw_body, hashlib.sha256).hexdigest()
valid = hmac.compare_digest(sig, expected)
```

### Invio callback pagamento (curl)
```bash
BODY='{"booking_id":123,"transaction_id":"TX-ABC"}'
SIG=$(echo -n "$BODY" | openssl dgst -sha256 -hmac "$CALLBACK_SECRET" -hex | awk '{print $2}')
curl -X POST https://<dominio-gateway>/partner-payment-callback/ \
     -H "Content-Type: application/json" \
     -H "X-SOSPG-Signature: $SIG" \
     -d "$BODY"
```

## Payload webhook ricevuto

```json
{
  "event": "booking_created",
  "partner_id": "<id-partner>",
  "booking_id": 42,
  "status": "pending",
  "total": 50.00,
  "service_id": 1,
  "location_id": 2,
  "start_date": "2026-04-01",
  "start_time": "10:00",
  "customer_email": "utente@esempio.it"
}
```

> **Nota**: `location_id` è l'ID della posizione LatePoint usato per identificare il partner sul sistema SOS. Ogni partner ha la propria location dedicata.

## Payload callback pagamento da inviare

```json
{
  "booking_id": 42,
  "transaction_id": "TX-XXXXXXXXXX"
}
```

Header obbligatorio: `X-SOSPG-Signature: <hmac-sha256-del-body-con-il-secret>`
