# Architettura attuale — SOS Partner Gateway (documento interno)

Documento di riferimento tecnico interno. Descrive lo stato effettivo del sistema al momento della stesura.

---

## Struttura complessiva

Il sistema è composto da due plugin WordPress distinti:

```
[Sito centrale]                         [Sito partner]
SOS Partner Gateway (v1.0.0)   <---->   SOS Partner Bridge Lite (v0.1.0)
```

I due siti comunicano tramite chiamate HTTP server-to-server.

---

## Flussi principali (diagramma testuale)

### Flusso partner esterno confermato

```
Utente                Sito partner            Sito centrale
  |                       |                        |
  |--- avvio -----------> |                        |
  |                       |-- firma server-side -->|  (partner_id|email|timestamp|nonce)
  |                       |                        |
  |--- POST /partner-login/ ---------------------->|  (browser POST diretto)
  |                       |                        |-- verifica PEM partner
  |                       |                        |-- verifica timestamp 120s
  |                       |                        |-- verifica nonce replay
  |                       |                        |-- crea/recupera utente WP
  |                       |                        |-- salva partner context
  |<-- redirect pagina partner -------------------|
```

### Flussi REST handoff token

Le route `/handoff/{partner_id}` e `/handoff/verify` esistono ancora come flusso secondario/compatibilità, ma non rappresentano il percorso partner principale confermato per Family+Happy.

### Flusso payment callback

```
Utente                Sito partner            Sito centrale
  |                       |                        |
  |--- pagamento -------->|                        |
  |                       |-- POST /{callback} --->|  (JSON + X-SOSPG-Signature HMAC)
  |                       |                        |-- verifica path
  |                       |                        |-- verifica HMAC
  |                       |                        |-- verifica booking_id esiste
  |                       |                        |-- verifica partner_id match
  |                       |                        |-- update payment_status nel DB
  |                       |<-- 200 OK -------------|  (o 4xx/401 in caso di errore)
  |<-- conferma ----------|
```

### Flusso embedded booking (opzionale/interno)

```
Sito partner                              Sito centrale
    |                                          |
    |-- POST /embedded-booking/create -------->|
    |                                          |-- valida token strategy configurata
    |                                          |-- opzionalmente prepara contesto booking
    |<-- risposta handoff / dati booking ------|
```

Questo flusso resta disponibile per casi embedded, ma non è richiesto per il partner flow Family+Happy confermato in produzione.

---

## Plugin principale — SOS Partner Gateway

### Entrypoint e costanti

| Costante | Valore |
|---|---|
| `SOS_PG_FILE` | path al file principale `sos-partner-gateway.php` |
| `SOS_PG_DIR` | directory radice del plugin |
| `SOS_PG_TABLE_LOGS` | `sos_partner_gateway_logs` |
| `SOS_PG_DB_VERSION` | `1.1` |

### Struttura file principali

```
sos-partner-gateway.php
includes/
  class-sos-pg-plugin.php          — classe core principale (singleton), business logic
  core/
    class-sos-pg-settings.php      — lettura/scrittura opzioni WordPress
    class-sos-pg-partner-registry.php — registry partner con accessors normalizzati
    class-sos-pg-handoff-token.php — emissione e verifica token handoff (HMAC-SHA256)
    class-sos-pg-embedded-booking.php — gestione flusso embedded booking
    class-sos-pg-request.php       — helper per request context
  rest/
    class-sos-pg-rest-router.php   — registrazione routes REST e handlers
```

### Tabelle DB custom

#### `sos_partner_gateway_logs`

Tabella di log eventi del sistema.

| Colonna | Tipo | Descrizione |
|---|---|---|
| `id` | BIGINT PK | Auto-increment |
| `created_at` | DATETIME | Timestamp evento |
| `level` | VARCHAR(20) | INFO / WARN / DEBUG |
| `event_type` | VARCHAR(50) | Codice evento (es. `PAYMENT_CALLBACK_OK`) |
| `partner_id` | VARCHAR(191) | Partner coinvolto |
| `email` | VARCHAR(191) | Email utente coinvolto |
| `ip` | VARCHAR(64) | IP richiedente |
| `reason` | TEXT | Motivazione del log |
| `user_agent` | TEXT | User-agent della richiesta |
| `context` | LONGTEXT | JSON con dati aggiuntivi |

#### `{$wpdb->prefix}sos_pg_booking_partner` (tabella booking partner)

Tabella custom creata in `maybe_upgrade_database()` (DB version `1.1`). Tiene traccia dell'associazione booking ↔ partner.

| Colonna | Tipo | Descrizione |
|---|---|---|
| `id` | BIGINT PK | Auto-increment |
| `lp_booking_id` | BIGINT UNIQUE | ID prenotazione LatePoint (`UNIQUE KEY`) |
| `partner_id` | VARCHAR(64) | Partner della prenotazione |
| `location_id` | VARCHAR(64) | Sede/location associata |
| `payment_transaction_id` | VARCHAR(191) | ID transazione pagamento |
| `payment_external_ref` | VARCHAR(191) | Riferimento esterno gateway |
| `payment_status` | VARCHAR(20) | Stato pagamento |
| `partner_charge` | DECIMAL(10,4) | Importo addebitato al partner |
| `confirmed_at` | DATETIME | Timestamp conferma pagamento |
| `created_at` | DATETIME | Timestamp creazione record |
| `updated_at` | DATETIME | Timestamp ultimo aggiornamento |

### Fallback legacy per booking_meta

Per le prenotazioni create prima dell'introduzione della tabella custom (fase 1), il sistema usa un fallback:

```php
$wpdb->get_var(
    $wpdb->prepare(
        "SELECT meta_value FROM {$this->booking_meta_table}
         WHERE object_id = %d AND meta_key = %s LIMIT 1",
        $booking_id, 'partner_id'
    )
);
```

Questo garantisce retrocompatibilità senza migrazione forzata dei dati storici.

### Opzioni WordPress usate

| Opzione | Descrizione |
|---|---|
| `sos_partner_gateway_settings` | Impostazioni principali del plugin (JSON) |
| `sos_partner_gateway_partner_configs` | Configurazioni per-partner (JSON) |
| `sos_pg_db_version` | Versione schema DB installata |

### Routes REST registrate (`/wp-json/sos-pg/v1/`)

| Method | Path | Handler | Auth |
|---|---|---|---|
| GET | `/health` | `handle_health` | public + context check |
| GET | `/session` | `handle_session` | public + context check |
| GET | `/partners/{partner_id}` | `handle_partner_lookup` | public + context check |
| GET | `/handoff/verify` | `handle_handoff_verify` | public + context check |
| GET | `/handoff/{partner_id}` | `handle_handoff_issue` | public + context check + user login |
| GET | `/embedded-booking/debug/{partner_id}` | `handle_embedded_booking_debug` | `manage_options` |
| GET | `/embedded-booking/verify/{partner_id}` | `handle_embedded_booking_verify` | `manage_options` |
| POST | `/embedded-booking/create` | `handle_embedded_booking_create` | public + context check — opzionale/interno, non main flow Family+Happy |

**Context check (`ensure_partner_rest_context`):** tutte le route pubbliche verificano che la richiesta provenga da un contesto partner riconosciuto (URL, cookie, referer, o header). Richieste da contesti sconosciuti ricevono HTTP 403.

### Endpoint non-REST

| Method | Path | Handler | Note |
|---|---|---|---|
| POST | `/{payment_callback_slug}` | `handle_payment_callback` | Hook `init`; default path: `/partner-payment-callback` |
| GET/POST | `/{endpoint_slug}` | handoff login flow | Hook `init`; default path: `/partner-login` |

---

## Gestione chiavi private — `private_key_path`

La chiave privata ECC per il flusso embedded booking è **mai salvata direttamente nel DB** (in produzione). Il sistema usa la seguente logica, implementata in `SOS_PG_Partner_Registry::get_partner_private_key()`:

1. Se `private_key_path` è configurato per il partner:
   - Verifica `realpath()` + `is_file()` + `is_readable()`
   - Se valido → legge il file con `file_get_contents()` e lo usa
   - Se non leggibile → logga via `error_log()` e prosegue al fallback
2. Fallback: usa `private_key_pem` direttamente dal DB (per retrocompatibilità o ambienti di sviluppo)

**Comportamento del save handler (`handle_save_partner_configs`):**
- Se il nuovo path è valido → lo salva
- Se il nuovo path è invalido e ne esiste già uno valido → ripristina quello vecchio, non sovrascrive
- Se il nuovo path è invalido e non esiste un path valido precedente → salta il salvataggio del partner preservando l'intera config precedente; mostra messaggio di errore con `partner_id`, nome campo e codice motivo (`file non trovato` / `il percorso non punta a un file` / `file non leggibile`)
- I campi `private_key_pem` e `self_login_private_key_pem` sono **mai accettati dal POST**: le textarea admin sono blank, il save handler li ignora (preserve-on-empty)

---

## Plugin partner — SOS Partner Bridge Lite

### Costanti

| Costante | Valore |
|---|---|
| `SOS_PBL_FILE` | path al file principale |
| `SOS_PBL_DIR` | directory radice del plugin |
| `SOS_PBL_VERSION` | `0.1.0` |

### Struttura file

```
sos-partner-bridge-lite.php
includes/
  class-sos-pbl-plugin.php          — bootstrap singleton, activation hook
  class-sos-pbl-config.php          — settings storage (option: sos_pbl_settings)
  class-sos-pbl-settings-page.php   — pagina admin, handler test, handler salvataggio
  class-sos-pbl-central-client.php  — HTTP client verso il sito centrale
  class-sos-pbl-handoff-service.php — placeholder handoff service
  class-sos-pbl-payment-callback.php— placeholder payment callback sender
```

### Opzione WordPress

`sos_pbl_settings` — oggetto JSON con i seguenti campi:

| Campo | Default | Descrizione |
|---|---|---|
| `partner_id` | `''` | ID partner assegnato dal centrale |
| `central_base_url` | `''` | URL base sito centrale |
| `integration_mode` | `handoff_login` | Modalità attiva: `handoff_login`, `embedded_booking`, `payment_callback`, `combined` |
| `shared_secret` | `''` | Secret condiviso per autenticazione e HMAC |
| `handoff_endpoint_path` | `/wp-json/sos-partner/v1/handoff` | Path endpoint handoff sul centrale |
| `payment_callback_path` | `/partner-payment-callback` | Path endpoint callback pagamento |
| `embedded_entrypoint_path` | `/wp-json/sos-partner/v1/embedded-booking` | Path endpoint embedded booking |
| `debug_enabled` | `0` | Attiva log tecnici |

### Transient usati

| Transient key | TTL | Contenuto |
|---|---|---|
| `sos_pbl_test_connection_result_{user_id}` | 120s | Risultato test connessione |
| `sos_pbl_test_handoff_result_{user_id}` | 120s | Risultato test handoff |
| `sos_pbl_test_callback_result_{user_id}` | 120s | Risultato test callback |

---

## Stato dei test implementati e flussi verificati

### Test dalla pagina admin del plugin partner

| Test | Endpoint chiamato | Esito atteso "successo" | Cosa verifica |
|---|---|---|---|
| **Test connessione** | `GET /wp-json/sos-pg/v1/health` | HTTP 200 | Rete OK, plugin centrale attivo |
| **Test handoff** | `GET /wp-json/sos-pg/v1/handoff/{partner_id}` | HTTP 403 `sos_pg_handoff_forbidden` | Endpoint esiste, contesto partner OK, headers riconosciuti |
| **Test callback** | `POST /{payment_callback_path}` con HMAC | HTTP 400 "Dati mancanti" | Path OK, contesto OK, secret configurato, HMAC accettato |

### Limite strutturale dei test

- **Test handoff**: non verifica se `partner_id` è registrato correttamente nel registry centrale (quella verifica avviene dopo il login utente). Un 403 con codice diverso da `sos_pg_handoff_forbidden` indica un problema di configurazione più profondo.
- **Test callback**: non verifica l'aggiornamento di un booking reale (richiederebbe `booking_id > 0` e una prenotazione esistente). Il test si ferma intenzionalmente a `booking_id=0` per essere sicuro (nessun effetto collaterale sul DB).
- **Test connessione**: può restituire 403 anche con endpoint raggiungibile, se il context check fallisce per mancanza di un contesto partner riconosciuto. Questo è un falso negativo del test di connessione pura.

---

## Token handoff — struttura e firma

I token handoff sono opachi per il client. Internamente sono composti da:

```
{base64url(payload_json)}.{hmac_sha256_signature}
```

**Payload JSON:**
```json
{
  "user_id": 42,
  "email": "utente@example.com",
  "partner_id": "acme_clinic",
  "issued_at": 1712345000,
  "expires_at": 1712345300
}
```

**Firma:** `hash_hmac('sha256', base64url_payload, secret)` dove il secret è derivato da `wp_salt('auth')` se non configurato esplicitamente.

**TTL default:** 300 secondi.

---

## Note per sviluppatori

- Il plugin centrale usa un pattern singleton (`SOS_PG_Plugin::instance()`); non istanziare direttamente.
- Tutti i path vengono normalizzati con prefisso `/` da `SOS_PBL_Config::update()`.
- `SOS_PG_Partner_Registry::get_external_api_partner()` accetta partner sia di tipo `external_api` su registry principale sia da routes/webhooks legacy.
- Il context check `is_partner_context()` ha priorità: REST request → endpoint request → admin (false) → page check → verified_partner_id nel context array. I test dal pannello admin passano in modalità `is_admin()` e non possono superare il context check direttamente.
