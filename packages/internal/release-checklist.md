# Checklist di rilascio — SOS Partner Gateway

Checklist pratica per il passaggio da staging a produzione. Completare in ordine.

---

## Fase 1 — Verifica pre-rilascio su staging

### Plugin centrale (SOS Partner Gateway)

- [ ] Versione plugin: `1.0.0`, DB version: `1.1`
- [ ] Tabella `sos_partner_gateway_logs` presente e struttura corretta
- [ ] Tabella `{prefix}sos_pg_booking_partner` presente con `UNIQUE KEY uq_lp_booking_id`
- [ ] Chiave pubblica ECC configurata in **Impostazioni → Chiave pubblica PEM**
- [ ] `payment_callback_secret` configurato e non vuoto
- [ ] Slug endpoint login configurato (default: `partner-login`)
- [ ] Slug endpoint callback configurato (default: `partner-payment-callback`)
- [ ] Almeno un partner configurato nel registro partner con `enabled: true`
- [ ] Per ogni partner con flusso embedded booking: `private_key_path` configurato e file leggibile dal web server
- [ ] **CRITICO** — `private_key_path`: verificare che il file esista fisicamente sul server (`is_file()` + `is_readable()`), che il processo PHP abbia i permessi di lettura, e che il path sia assoluto. Un path errato non genera errore visibile in admin ma causa fallimento silenzioso del flusso embedded in produzione.
- [ ] Flag `debug_logging_enabled` attivo su staging per monitoraggio iniziale
- [ ] Nessuna chiave privata PEM salvata nel DB (campo `private_key_pem` vuoto per tutti i partner in produzione)

### Plugin partner (SOS Partner Bridge Lite) — per ogni sito partner

- [ ] Versione plugin: `0.1.0`
- [ ] Partner ID inserito e corrispondente a quello sul sito centrale
- [ ] URL sito centrale corretto (HTTPS, senza slash finale)
- [ ] Shared secret inserito e allineato con il centrale
- [ ] Modalità di integrazione corretta
- [ ] Test connessione: HTTP 200 ✅
- [ ] Test handoff: HTTP 403 `sos_pg_handoff_forbidden` ✅
- [ ] Test callback (se applicabile): HTTP 400 "Dati mancanti" ✅
- [ ] Sidebar "Stato configurazione" mostra **Plugin pronto**
- [ ] Flag Debug disattivato

---

## Fase 2 — Verifica funzionale staging

- [ ] Flusso handoff login end-to-end: utente loggato sul centrale → redirect al partner con token → token verificato correttamente
- [ ] Token scaduto rifiutato (attendere > 300s e riprovare)
- [ ] Payment callback end-to-end: POST con payload reale → stato prenotazione aggiornato sul centrale
- [ ] Payment callback con HMAC errato: HTTP 401 atteso
- [ ] Payment callback con `booking_id` inesistente: HTTP 404 atteso
- [ ] Payment callback con `partner_id` mismatch: HTTP 403 atteso
- [ ] Log eventi visibili in admin del plugin centrale per tutti i flussi testati
- [ ] Nessun errore PHP nei log di WordPress durante i flussi

---

## Fase 3 — Configurazione produzione

### Sito centrale

- [ ] HTTPS attivo con certificato valido
- [ ] `payment_callback_secret` diverso da quello usato su staging
- [ ] Shared secret dei partner diversi da quelli usati su staging
- [ ] `debug_logging_enabled` valutare se mantenere attivo (log verbosi) o disattivare
- [ ] Accesso diretto ai file di chiave privata ECC bloccato dal web server (es. regola `.htaccess` o Nginx per la directory)
- [ ] File di chiave privata ECC fuori dalla document root, oppure protetti da regola che nega l'accesso HTTP diretto
- [ ] Backup del DB effettuato prima dell'aggiornamento

### Siti partner

- [ ] URL sito centrale aggiornato all'URL di produzione
- [ ] Shared secret aggiornato al valore di produzione
- [ ] I tre test eseguiti di nuovo sull'ambiente di produzione
- [ ] Flag Debug disattivato

---

## Fase 4 — Post-rilascio (prime 24 ore)

- [ ] Monitorare la tabella `sos_partner_gateway_logs` per eventi `WARN` o `DEBUG` inattesi
- [ ] Verificare che i primi flussi reali in produzione completino senza errori
- [ ] Verificare che i payment callback reali aggiornino correttamente `payment_status` nella tabella `sos_pg_booking_partner`
- [ ] Verificare assenza di errori PHP nei log del server
- [ ] Confermare che nessuna chiave privata sia accessibile via HTTP (`curl https://sito.centrale/path/to/key.pem` deve rispondere 403 o 404)

---

## Note operative

**Rollback plugin:**
- Disattivare il plugin non rimuove le tabelle DB né le opzioni. Il rollback a una versione precedente è sicuro a livello di dati.
- Se si rimuove il plugin completamente, le tabelle `sos_partner_gateway_logs` e `{prefix}sos_pg_booking_partner` restano nel DB fino a pulizia manuale.

**Reset configurazione partner:**
- Modificare direttamente l'opzione `sos_partner_gateway_partner_configs` nel DB o tramite la pagina admin del plugin.
- Non modificare `sos_pbl_settings` direttamente nel DB del sito partner salvo emergenza: usare sempre la pagina admin.

**Secret rotation:**
1. Generare un nuovo secret
2. Aggiornare prima sul sito centrale (plugin centrale → impostazioni callback)
3. Aggiornare immediatamente dopo su tutti i siti partner (campo shared secret)
4. Eseguire il test callback per confermare il nuovo allineamento
