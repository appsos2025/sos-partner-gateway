# SOS Partner Gateway — Function Map

Documento operativo basato sul comportamento verificato nel codice reale.
Obiettivo: avere una mappa rapida di dove entra ogni flusso e quali opzioni lo influenzano.

## Regola generale

Il plugin cambia comportamento in base a `site_role`:

- `main`: registra login centrale, protezione pagine, booking hooks LatePoint, callback pagamento, logica sconti e test pagamento.
- `partner`: registra shortcode/tester partner, webhook listener locale e strumenti di invio verso il centrale.
- Alcuni endpoint di completion e monitor restano disponibili in entrambi i ruoli.

---

## Funzioni principali del plugin centrale

| Funzione | File | Cosa fa realmente | Flussi coinvolti | Opzioni / variabili chiave |
|---|---|---|---|---|
| `__construct()` | `includes/class-sos-pg-plugin.php` | Registra hook, menu, endpoint e filtri in modo diverso tra ruolo `main` e ruolo `partner`. | tutti | `site_role` |
| `is_partner_mode()` | `includes/class-sos-pg-plugin.php` | Restituisce se il sito corrente lavora come partner. È il gate principale dei rami runtime. | tutti | `site_role` |
| `handle_partner_login()` | `includes/class-sos-pg-plugin.php` | Riceve il login firmato sul sito main, verifica la richiesta e prepara sessione / redirect partner. | login partner | endpoint login, chiavi ECC, partner registry |
| `protect_partner_pages()` | `includes/class-sos-pg-plugin.php` | Impedisce l’accesso a pagine partner senza sessione e contesto corretto. | login partner, completion/redirect | sessione partner, `partner_id` |
| `is_partner_context()` | `includes/class-sos-pg-plugin.php` | Decide se la richiesta corrente appartiene a un contesto partner valido usando endpoint, referrer, sessione, pagina o booking location. | booking_created, payment callback, completion/redirect | `site_role`, partner session, location mapping |
| `apply_partner_discount()` | `includes/class-sos-pg-plugin.php` | Quando il booking è in contesto partner e il partner deve incassare lui, porta il totale lato main a zero e conserva l’importo originario. | booking_created, payment | `pay_on_partner` |
| `handle_booking_created()` | `includes/class-sos-pg-plugin.php` | Parte alla creazione di una prenotazione LatePoint, risolve il partner, salva il record SOS interno e invia il webhook `booking_created`. | booking_created | `pay_on_partner`, `webhook_url`, partner/location mapping |
| `send_partner_webhook()` | `includes/class-sos-pg-plugin.php` | Invia il JSON firmato al webhook del partner; se manca URL logga e salta. | webhook partner, booking_created | `webhook_url`, secret HMAC |
| `handle_payment_callback()` | `includes/class-sos-pg-plugin.php` | Riceve la callback HMAC dal partner sul sito main, valida booking/partner/idempotenza e aggiorna `payment_status` e stato booking lato main. | payment callback | `payment_success_status`, `current_payment_callback_path()` |
| `current_payment_callback_path()` | `includes/class-sos-pg-plugin.php` | Costruisce il path pubblico reale del callback pagamento in base allo slug impostato. | payment callback, test | slug callback configurato |
| `current_completion_path()` | `includes/class-sos-pg-plugin.php` | Restituisce il path usato dal completion monitor / redirect finale. | completion/redirect | path completion |
| `get_partner_completion_url()` | `includes/class-sos-pg-plugin.php` | Costruisce la destinazione di ritorno finale verso il partner quando la completion è pronta. | completion/redirect | `current_completion_path()`, config partner, completion return URL |
| `handle_send_payment_test()` | `includes/class-sos-pg-plugin.php` | Dal pannello admin del main invia un test verso il callback configurato. | payment callback, fake/test | `current_payment_callback_path()`, `payment_success_status` |
| `handle_partner_tester_webhook()` | `includes/class-sos-pg-plugin.php` | Listener locale lato partner/tester per ricevere webhook di booking e validare HMAC. | webhook partner | `partner_webhook_secret` |
| `tester_send_payment_callback()` | `includes/class-sos-pg-plugin.php` | Dal tester partner invia manualmente una callback di pagamento verso il main usando URL e secret salvati. | payment callback, fake/test | `partner_callback_url`, secret callback |

---

## Funzioni principali del bridge partner

| Funzione | File | Cosa fa realmente | Flussi coinvolti | Opzioni / variabili chiave |
|---|---|---|---|---|
| `build_payment_payload()` | `packages/partner-wordpress/sos-partner-bridge-lite/includes/class-sos-pbl-payment-callback.php` | Prepara il payload della callback pagamento con booking, transaction, importo e metadati opzionali. | payment callback, fake payment | `status`, `partner_id`, `external_reference` |
| `send_payment_callback()` | `packages/partner-wordpress/sos-partner-bridge-lite/includes/class-sos-pbl-payment-callback.php` | Invia la callback firmata al sito centrale concatenando base URL centrale e callback path configurato. | payment callback | `partner_callback_url` oppure `central_base_url` + path callback |
| `maybe_handle_submission()` | `packages/partner-wordpress/sos-partner-bridge-lite/includes/class-sos-pbl-fake-payment-gateway.php` | Gestisce il submit del fake payment gateway e scatena il finto successo. | fake payment | payload booking e importo |
| hook `sos_fake_payment_success` | `packages/partner-wordpress/sos-partner-bridge-lite/sos-partner-bridge-lite.php` | Dopo il fake payment crea il payload e lo spedisce al callback reale del main. | fake payment, payment callback | configurazione bridge, callback URL/path |

---

## Mappa per flusso

### 1) Login partner

Funzioni principali:
- `handle_partner_login()`
- `protect_partner_pages()`
- `is_partner_context()`

Comportamento reale:
- il login avviene sul sito `main`
- la richiesta è firmata
- il plugin salva il contesto partner e reindirizza alla pagina corretta

Punti chiave:
- dipende dal partner ID risolto correttamente
- il contesto partner può essere ripreso anche da sessione/cookie/meta

### 2) Booking created

Funzioni principali:
- `handle_booking_created()`
- `send_partner_webhook()`
- `apply_partner_discount()`

Comportamento reale:
- quando LatePoint crea il booking, il plugin prova a risolvere il partner
- se il partner esiste e ha `webhook_url`, invia il webhook `booking_created`
- se `pay_on_partner` è attivo, nel payload aggiunge `partner_charge` e marca il flusso come pagamento partner

Nota operativa:
- il webhook oggi può partire anche in scenari più ampi del solo pagamento partner, se il booking risulta partner-linked e il partner ha un URL configurato

### 3) Webhook partner in entrata

Funzioni principali:
- `handle_partner_tester_webhook()`

Comportamento reale:
- usata soprattutto in modalità partner/tester per ricevere e mostrare i booking inviati dal main
- verifica HMAC con il secret del partner

### 4) Payment callback

Funzioni principali:
- `handle_payment_callback()`
- `current_payment_callback_path()`
- `tester_send_payment_callback()`
- `build_payment_payload()`
- `send_payment_callback()`

Comportamento reale:
- il partner invia una callback firmata al sito `main`
- il main valida firma, booking, partner, location e duplicati
- il main aggiorna il booking LatePoint e il `payment_status`
- la sorgente di verità per lo stato finale non è il payload partner ma la configurazione locale `payment_success_status`

Nota operativa:
- il campo `status` nel payload può essere presente, ma non governa da solo l’esito finale sul main

### 5) Completion / redirect

Funzioni principali:
- `current_completion_path()`
- `get_partner_completion_url()`
- completion monitor lato browser

Comportamento reale:
- il plugin usa un endpoint dedicato di completion per attendere l’allineamento backend
- il redirect finale preferisce la destinazione configurata per il partner quando valida

### 6) Fake payment

Funzioni principali:
- `maybe_handle_submission()` del fake gateway
- hook `sos_fake_payment_success`
- `send_payment_callback()` del bridge partner

Comportamento reale:
- il fake payment non aggiorna direttamente LatePoint
- simula il successo e poi invia una callback HTTP al main
- quindi testa soprattutto il trasporto callback e la configurazione ambiente, non l’intero comportamento utente di produzione

---

## Opzioni e variabili chiave

### `site_role`
- governa i rami principali del plugin
- se errato, il sito può esporre il pannello o gli endpoint sbagliati

### `pay_on_partner`
- indica se il partner incassa davvero il pagamento
- influenza sconti, totale lato main e campi extra nel payload booking
- non è l’unico discriminante per l’invio del webhook booking

### `payment_success_status`
- è la sorgente di verità locale per lo stato finale del booking dopo la callback
- se non corrisponde a uno slug LatePoint utile, workflow ed email possono non partire come atteso

### `partner_callback_url`
- usata dal tester/bridge partner per sapere dove spedire la callback pagamento
- un valore errato può far colpire staging invece di produzione o viceversa

### `webhook_url`
- URL del partner che riceve il `booking_created`
- se assente, il booking resta locale ma il partner non viene notificato

### `current_payment_callback_path()`
- determina il path pubblico reale del callback pagamento sul main
- deve essere coerente con ciò che il partner ha configurato

### `current_completion_path()`
- determina il path del completion monitor / redirect finale
- aiuta il ritorno controllato verso il partner quando il backend è pronto

---

## Comportamento atteso vs comportamento reale

| Tema | Atteso | Reale verificato |
|---|---|---|
| Booking webhook | Solo nel flusso pagamento partner | Parte quando il booking è collegato a un partner valido e il webhook è configurato |
| Stato da callback | Il partner potrebbe suggerire lo stato | Sul main prevale `payment_success_status` |
| Callback pagamento | Flusso partner → main | Confermato; l’aggiornamento del booking avviene sul main |
| Endpoint ambiente | Dovrebbero essere coerenti per ambiente | Sono molto configurabili, quindi sensibili a errori di URL/path |
