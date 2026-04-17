# Guida integrazione server-to-server — Family+Happy → SOS

Questa guida descrive il flusso partner-facing attualmente allineato con il comportamento live del sistema SOS.

L’integrazione per Family+Happy è composta da tre passaggi:

1. **Preparazione server-side del payload firmato** da parte di Family+Happy.
2. **Browser POST diretto** verso il percorso di login dedicato di SOS.
3. **Payment callback opzionale** dal server Family+Happy verso SOS dopo il pagamento.

---

## 1. Overview

### Flusso operativo

1. Il backend Family+Happy prepara i campi necessari per il login partner.
2. Family+Happy firma lato server il messaggio composto da partner_id, email, timestamp e nonce.
3. Il browser dell’utente esegue un **POST diretto** verso il percorso di login SOS.
4. SOS verifica la firma PEM, valida timestamp e nonce, crea o recupera l’utente WordPress, imposta il contesto partner e reindirizza l’utente alla pagina corretta.
5. Se Family+Happy gestisce il pagamento lato proprio sistema, può poi inviare una **callback firmata** a SOS per confermare il pagamento.

> Nel flusso Family+Happy attuale non è richiesto alcun passaggio preliminare verso l’endpoint embedded booking.

---

## 2. Direct partner-login endpoint

### Endpoint live

```http
POST {central_base_url}/partner-login/
```

### Campi richiesti

| Campo | Obbligatorio | Note |
|---|---|---|
| `partner_id` | Sì | Identificativo partner configurato su SOS |
| `payload` | Sì | Email dell’utente |
| `timestamp` | Sì | Timestamp Unix corrente |
| `nonce` | Sì | Valore univoco per prevenire replay |
| `signature` | Sì | Firma Base64 del messaggio firmato lato partner |
| `return_url` | No | URL di ritorno partner, se usato nel flusso browser |
| `opener_origin` | No | Origine del portale partner, se utile nel flusso popup |
| `sos_pg_flow_context` | No | Contesto opzionale del flusso browser |

### Messaggio da firmare

Il messaggio da firmare deve essere esattamente:

```text
partner_id|email|timestamp|nonce
```

La firma deve essere prodotta lato server Family+Happy con la chiave privata partner e inviata nel campo `signature` in formato Base64.

### Esempio campi da inviare

```json
{
  "partner_id": "family_happy",
  "payload": "maria.rossi@example.com",
  "timestamp": 1776410100,
  "nonce": "aB12Cd34Ef56",
  "signature": "BASE64_SIGNATURE"
}
```

### Esiti principali

| HTTP | Significato |
|---|---|
| `302` | Login accettato e redirect verso la pagina partner |
| `400` | Partner, email, nonce o firma mancanti/non validi |
| `403` | Timestamp scaduto, firma non valida o replay nonce |
| `404` | Pagina/route partner non configurata |
| `429` | Rate limit temporaneo per IP |

---

## 3. Browser POST diretto

Il browser dell’utente deve inviare direttamente il form verso il login partner di SOS con i valori firmati dal backend Family+Happy.

### Esempio minimo di browser POST

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

> I campi devono essere costruiti e firmati lato server Family+Happy. Il browser deve solo inviare il POST verso SOS.

---

## 4. Security

Questa sezione riporta solo i controlli attualmente effettivi nel runtime.

### Partner login diretto

- `partner_id` deve essere presente e valido
- `payload` deve contenere una email valida
- il `timestamp` viene validato con una finestra di **120 secondi**
- il `nonce` è obbligatorio
- esiste protezione contro il replay del `nonce`
- la `signature` viene verificata con la chiave pubblica PEM configurata per il partner
- se timestamp, nonce o signature non sono validi, il login viene rifiutato
### Payment callback

- la firma usa **HMAC-SHA256** sul **corpo JSON grezzo esatto** della richiesta
- l’header da inviare è **X-SOSPG-Signature**
- la risoluzione del secret di callback è **partner-specific first**, con fallback al secret globale solo se necessario

---

## 5. Payment callback

### Endpoint

```http
POST {configured_callback_url}
```

L’URL esatto del callback viene concordato con SOS. Se non personalizzato, il percorso standard è generalmente:

```http
{central_base_url}/partner-payment-callback/
```

### Header richiesti

| Header | Valore |
|---|---|
| `Content-Type` | `application/json` |
| `X-SOSPG-Signature` | HMAC-SHA256 del body JSON grezzo |

### Regola firma

```text
signature = HMAC_SHA256(raw_json_body, callback_secret)
```

Il body deve essere firmato nella sua forma esatta, senza riordinare o rigenerare i campi tra firma e invio.

### Payload allineato al runtime

| Campo | Obbligatorio | Note |
|---|---|---|
| `booking_id` | Sì | Identificativo prenotazione su SOS |
| `transaction_id` | Sì | Identificativo univoco della transazione partner |
| `partner_id` | Sì | Deve corrispondere al partner associato alla prenotazione |
| `amount_paid` | No | Importo pagato |
| `currency` | No | Valuta |
| `payment_provider` | No | Provider di pagamento |
| `external_reference` | No | Riferimento esterno partner o gateway |
| `email` | No | Email del pagante |

### Esempio callback

```json
{
  "booking_id": 1234,
  "transaction_id": "fh_txn_20260417_001",
  "partner_id": "family_happy",
  "amount_paid": 150.0,
  "currency": "EUR",
  "payment_provider": "family_happy_pay",
  "external_reference": "FH-PAY-20260417-001",
  "email": "maria.rossi@example.com"
}
```

### Esiti principali

| HTTP | Significato |
|---|---|
| `200` | Callback elaborato con successo |
| `400` | Payload non valido o dati obbligatori mancanti |
| `401` | Firma non valida |
| `403` | Callback non attivato oppure partner non coerente con la prenotazione |
| `404` | Prenotazione non trovata |
| `409` | Transazione duplicata o conflitto su prenotazione già chiusa |

---

## 6. Identity fields

Nel flusso Family+Happy → SOS:

- `email` è obbligatoria
- per questo partner il flusso operativo usa solo l’email come identità richiesta
- `first_name`, `last_name`, `phone` e `name` non sono necessari nel flusso diretto verso il login partner

SOS non esegue sincronizzazioni dirette di un’anagrafica esterna durante il login browser.

---

## 7. Note operative

- usare sempre HTTPS
- generare timestamp, nonce e signature solo lato server Family+Happy
- usare il browser solo per il POST diretto verso `/partner-login/`
- conservare la chiave privata e il callback secret solo lato server
- non loggare i secret in chiaro

---

## 8. Checklist finale

- [ ] `partner_id` concordato e attivo su SOS
- [ ] chiave pubblica PEM del partner configurata e verificata su SOS
- [ ] firma server-side del messaggio `partner_id|email|timestamp|nonce` testata con successo
- [ ] browser POST diretto verso `/partner-login/` testato con successo
- [ ] callback secret ricevuto e custodito lato server
- [ ] payment callback testata con firma HMAC valida
- [ ] richieste eseguite solo via HTTPS
