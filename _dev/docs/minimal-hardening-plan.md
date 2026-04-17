# SOS Partner Gateway — Minimal Hardening Plan

Documento di proposta. Nessuna modifica al codice applicata.
Obiettivo: ridurre i punti permissivi individuati senza rompere i flussi esistenti.

---

## Priorità consigliata

1. Gate business esplicito sulla payment callback
2. Restrizione opzionale del booking webhook ai casi davvero partner-managed
3. Warning di configurazione per staging / produzione
4. Validazione più chiara dello stato finale LatePoint

Motivazione: il rischio più alto oggi è che un booking venga trattato come partner-managed solo perché è associato a un partner, non perché il pagamento sia davvero delegato al partner.

---

## 1) Booking webhook troppo ampio

### File e funzioni coinvolte
- `includes/class-sos-pg-plugin.php`
- `handle_booking_created()`
- `send_partner_webhook()`
- opzionalmente `render_settings_page()` e `handle_save_settings()` per esporre la modalità di invio

### Intervento minimo consigliato
Aggiungere una modalità di invio del webhook con due comportamenti:

- `legacy_notify_all_partner_bookings` → comportamento attuale
- `strict_only_partner_managed` → invio solo se il booking è realmente gestito a pagamento dal partner

Il controllo andrebbe inserito in `handle_booking_created()`, subito dopo la risoluzione del partner e prima della costruzione/invio del payload.

### Proposta concreta di hardening
- continuare a calcolare `discount_config` come oggi
- usare `pay_on_partner` come discriminante per la modalità strict
- se la modalità strict è attiva e `pay_on_partner` è falso:
  - non inviare il webhook
  - loggare un evento diagnostico dedicato

### Opzionale o default
- **Consigliato: opzionale**, con default legacy

### Impatto su compatibilità esistente
- **Molto basso** se lasciato opzionale e disattivato di default
- i partner già integrati non cambiano comportamento finché l’admin non attiva la modalità strict

### Rischio di regressione
- **Basso** in modalità opzionale
- **Medio** solo se reso default senza migrazione, perché alcuni partner oggi potrebbero dipendere dal webhook anche per semplice notifica

---

## 2) Callback senza gate business esplicito

### File e funzioni coinvolte
- `includes/class-sos-pg-plugin.php`
- `handle_payment_callback()`
- `handle_booking_created()`
- `upsert_partner_booking_record()`
- opzionalmente `render_settings_page()` e `handle_save_settings()` per la modalità strict

### Marker consigliato per distinguere booking partner-managed
Ordine consigliato di affidabilità:

1. flag persistito dedicato nel record SOS del booking, ad esempio stato di gestione pagamento partner
2. `partner_charge > 0` nel record SOS già salvato
3. fallback compatibile alla configurazione partner con `pay_on_partner=true`

Per minimizzare l’impatto, la prima versione può evitare migrazioni invasive e usare subito:
- `partner_charge > 0` come marker principale runtime
- `pay_on_partner=true` come fallback legacy

### Dove leggerlo / salvarlo
- salvarlo in `handle_booking_created()` nel momento in cui il booking viene riconosciuto come pagamento partner
- leggerlo in `handle_payment_callback()` prima dell’aggiornamento dello stato e del `payment_status`

### Dove bloccare la callback se il marker manca
Il blocco andrebbe messo in `handle_payment_callback()`, dopo i controlli di identità già presenti:
- booking esistente
- partner match
- location match

Solo dopo questi controlli, aggiungere il gate business:
- se il booking non risulta partner-managed, rifiutare la callback oppure accettarla solo in modalità legacy con warning

### Modalità consigliata
- **Fase 1**: warning-only con log dedicato
- **Fase 2**: setting opzionale strict che blocca davvero la callback non partner-managed

### Impatto sui flussi già esistenti
- con warning-only: impatto quasi nullo
- con strict mode: protegge i nuovi flussi senza rompere automaticamente i vecchi

### Rischio di regressione
- **Basso** in warning-only
- **Medio** se strict viene attivato su installazioni dove alcuni booking legacy non salvano abbastanza marker

---

## 3) Mismatch staging / produzione

### File e funzioni coinvolte
- `includes/class-sos-pg-plugin.php`
- `render_settings_page()`
- `render_partner_configs_page()`
- `render_test_payment_page()`
- `current_payment_callback_path()`
- `current_completion_path()`
- `handle_send_payment_test()`
- `tester_send_payment_callback()`
- bridge partner: `send_payment_callback()` in `class-sos-pbl-payment-callback.php`

### Intervento minimo consigliato
Aggiungere warning amministrativi non bloccanti che confrontino host e path configurati.
Nessun blocco salvataggio, nessuna rottura di compatibilità.

### Warning minimi da aggiungere in admin
1. host del sito corrente diverso dall’host presente in `partner_callback_url`
2. host del sito corrente diverso dagli host dei `webhook_url` partner attesi
3. callback path configurato sul partner diverso dal path reale costruito dal main
4. URL non HTTPS in ambienti non locali
5. `completion_return_url` su dominio diverso da quello atteso per quel partner

### Confronti host/path da fare
- `parse_url(home_url(), PHP_URL_HOST)` vs host di `partner_callback_url`
- host del main vs host di ogni `webhook_url`
- `current_payment_callback_path()` vs path configurato dal bridge partner
- path di completion reale vs link di ritorno configurati

### Come evitare configurazioni incoerenti senza rompere nulla
- mostrare badge informativi e warning in admin
- mostrare l’URL finale effettivo che il sistema userà
- mostrare fingerprint breve del secret, non il secret completo
- non impedire il salvataggio: solo rendere evidente il rischio

### Impatto su compatibilità esistente
- nullo sul runtime
- migliora solo visibilità e diagnosi

### Rischio di regressione
- **Molto basso**

---

## 4) Stato finale incoerente con LatePoint

### File e funzioni coinvolte
- `includes/class-sos-pg-plugin.php`
- `render_settings_page()`
- `handle_save_settings()`
- `handle_payment_callback()`

### Intervento minimo consigliato
Mantenere `payment_success_status` come campo libero per compatibilità, ma aggiungere validazione assistita e warning forti quando lo slug non è riconosciuto da LatePoint.

### Come validare meglio `payment_success_status`
- recuperare la lista degli slug disponibili da LatePoint quando possibile
- al salvataggio o alla render della pagina impostazioni:
  - se lo slug esiste → mostrare conferma verde
  - se non esiste → mostrare warning giallo/rosso non bloccante

### Come mostrare warning se lo slug non esiste
- admin notice nella pagina impostazioni
- nota esplicita nel pannello test pagamento
- testo chiaro che distingua:
  - stato ricevuto dal partner
  - stato realmente applicato dal main

### Come mantenere compatibilità con configurazioni custom
- non trasformare subito il campo in select obbligatoria
- mantenere il text input libero
- affiancare eventualmente una select suggerita con gli slug noti, senza forzare il valore

### Impatto su compatibilità esistente
- molto basso, perché non cambia il runtime
- migliora la consapevolezza sugli slug non validi o inattesi

### Rischio di regressione
- **Molto basso**

---

## Piano di adozione minimo

### Step 1
Attivare warning-only su callback e configurazione ambiente.

### Step 2
Aggiungere il marker esplicito di booking partner-managed in fase di creazione booking.

### Step 3
Introdurre setting opzionali:
- strict webhook mode
- strict callback business gate

### Step 4
Migliorare UI e warning per `payment_success_status`.

---

## Raccomandazione finale

Se si vuole il massimo rapporto sicurezza/compatibilità, la prima release di hardening dovrebbe:
- non bloccare nulla di default
- loggare e mostrare warning dove il flusso è ambiguo
- permettere all’admin di attivare modalità strict solo quando l’integrazione partner è pronta
