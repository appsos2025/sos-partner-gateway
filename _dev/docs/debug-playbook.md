# SOS Partner Gateway — Debug Playbook

Playbook operativo costruito sul comportamento verificato nel codice reale.
Non descrive patch: serve per capire dove guardare quando un flusso non si allinea.

---

## 1) Email LatePoint non parte

### Sintomi
- il pagamento risulta completato ma email o workflow LatePoint non si attivano
- il booking cambia stato in modo non coerente con le automazioni previste

### Log da controllare
- `PAYMENT_CALLBACK_OK`
- eventuali log di update booking/status
- log applicativi LatePoint o processi `booking_updated`

### Funzioni coinvolte
- `handle_payment_callback()`
- `OsBookingModel->update_status(...)` lato integrazione LatePoint

### Verifiche da fare
- controllare quale valore è configurato in `payment_success_status`
- verificare che lo slug esista davvero in LatePoint
- verificare che i workflow/email LatePoint siano legati a quello stato reale
- confermare che il callback sia arrivato sul sito main corretto

### Punto permissivo o rischioso
- il partner può inviare un campo `status`, ma il main usa come verità locale `payment_success_status`; se il valore locale è inatteso, la mail non parte anche con callback riuscita

---

## 2) Callback partner non aggiorna stato

### Sintomi
- il pagamento viene confermato dal partner ma il booking resta invariato
- `payment_status` o stato prenotazione non cambiano sul main
- risposta HTTP diversa da 2xx o rifiuto per firma/contesto

### Log da controllare
- `PAYMENT_CALLBACK_OK`
- log di callback fallita o rifiutata
- log firma HMAC / partner mismatch / duplicate transaction

### Funzioni coinvolte
- `handle_payment_callback()`
- `current_payment_callback_path()`
- `send_payment_callback()`
- `tester_send_payment_callback()`

### Verifiche da fare
- controllare che URL e path del callback puntino all’ambiente giusto
- verificare che il secret HMAC lato partner e lato main coincidano
- verificare `booking_id`, `partner_id`, `location_id`, `external_reference`
- controllare che la callback arrivi al sito in ruolo `main`
- verificare se il booking è già segnato come pagato o se il transaction ID è già stato usato

### Punto permissivo o rischioso
- la flessibilità di URL e path rende facile spedire la callback sul portale sbagliato o su uno slug non allineato

---

## 3) Webhook booking non processato dal partner

### Sintomi
- la prenotazione esiste sul main ma il partner non riceve nulla
- il tester partner non mostra l’evento `booking_created`

### Log da controllare
- `BOOKING_PARTNER_HOOK`
- `WEBHOOK_PARTNER_SENT`
- `WEBHOOK_PARTNER_FAIL`
- `WEBHOOK_PARTNER_SKIP_NO_URL`

### Funzioni coinvolte
- `handle_booking_created()`
- `send_partner_webhook()`
- `handle_partner_tester_webhook()`

### Verifiche da fare
- verificare che il booking sia davvero associato a un partner risolvibile
- controllare che il partner abbia un `webhook_url` valido
- verificare che il secret HMAC in ingresso sul partner coincida
- verificare che il sito partner sia raggiungibile pubblicamente

### Punto permissivo o rischioso
- il webhook booking può partire anche fuori dal caso stretto di pagamento partner; quindi bisogna distinguere tra “partner notificato” e “partner autorizzato a incassare”

---

## 4) Fake payment diverso dalla produzione

### Sintomi
- il test fake funziona ma il caso reale no
- oppure il fake produce esiti troppo ottimistici rispetto al checkout reale

### Log da controllare
- `FAKE_PAYMENT_SUCCESS`
- `FAKE_PAYMENT_CALLBACK_SENT`
- `FAKE_PAYMENT_CALLBACK_OK`
- `FAKE_PAYMENT_CALLBACK_ERROR`

### Funzioni coinvolte
- fake gateway del bridge partner
- hook `sos_fake_payment_success`
- `send_payment_callback()`
- `handle_payment_callback()`

### Verifiche da fare
- ricordare che il fake payment simula il successo e poi invia una callback HTTP
- verificare se il problema è nel provider reale oppure già nel trasporto callback
- confrontare payload, ambiente, URL e secret tra test fake e produzione

### Punto permissivo o rischioso
- il fake payment testa molto bene la catena callback, ma non replica tutte le condizioni del provider reale o del front-end di produzione

---

## 5) Staging e produzione mischiati

### Sintomi
- webhook e callback arrivano al sito giusto solo a volte
- booking su staging aggiornati da un partner di produzione o viceversa
- errori di contesto partner apparentemente casuali

### Log da controllare
- URL destinazione dei webhook
- URL callback usati dal bridge/tester partner
- risposte HTTP e body di errore

### Funzioni coinvolte
- `send_partner_webhook()`
- `send_payment_callback()`
- `current_payment_callback_path()`
- `get_partner_completion_url()`

### Verifiche da fare
- controllare `partner_callback_url`, `central_base_url`, `payment_callback_path`
- verificare che login endpoint, callback endpoint e completion endpoint appartengano allo stesso ambiente
- controllare che i secret non siano stati copiati tra ambienti diversi

### Punto permissivo o rischioso
- il sistema è volutamente configurabile; proprio per questo un piccolo errore di URL o slug può mischiare ambienti diversi

---

## 6) Mismatch tra payload status e payment_success_status

### Sintomi
- il partner invia uno stato atteso, ma il booking finisce in uno stato diverso
- le email/workflow sembrano agganciarsi allo stato sbagliato

### Log da controllare
- payload callback ricevuto
- log di target status risolto nel callback
- `PAYMENT_CALLBACK_OK`

### Funzioni coinvolte
- `build_payment_payload()`
- `handle_payment_callback()`

### Verifiche da fare
- confrontare il valore `status` inviato dal partner con `payment_success_status` configurato sul main
- verificare quale dei due viene realmente applicato
- controllare che `payment_success_status` corrisponda allo slug LatePoint desiderato

### Punto permissivo o rischioso
- il payload partner può dare l’impressione di governare lo stato finale, ma nel runtime verificato prevale la configurazione locale del main

---

## Checklist rapida di triage

Quando un flusso non torna, controllare in quest’ordine:

1. ruolo del sito: `main` o `partner`
2. ambiente corretto: staging o produzione
3. URL e path effettivi di webhook, callback e completion
4. HMAC secret coerenti
5. partner risolto correttamente su booking e location
6. valore reale di `payment_success_status`
7. presenza nei log applicativi dei marker principali
