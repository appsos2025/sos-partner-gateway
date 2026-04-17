# SOS Partner Gateway — Hardening Execution Checklist

Checklist esecutiva derivata dal piano di hardening minimo.
Obiettivo: applicare gli interventi in ordine pratico, con rischio basso e massima compatibilità.

Nessuna modifica al codice applicata in questo documento.

---

## Ordine di esecuzione consigliato

1. Hardening ambiente/configurazione — warning non bloccanti
2. Hardening stato/workflow — validazione visiva di `payment_success_status`
3. Hardening business — marker di booking partner-managed
4. Hardening business — gate warning-only sulla callback
5. Hardening business — modalità strict opzionale per callback e webhook

Dipendenza generale:
- le fasi 1 e 2 sono indipendenti e ad altissimo valore diagnostico
- la fase 3 prepara i dati necessari per la fase 4
- la fase 5 va fatta solo dopo aver osservato il comportamento reale con le fasi precedenti

---

## 1) Fase: Warning ambiente

### Obiettivo
Rendere immediatamente visibili errori di configurazione tra staging e produzione senza cambiare il runtime.

### File e funzioni coinvolte
- `includes/class-sos-pg-plugin.php`
- `render_settings_page()`
- `render_partner_configs_page()`
- `render_test_payment_page()`
- `current_payment_callback_path()`
- `current_completion_path()`

### Modifica minima prevista
Aggiungere warning e riepiloghi informativi in admin per mostrare:
- host del sito corrente
- callback URL effettivo
- webhook URL partner
- completion URL effettivo
- mismatch tra host e path

### Rischio di regressione
- **Molto basso**

### Test da eseguire subito dopo
- aprire la pagina impostazioni in ambiente main
- aprire la pagina config partner
- verificare che gli URL finali mostrati coincidano con l’ambiente atteso
- verificare che eventuali warning compaiano senza impedire il salvataggio

### Criterio di successo
L’admin riesce a capire a colpo d’occhio se callback, webhook e completion stanno puntando all’ambiente giusto.

### Stato fase
- **Consigliata**

### Dipendenze
- nessuna

---

## 2) Fase: Warning stato LatePoint

### Obiettivo
Ridurre i casi in cui `payment_success_status` è impostato a uno slug che non attiva i workflow desiderati.

### File e funzioni coinvolte
- `includes/class-sos-pg-plugin.php`
- `render_settings_page()`
- `handle_save_settings()`
- `handle_payment_callback()`

### Modifica minima prevista
Aggiungere validazione assistita e notice non bloccanti per:
- slug esistente in LatePoint
- slug sconosciuto o potenzialmente incoerente
- distinzione visiva tra stato ricevuto dal partner e stato applicato dal main

### Rischio di regressione
- **Molto basso**

### Test da eseguire subito dopo
- salvare uno slug valido e verificare che il warning scompaia
- salvare uno slug volutamente non valido e verificare la comparsa del warning
- eseguire un test callback e controllare che il pannello sia coerente con il comportamento osservato

### Criterio di successo
L’admin capisce subito se lo stato finale configurato è compatibile con LatePoint.

### Stato fase
- **Consigliata**

### Dipendenze
- nessuna

---

## 3) Fase: Marker partner-managed

### Obiettivo
Distinguere in modo esplicito un booking semplicemente associato a un partner da un booking davvero gestito economicamente dal partner.

### File e funzioni coinvolte
- `includes/class-sos-pg-plugin.php`
- `handle_booking_created()`
- `upsert_partner_booking_record()`
- eventuale record SOS già persistito

### Modifica minima prevista
Persistire un marker semplice e leggibile per i booking partner-managed, usando in prima battuta:
- `partner_charge > 0` come evidenza primaria
- fallback a `pay_on_partner=true` per compatibilità legacy

### Rischio di regressione
- **Basso**

### Test da eseguire subito dopo
- creare un booking con pagamento partner abilitato e verificare che il marker venga salvato
- creare un booking partner-linked ma non partner-managed e verificare che il marker non venga segnato come attivo
- controllare i log e il record SOS risultante

### Criterio di successo
Ogni booking partner-managed è distinguibile in modo affidabile dai booking solo notificati al partner.

### Stato fase
- **Consigliata**

### Dipendenze
- nessuna tecnica forte, ma è raccomandata prima delle fasi 4 e 5

---

## 4) Fase: Callback warning-only

### Obiettivo
Aggiungere un controllo business reale alla callback senza bloccare ancora i flussi legacy.

### File e funzioni coinvolte
- `includes/class-sos-pg-plugin.php`
- `handle_payment_callback()`

### Modifica minima prevista
Dopo i controlli già esistenti su HMAC, partner, location e duplicati:
- verificare se il booking risulta partner-managed
- se non lo è, loggare un warning dedicato
- lasciare passare il flusso solo in modalità compatibile iniziale

### Rischio di regressione
- **Basso**

### Test da eseguire subito dopo
- inviare una callback valida su booking partner-managed e verificare esito regolare
- inviare una callback valida su booking non partner-managed e verificare che venga loggato il warning
- controllare che nessun flusso legacy venga interrotto in questa fase

### Criterio di successo
Il sistema inizia a segnalare le incoerenze business senza introdurre blocchi prematuri.

### Stato fase
- **Consigliata**

### Dipendenze
- dipende idealmente dalla fase 3

---

## 5) Fase: Strict callback mode

### Obiettivo
Bloccare davvero le callback su booking che non risultano partner-managed, ma solo quando l’installazione è pronta.

### File e funzioni coinvolte
- `includes/class-sos-pg-plugin.php`
- `render_settings_page()`
- `handle_save_settings()`
- `handle_payment_callback()`

### Modifica minima prevista
Aggiungere un setting opzionale di strict mode che:
- rifiuta la callback se manca il marker business
- mantiene il comportamento legacy se la modalità strict non è attivata

### Rischio di regressione
- **Medio**

### Test da eseguire subito dopo
- attivare strict mode in staging
- verificare che i booking partner-managed continuino a chiudersi correttamente
- verificare che i booking non partner-managed vengano rifiutati
- controllare che i log spieghino chiaramente il motivo del rifiuto

### Criterio di successo
Solo i booking realmente delegati al partner possono completare il flusso callback in modalità strict.

### Stato fase
- **Opzionale**, ma fortemente raccomandata dopo validazione in staging

### Dipendenze
- dipende dalla fase 3
- idealmente preceduta dalla fase 4

---

## 6) Fase: Strict webhook mode

### Obiettivo
Limitare l’invio del booking webhook ai soli casi in cui il partner deve davvero gestire il pagamento, senza rompere chi usa il webhook come notifica generale.

### File e funzioni coinvolte
- `includes/class-sos-pg-plugin.php`
- `handle_booking_created()`
- `send_partner_webhook()`
- `render_settings_page()`
- `handle_save_settings()`

### Modifica minima prevista
Aggiungere una modalità opzionale che invii il webhook solo se:
- il partner è risolto correttamente
- il booking è marcato come partner-managed

### Rischio di regressione
- **Medio**

### Test da eseguire subito dopo
- con strict disattivato: verificare che il comportamento legacy resti invariato
- con strict attivato: verificare che il webhook parta solo per booking partner-managed
- controllare che i partner che usano il webhook solo come notifica siano stati allineati prima di attivare la modalità

### Criterio di successo
Il partner riceve solo i booking che deve davvero processare economicamente, quando la modalità strict è attiva.

### Stato fase
- **Opzionale**

### Dipendenze
- dipende dalla fase 3
- consigliata dopo la fase 5

---

## 7) Fase: Verifica finale di regressione

### Obiettivo
Confermare che l’hardening introdotto non abbia rotto login, booking, callback, completion e fake payment.

### File e funzioni coinvolte
- flussi runtime del plugin e del bridge partner
- `handle_partner_login()`
- `handle_booking_created()`
- `handle_payment_callback()`
- `get_partner_completion_url()`
- fake payment bridge

### Modifica minima prevista
Nessuna nuova modifica funzionale: solo verifica finale integrata.

### Rischio di regressione
- **Nullo** come intervento, ma essenziale come validazione

### Test da eseguire subito dopo
1. login partner su ambiente corretto
2. creazione booking con partner associato
3. caso con `pay_on_partner=true`
4. caso con `pay_on_partner=false`
5. callback pagamento valida
6. fake payment end-to-end
7. redirect completion finale

### Criterio di successo
Tutti i flussi essenziali continuano a funzionare, mentre i casi ambigui risultano visibili o bloccati solo dove previsto.

### Stato fase
- **Consigliata**

### Dipendenze
- da eseguire dopo ogni fase importante e sicuramente al termine delle fasi 4, 5 e 6

---

## Sintesi operativa

### Prime fasi a massimo valore e minimo rischio
- Fase 1 — Warning ambiente
- Fase 2 — Warning stato LatePoint
- Fase 3 — Marker partner-managed
- Fase 4 — Callback warning-only

### Fasi opzionali di enforcement
- Fase 5 — Strict callback mode
- Fase 6 — Strict webhook mode

### Raccomandazione finale
Per una prima release sicura:
- implementare subito le fasi 1, 2, 3 e 4
- portare le fasi 5 e 6 in staging
- attivarle in produzione solo dopo verifica sui partner reali
