# Guida integrazione esterna — SOS Partner Gateway API

Questo documento è rivolto a sviluppatori che devono integrare un sistema custom (non WordPress) con il sito centrale SOS Partner Gateway.

---

## Panoramica

Il sito centrale SOS espone endpoint REST e un endpoint non-REST per i seguenti scenari di integrazione:

1. **Handoff login**: il sistema partner ottiene un token di sessione per un utente autenticato e lo invia al sito centrale per creare una sessione riconosciuta.
2. **Payment callback**: il sistema partner notifica al sito centrale la conferma di un pagamento.
3. **Embedded booking** (principalmente WordPress): il sistema partner incorpora il modulo di prenotazione del sito centrale tramite un token firmato con chiave ECC.

I flussi **handoff** e **payment callback** sono i più comuni per integrazioni custom.

---

## Autenticazione

Ogni richiesta verso il sito centrale deve includere i seguenti header:

| Header | Valore | Note |
|---|---|---|
| `X-SOS-Partner-ID` | Partner ID univoco | Formato: `[A-Za-z0-9_-]{1,64}` |
| `X-SOS-Partner-Token` | Shared secret | Valore condiviso con l'amministratore centrale |

Questi header identificano il partner e sono richiesti su tutti gli endpoint soggetti a context check.

> **Nota:** il sito centrale non usa OAuth o API key nel senso tradizionale. L'autenticazione avviene tramite context check: il sito centrale verifica che la richiesta provenga da un path riconosciuto come partner.

---

## Endpoint disponibili

### Health check

```
GET {central_base_url}/wp-json/sos-pg/v1/health
```

Verifica la raggiungibilità del sito centrale. Restituisce un payload di sistema se il contesto è valido.

**Risposta 200 (OK):**
```json
{
  "ok": true,
  ...
}
```

**Risposta 403:** contesto partner non riconosciuto.

---

### Handoff login — Issue token

```
GET {central_base_url}/wp-json/sos-pg/v1/handoff/{partner_id}
```

Richiede un token di handoff per un utente autenticato sul sito centrale. Questo endpoint è chiamato **dal sito centrale**, tipicamente dopo che un utente ha effettuato il login, per generare un token da passare al sito partner.

**Requisiti:**
- L'utente deve essere autenticato sul sito centrale (sessione WordPress attiva).
- Il `partner_id` nel path deve corrispondere a un partner abilitato nel registry del centrale.

**Risposta 200 (OK):**
```json
{
  "ok": true,
  "partner_id": "acme_clinic",
  "token": "<token_opaco>",
  "expires_at": 1712345678
}
```

**Risposta 403 `sos_pg_handoff_forbidden`:** utente non autenticato.
**Risposta 404:** partner non trovato o non abilitato.

> **Nota:** il token ha una scadenza breve (default 300 secondi). Va consumato immediatamente.

---

### Handoff login — Verify token

```
GET {central_base_url}/wp-json/sos-pg/v1/handoff/verify?token={token}
```

Oppure tramite header:

```
Authorization: Bearer {token}
```

Verifica un token di handoff precedentemente emesso. Questo endpoint è chiamato **dal sito partner** per validare il token ricevuto e ottenere i dati utente.

**Risposta 200 (OK):**
```json
{
  "ok": true,
  "user_id": 42,
  "email": "utente@example.com",
  "partner_id": "acme_clinic",
  "expires_at": 1712345678
}
```

**Risposta 401:** token mancante, non valido o scaduto.

---

### Partner lookup

```
GET {central_base_url}/wp-json/sos-pg/v1/partners/{partner_id}
```

Recupera le informazioni pubbliche di un partner registrato come `external_api`.

**Risposta 200 (OK):**
```json
{
  "ok": true,
  "partner_id": "acme_clinic",
  "type": "external_api",
  "enabled": true,
  "api_base_url": "https://partner.example.com"
}
```

**Risposta 404:** partner non trovato o non di tipo `external_api`.

---

### Session check

```
GET {central_base_url}/wp-json/sos-pg/v1/session
```

Verifica se l'utente corrente è autenticato sul sito centrale e restituisce i dati di sessione.

**Risposta 200 (utente autenticato):**
```json
{
  "logged_in": true,
  "user_id": 42,
  "email": "utente@example.com",
  "partner_id": "acme_clinic"
}
```

**Risposta 200 (utente non autenticato):**
```json
{
  "logged_in": false
}
```

---

## Payment callback

```
POST {central_base_url}/{payment_callback_slug}
```

Dove `payment_callback_slug` è il valore configurato nelle impostazioni del plugin centrale (default: `partner-payment-callback`).

> **Attenzione:** questo endpoint **non è una route REST** (`/wp-json/`). È gestito dall'hook `init` del plugin su un path WordPress personalizzato.

### Header richiesti

| Header | Valore |
|---|---|
| `Content-Type` | `application/json` |
| `X-SOSPG-Signature` | `hash_hmac('sha256', body_json, payment_callback_secret)` |

### Payload

```json
{
  "booking_id": 1234,
  "transaction_id": "txn_abc123",
  "partner_id": "acme_clinic",
  "amount_paid": 150.00,
  "currency": "EUR",
  "payment_provider": "stripe",
  "external_reference": "pi_xxx",
  "email": "utente@example.com"
}
```

| Campo | Tipo | Obbligatorio | Descrizione |
|---|---|---|---|
| `booking_id` | int | Sì | ID della prenotazione LatePoint sul sito centrale |
| `transaction_id` | string | Sì | ID transazione univoco del gateway di pagamento partner |
| `partner_id` | string | Sì | Deve corrispondere al partner registrato per quella prenotazione |
| `amount_paid` | float | No | Importo pagato |
| `currency` | string | No | Codice valuta (es. `EUR`) |
| `payment_provider` | string | No | Nome del gateway usato (es. `stripe`, `paypal`) |
| `external_reference` | string | No | Riferimento aggiuntivo del gateway |
| `email` | string | No | Email dell'utente pagante |

### Come calcolare la firma HMAC

```
signature = hash_hmac('sha256', json_body, shared_secret)
```

Header da includere nella richiesta:

```
X-SOSPG-Signature: {signature}
```

**Regole importanti:**

- `json_body` è la stringa JSON esatta inviata nel body della richiesta — non re-serializzata, non riordinata.
- `shared_secret` è il `payment_callback_secret` configurato sul plugin centrale (stesso valore inserito nel campo **Shared secret** del plugin partner).
- La firma è calcolata sull'intero body come stringa grezza, prima di inviare la richiesta.
- Il sito centrale confronta la firma con `hash_equals()` per prevenire timing attacks.

**Esempio PHP:**

```php
$body = json_encode($payload); // genera la stringa JSON
$signature = hash_hmac('sha256', $body, $shared_secret);

// Inviare nella richiesta:
// Content-Type: application/json
// X-SOSPG-Signature: {$signature}
// Body: {$body}
```

**Esempio Node.js:**

```js
const crypto = require('crypto');
const body = JSON.stringify(payload);
const signature = crypto.createHmac('sha256', sharedSecret).update(body).digest('hex');
```

Dove `raw_body_string` è il corpo JSON esatto inviato nella richiesta (stessa stringa, non re-encodata). Il secret deve coincidere con il `payment_callback_secret` configurato sul plugin centrale.

### Risposte

| HTTP | Body | Significato |
|---|---|---|
| `200` | `OK` | Callback elaborato con successo |
| `400` | `Payload non valido` | Body non è JSON valido |
| `400` | `Dati mancanti` | `booking_id`, `partner_id` o `transaction_id` mancanti o vuoti |
| `401` | `Firma non valida` | HMAC non corrisponde |
| `403` | `Contesto partner non valido` | Path non riconosciuto come endpoint callback |
| `403` | `Callback non attivato` | `payment_callback_secret` vuoto sul plugin centrale |
| `403` | `Partner mismatch per booking` | Il `partner_id` del payload non corrisponde al partner della prenotazione |
| `404` | `Prenotazione non trovata` | Il `booking_id` non esiste nel DB del centrale |

---

## Embedded booking (cenni)

Il flusso embedded booking si basa su un token firmato con **chiave privata ECC (EC P-256)**. Il sito partner deve avere una coppia di chiavi configurata nel registry del plugin centrale:

- La **chiave pubblica** è depositata nel plugin centrale (campo `public_key_pem` per quel partner).
- La **chiave privata** è usata dal sito partner per firmare i token. Deve essere mantenuta sicura e non deve mai essere trasmessa al sito centrale.

Questo flusso è principalmente destinato a siti WordPress che usano il plugin SOS Partner Bridge Lite. Per integrazioni custom, contattare l'amministratore centrale per la documentazione tecnica specifica.

---

## Errori tipici

| Sintomo | Causa probabile |
|---|---|
| 403 su qualsiasi endpoint REST | Context check fallito: partner ID non riconosciuto, o l'IP/referer non è in un contesto partner valido |
| 401 sul callback | Il secret usato per l'HMAC non corrisponde a quello configurato sul centrale |
| 404 su `/wp-json/sos-pg/v1/...` | Il plugin SOS Gateway non è attivo sul sito centrale |
| 404 su `/{slug}` | Lo slug del callback è diverso da quello configurato, o il plugin non è attivo |
| Token scaduto (handoff) | Il token ha TTL di 300 secondi: va consumato immediatamente dopo l'emissione |
| `sos_pg_handoff_invalid` | Token malformato, già usato o firmato con secret errato |

---

## Checklist sicurezza

- [ ] Il `payment_callback_secret` è generato casualmente (min 32 caratteri), non è una password memorabile
- [ ] Il `payment_callback_secret` non è mai esposto lato client o in log pubblici
- [ ] La firma HMAC è calcolata sul body raw della richiesta, non su una sua re-serializzazione
- [ ] Il `booking_id` è verificato come appartenente al partner prima di elaborare il callback
- [ ] I token di handoff vengono consumati immediatamente e non memorizzati
- [ ] La chiave privata ECC (per embedded booking) è custodita su file system con permessi restrittivi, mai nel DB
- [ ] Le chiamate verso il sito centrale avvengono server-to-server, non dal browser dell'utente
- [ ] Il sito centrale è raggiungibile solo via HTTPS
