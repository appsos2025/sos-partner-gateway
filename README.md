# SOS Partner Gateway

Plugin WordPress per:
- login partner firmato su endpoint unico `/partner-login/`
- redirect dinamico alla pagina partner corretta dopo il login
- webhook `booking_created` verso il ricevitore partner
- callback pagamento HMAC su `/partner-payment-callback/`
- log operativi e sicurezza integrati in database
- rate limit, ban / sblocco IP e protezione replay nonce
- base riusabile per qualsiasi partner / portale esterno

## Requisiti
- WordPress 5.8+
- PHP 7.4+ con estensione `openssl`
- Plugin LatePoint (per booking, sconti e webhook; opzionale se si usa solo il gateway di login)

## Ruolo del sito: main vs partner

Il plugin supporta due modalità operative, selezionabili dall'admin in **Impostazioni → Ruolo sito**:

| Modalità | Quando usarla |
|---|---|
| **Sito principale (gateway)** — `main` | Il sito riceve i login firmati, gestisce le prenotazioni LatePoint, invia webhook ai partner e accetta i callback di pagamento. |
| **Sito partner** — `partner` | Il sito firma e invia le richieste di login al sito principale tramite shortcode o tester integrato. Non gestisce prenotazioni in proprio. |

### Campi visibili in base al ruolo

**Modalità `main` (sito principale)** — vengono mostrate tutte le impostazioni del gateway:
- Slug endpoint login, pagina di cortesia, debug log
- Rate limit breve e lungo (tentativi, finestre temporali, durata ban)
- Chiave pubblica ECC (PEM) per la verifica firma
- Slug e secret callback pagamento partner
- Stato di successo pagamento
- Shortcode self-use: Partner ID, URL endpoint login, chiave privata ECC

**Modalità `partner` (sito partner)** — vengono mostrati solo i campi necessari lato partner:
- Partner ID
- URL endpoint login (sito principale)
- Chiave privata ECC (PEM) per firmare le richieste
- URL webhook in entrata (generato automaticamente: `/?sos_pg_webhook=1`) e secret HMAC
- URL callback pagamento (sito principale) e secret

I campi esclusivi del sito principale (rate limit, chiave pubblica, slug callback, ecc.) sono nascosti in modalità partner perché non hanno rilevanza su un sito che non gestisce prenotazioni direttamente.

### Cambio ruolo e salvataggio

Dopo aver modificato il selettore **Ruolo sito** è necessario cliccare **Salva impostazioni** per applicare la nuova modalità. Il menu di amministrazione cambia di conseguenza:

- `main`: menu con Log, Impostazioni, Pagine Partner, Test pagamento
- `partner`: menu con Impostazioni e Tester

Il redirect dopo il salvataggio punta alla pagina corretta in base al ruolo attivo:
- Modalità `partner` → `admin.php?page=sos-partner-gateway&msg=saved`
- Modalità `main` → `admin.php?page=sos-partner-gateway-settings&msg=saved`

### Preservazione dei campi partner durante il cambio ruolo

Quando si passa da `main` a `partner` (o viceversa) il form visualizzato appartiene al vecchio ruolo e non contiene i campi esclusivi del nuovo ruolo. Per evitare di azzerare involontariamente i valori già configurati, il plugin usa controlli `isset()` sui campi partner-only prima di aggiornarli:

- I tre campi `partner_webhook_secret`, `partner_callback_url`, `partner_callback_secret` vengono scritti nel database **solo se presenti nel POST** ricevuto.
- Se il POST proviene dal form del sito principale (cambio ruolo `main → partner`), questi campi sono assenti → i valori già salvati vengono conservati.
- Se il POST proviene dal form del sito partner, i campi sono presenti → vengono aggiornati normalmente, inclusa la cancellazione esplicita (campo presente ma vuoto).

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

## Flusso confermato
1. Il partner prepara lato server una POST firmata verso l'endpoint pubblico `/partner-login/`
2. I campi richiesti sono: `partner_id`, `payload` (email), `timestamp`, `nonce`, `signature`
3. Il plugin verifica firma PEM, finestra temporale di 120 secondi, replay nonce e rate-limit IP
4. L'utente WordPress viene creato o recuperato e il `partner_id` viene salvato nel profilo utente
5. L'utente viene reindirizzato alla pagina partner configurata e completa la prenotazione nel browser
6. Alla creazione booking LatePoint, il plugin risolve il partner e invia il webhook `booking_created` al ricevitore partner se configurato
7. Il partner invia il callback di pagamento firmato al sito `main`, che aggiorna il booking LatePoint lato centrale marcando `payment_status=paid` e impostando lo stato finale configurato localmente, ad esempio `pagato`

> Per partner come Family+Happy il flusso principale è diretto verso `/partner-login/`. L'endpoint embedded booking resta opzionale e non è richiesto per questo scenario.

## Sicurezza e logging operativi
- Firma partner verificata con chiave pubblica PEM configurata per il partner
- Messaggio firmato atteso: `partner_id|email|timestamp|nonce`
- Finestra timestamp attiva: 120 secondi
- Protezione replay nonce attiva
- Rate limit breve e lungo con ban temporaneo IP
- Gli eventi critici di sicurezza e operativi restano loggati anche se i log debug/sviluppo sono disattivati
- Il toggle log in admin riduce solo i log rumorosi di debug, non disattiva la tracciatura di fail, ban, blocked, unlock e successi critici

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

### Comportamento reale verificato
- Il webhook non è limitato in modo stretto al solo caso `pay_on_partner=true`.
- Nel codice reale parte quando il booking risulta collegato a un partner valido e quel partner ha un `webhook_url` configurato.
- Questo rende il flusso più permissivo di quanto si possa intuire leggendo solo la descrizione funzionale.

## Callback pagamento
- Endpoint: slug configurabile in Impostazioni (default `/partner-payment-callback`)
- Autenticazione: header `X-SOSPG-Signature` = HMAC SHA256 sul raw body JSON usando il secret configurato.
- Payload minimo accettato:
- `booking_id` (obbligatorio)
- `status` (facoltativo; può essere inviato dal partner ma non è la sorgente di verità finale)
- `transaction_id` (facoltativo)
- `partner_id` (facoltativo)
- Effetto: il sito `main` aggiorna la prenotazione LatePoint lato centrale con `status = payment_success_status` (default `pending`) e `payment_status = paid`, logga `PAYMENT_CALLBACK_OK`.

### Comportamento reale verificato
- La callback aggiorna il booking sul sito `main`, non sul sito partner.
- Lo stato finale effettivamente applicato dipende principalmente da `payment_success_status` salvato nelle impostazioni del main.
- Il valore `status` nel payload partner può essere presente ma non sostituisce automaticamente la configurazione locale del main.
- Il corretto funzionamento dipende fortemente dall’allineamento tra ambiente, base URL, slug callback e secret HMAC: una configurazione errata può mischiare staging e produzione o colpire l’endpoint sbagliato.

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
