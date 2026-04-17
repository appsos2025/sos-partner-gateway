# SOS Partner Gateway — Permissive Flow Audit

Analisi basata sul codice reale, sul function map e sul debug playbook.
Focus: punti in cui il flusso è oggi più permissivo del necessario e può creare incoerenze tra sito main e sito partner.

---

## 1) Booking webhook non limitato in modo stretto a `pay_on_partner=true`

### File e funzione
- `includes/class-sos-pg-plugin.php`
- `handle_booking_created()`
- `send_partner_webhook()`

### Comportamento reale verificato
Il booking webhook parte quando:
- il booking è riconosciuto come partner-context
- il partner è risolvibile
- esiste un `webhook_url`

Il flag `pay_on_partner` viene usato per arricchire il payload con `partner_charge` e `pay_on_partner=true`, ma non è oggi il gate che decide se inviare o no il webhook.

### Rischio reale
- Il partner può ricevere booking anche se il pagamento resta gestito sul main.
- Sistemi esterni del partner possono interpretare il booking come “da incassare” anche quando non dovrebbe.
- Si crea ambiguità tra semplice notifica booking e booking davvero partner-managed.

### Proposta di hardening compatibile
- Introdurre una modalità opzionale di invio stretto: inviare il webhook solo se il booking risulta `pay_on_partner`.
- Mantenere la compatibilità lasciando il comportamento attuale come default legacy.
- In alternativa, continuare a inviare il webhook ma aggiungere sempre un campo esplicito che distingua:
  - booking notificato
  - booking con pagamento delegato al partner

---

## 2) Payment callback senza verifica business esplicita di booking partner-managed

### File e funzione
- `includes/class-sos-pg-plugin.php`
- `handle_payment_callback()`
- `is_partner_context()`

### Comportamento reale verificato
La callback controlla correttamente:
- HMAC
- esistenza booking
- partner match
- location match
- duplicate transaction
- already paid
- external reference mismatch

Non verifica però in modo esplicito che quel booking sia davvero un booking con pagamento gestito dal partner.

### Rischio reale
- Un partner validamente associato al booking può chiudere il flusso di pagamento anche quando il booking non doveva essere partner-managed.
- Questo può produrre incoerenza tra contabilità, stato prenotazione e responsabilità di incasso.

### Proposta di hardening compatibile
- Aggiungere un controllo business opzionale che richieda almeno uno di questi marker:
  - `pay_on_partner=true` nella configurazione partner
  - `partner_charge > 0` nel record SOS
  - flag persistito di booking partner-managed
- Per compatibilità, iniziare con un warning e una modalità strict attivabile via setting.

---

## 3) Endpoint e URL fortemente configurabili: rischio mismatch staging / produzione

### File e funzione
- `includes/class-sos-pg-plugin.php`
- `current_payment_callback_path()`
- `current_completion_path()`
- `get_partner_completion_url()`
- `send_partner_webhook()`
- `tester_send_payment_callback()`
- `handle_send_payment_test()`
- `packages/partner-wordpress/sos-partner-bridge-lite/includes/class-sos-pbl-payment-callback.php`
- `send_payment_callback()`

### Comportamento reale verificato
I flussi dipendono da URL e path costruiti dinamicamente tramite:
- `home_url(...)`
- `partner_callback_url`
- `central_base_url`
- `payment_callback_path`
- `webhook_url`
- `completion_return_url`

### Rischio reale
- Un partner può inviare callback allo slug corretto ma sull’ambiente sbagliato.
- Un webhook può andare a staging mentre il booking è in produzione.
- I test manuali possono sembrare corretti ma colpire un host diverso da quello previsto.

### Proposta di hardening compatibile
- Mostrare in admin un riepilogo ambiente con host, path e fingerprint dei secret.
- Aggiungere warning quando host callback, host webhook e host completion non appartengono allo stesso ambiente atteso.
- Tenere il comportamento attuale, ma rendere visibile il rischio prima dell’invio.

---

## 4) Stato finale booking potenzialmente incoerente con i workflow LatePoint

### File e funzione
- `includes/class-sos-pg-plugin.php`
- `handle_payment_callback()`
- salvataggio impostazioni di `payment_success_status`

### Comportamento reale verificato
- Lo stato finale applicato dal main deriva da `payment_success_status`.
- Il campo `status` inviato dal partner non è la vera sorgente di verità.
- Esiste logging diagnostico che segnala slug non riconosciuti, ma il sistema resta sensibile a configurazioni incoerenti.

### Rischio reale
- Il partner invia uno stato “atteso”, ma LatePoint riceve un altro slug.
- Email e workflow possono non partire oppure partire sul trigger sbagliato.
- Il problema può sembrare lato callback, mentre in realtà è una divergenza di configurazione stato.

### Proposta di hardening compatibile
- Mostrare in admin un elenco guidato degli slug LatePoint disponibili.
- Lasciare il campo testo per compatibilità, ma aggiungere warning forte se il valore non corrisponde a uno slug noto.
- Separare visivamente “status ricevuto dal partner” da “status effettivamente applicato dal main”.

---

## Priorità operativa suggerita

1. Mettere un gate business opzionale sulla callback pagamento.
2. Distinguere in modo esplicito notifica booking vs booking partner-managed.
3. Rendere molto più visibile la configurazione di ambiente e endpoint.
4. Validare meglio `payment_success_status` rispetto agli slug LatePoint reali.
