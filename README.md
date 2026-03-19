# SOS Partner Gateway

Plugin WordPress per:
- login partner firmato ECC su endpoint unico `/partner-login/`
- protezione singole pagine partner
- redirect dinamico alla pagina partner corretta
- log integrati in database
- ban / sblocco IP
- configurazione centralizzata per partner pages
- base riusabile per qualsiasi partner / portale esterno

## Requisiti
- WordPress 5.8+
- PHP 7.4+ con estensione `openssl`
- Plugin LatePoint (per booking, sconti e webhook; opzionale se si usa solo il gateway di login)

## Installazione
1. Carica la cartella `sos-partner-gateway` in `wp-content/plugins/`.
2. Attiva il plugin da **Plugin → Plugin installati**.
3. Vai su **SOS Partner Gateway → Impostazioni** e inserisci la **chiave pubblica ECC** (formato PEM) del partner.
4. Configura slug endpoint, rate limit e callback pagamento secondo le esigenze.

## Generare una coppia di chiavi ECC (per il partner)
```bash
# Chiave privata P-256
openssl ecparam -name prime256v1 -genkey -noout -out private.pem

# Chiave pubblica corrispondente (da incollare nelle impostazioni WordPress)
openssl ec -in private.pem -pubout -out public.pem
```
> **Attenzione**: conserva `private.pem` solo sul server del partner. Non committare mai i file PEM in un repository.

## Flusso
1. Il partner invia una POST firmata all'endpoint pubblico `/partner-login/`
2. Il plugin verifica firma, timestamp, nonce e rate-limit
3. L'utente viene creato o recuperato
4. Il plugin salva `partner_id` e reindirizza alla pagina partner configurata
5. La pagina partner è accessibile solo se l'utente è autenticato e ha il `partner_id` corretto
6. Alla creazione booking LatePoint, il plugin invia un webhook per-partner con payload minimale e HMAC
7. Il partner invia il callback di pagamento firmato per marcare la prenotazione come `payment_status=paid` e stato configurato

## Uso su più portali (multi-portal)
Il plugin può essere installato su **qualsiasi sito WordPress con LatePoint** che vuole gestire partner esterni. Per ogni nuova installazione:
1. Genera una nuova coppia di chiavi ECC per quel portale.
2. Installa e attiva il plugin.
3. Incolla la chiave pubblica nelle impostazioni.
4. Aggiungi i partner con i rispettivi `partner_id`, routing, sconti e webhook.

Ogni portale ha la propria chiave, propri partner e propri log — tutto isolato e indipendente.

## Webhook booking_created (per-partner)
- Configurazione: menu Partner Gateway → Pagine Partner → Webhook partner. Ogni partner ha URL e secret (HMAC SHA256 su body JSON).
- Eventi inviati: solo `booking_created`.
- Header: `Content-Type: application/json`, `X-SOSPG-Signature: <hmac>`
- Payload minimo:
- `event`, `partner_id`, `booking_id`, `status`, `service_id`
- `start_date`, `start_time`
- `total`
- `customer_email`
- `location_id` (ID della posizione LatePoint associata al partner, usato per differenziare i partner)
- Logging: `BOOKING_PARTNER_HOOK`, `WEBHOOK_PARTNER_SENT`, `WEBHOOK_PARTNER_FAIL`, `WEBHOOK_PARTNER_SKIP_NO_URL`.

## Callback pagamento
- Endpoint: slug configurabile in Impostazioni (default `/partner-payment-callback`)
- Autenticazione: header `X-SOSPG-Signature` = HMAC SHA256 sul raw body JSON usando il secret configurato.
- Payload minimo accettato:
- `booking_id` (obbligatorio)
- `status` (facoltativo, se assente usa `payment_success_status` configurato)
- `transaction_id` (facoltativo)
- `partner_id` (facoltativo)
- Effetto: aggiorna la prenotazione LatePoint con `status = payment_success_status` (default `pending`) e `payment_status = paid`, logga `PAYMENT_CALLBACK_OK`.

## Tracciamento partner nei booking
- Durante la creazione prenotazione vengono scritti i meta LatePoint `partner_id` e `partner_location_id` con il valore del partner e della posizione LatePoint corrente.
- La differenziazione tra partner è gestita tramite le **posizioni LatePoint** (`location_id`): ogni partner ha una posizione dedicata.
- I cookie `sos_pg_partner_id` mantengono il partner se la sessione WordPress scade prima del checkout LatePoint.

## Tool di test e integrazione

### plugin-login-tester (WordPress)
Nella cartella `tools/partner-login-tester/` è disponibile un plugin WordPress da installare sul sito del partner per:
- Simulare il login firmato verso il gateway
- Ricevere webhook `booking_created` e visualizzarli
- Confermare il pagamento con un click direttamente dall'ultimo webhook ricevuto
Utile in fase di sviluppo/integrazione; non installare in produzione.

### integration-example (standalone, senza WordPress)
Nella cartella `tools/integration-example/` è disponibile un esempio PHP standalone per partner che **non usano WordPress**:
- Riceve e verifica il webhook `booking_created`
- Mostra una dashboard con le prenotazioni ricevute
- Permette di confermare il pagamento con un click (invia il callback HMAC al gateway)
- Include esempi per Node.js, Python e curl
Adatta questo esempio alla tua piattaforma preferita.
