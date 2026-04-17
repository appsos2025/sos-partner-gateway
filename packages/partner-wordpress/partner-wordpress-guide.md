# Guida partner WordPress — SOS Partner Bridge Lite

Questo documento è rivolto agli amministratori di siti WordPress partner che devono configurare l'integrazione con il sito centrale SOS.

---

## Panoramica

**SOS Partner Bridge Lite** è un plugin WordPress leggero da installare sul sito partner. Permette al sito partner di comunicare con il sito centrale SOS per i seguenti flussi:

- **Handoff login**: l'utente autenticato sul sito centrale viene riconosciuto automaticamente sul sito partner tramite un token a breve scadenza.
- **Embedded booking**: il modulo di prenotazione del sito centrale viene caricato all'interno del sito partner.
- **Payment callback**: il sito partner notifica al sito centrale la conferma di un pagamento avvenuto sul proprio gateway.

Il plugin non gestisce pagamenti, non espone dati sensibili e non modifica il comportamento delle altre pagine WordPress.

---

## Requisiti

- WordPress 5.9 o superiore
- PHP 7.4 o superiore
- Plugin **SOS Partner Gateway** installato e attivo sul sito centrale
- Le seguenti informazioni, fornite dall'amministratore del sito centrale:
  - Partner ID univoco (es. `acme_clinic`)
  - URL base del sito centrale (es. `https://central.example.com`)
  - Shared secret / token (per autenticazione e firma HMAC)

---

## Installazione

1. Scaricare il file ZIP del plugin `sos-partner-bridge-lite`.
2. Nel pannello WordPress del sito partner, andare in **Plugin → Aggiungi nuovo → Carica plugin**.
3. Selezionare lo ZIP e cliccare **Installa ora**.
4. Attivare il plugin.
5. Nel menu laterale apparirà la voce **SOS Partner Bridge**.

---

## Configurazione

Andare in **SOS Partner Bridge** nel menu WordPress. La pagina di configurazione si divide in quattro sezioni.

### Sezione 1 — Configurazione base

| Campo | Obbligatorio | Descrizione |
|---|---|---|
| **Partner ID** | Sì | Identificativo univoco del sito partner, assegnato dall'amministratore centrale. Deve corrispondere esattamente a quello registrato sul sito centrale. |
| **Integrazione attiva** | Sì | Il flusso da attivare su questo sito. Vedere sezione successiva. |

### Sezione 2 — Connessione al sito centrale

| Campo | Obbligatorio | Descrizione |
|---|---|---|
| **URL sito centrale** | Sì | Dominio base del sito SOS centrale, incluso protocollo, senza trailing slash. Esempio: `https://central.example.com` |
| **Shared secret / token** | Sì | Credenziale condivisa fornita dall'amministratore centrale. Viene usata per autenticare le richieste e per firmare i payload (callback pagamento). |
| **Debug** | No | Attiva log tecnici nel log PHP di WordPress. Da usare solo durante setup o troubleshooting. |

### Sezione 3 — Integrazione attiva

Questi campi si mostrano o nascondono automaticamente in base alla modalità scelta. La maggior parte dei valori di default sono corretti e non richiedono modifica se il sito centrale usa la configurazione standard.

| Campo | Per quale modalità | Descrizione |
|---|---|---|
| **Endpoint handoff login** | `handoff_login`, `embedded_booking`, `combined` | Path sul sito centrale che riceve la richiesta di handoff. Default: `/wp-json/sos-partner/v1/handoff` |
| **Endpoint callback pagamento** | `payment_callback`, `combined` | Path sul sito centrale che riceve la conferma di pagamento. Default: `/partner-payment-callback` (non è una route REST) |
| **Endpoint embedded booking** | `embedded_booking`, `combined` | Path del centrale usato dal flusso embedded booking. Default: `/wp-json/sos-partner/v1/embedded-booking` |

---

## Esempio configurazione minima (caso più comune)

Per un sito partner che usa tutti i flussi (`combined`), la configurazione tipica è:

| Campo | Valore |
|---|---|
| **Partner ID** | `caf_bari` |
| **Integrazione attiva** | `combined` |
| **URL sito centrale** | `https://tuosito.it` |
| **Shared secret** | fornito dall'amministratore del sito centrale |
| **Endpoint handoff login** | `/wp-json/sos-pg/v1/handoff` |
| **Endpoint callback pagamento** | `/partner-payment-callback` |
| **Endpoint embedded booking** | lasciare il valore di default o concordare con il centrale |

> I campi **Endpoint** vanno modificati solo se il sito centrale usa uno slug personalizzato. In caso di dubbio, lasciare i valori di default e chiedere conferma all'amministratore centrale.

---

## Modalità di integrazione

### `handoff_login` — Handoff login

Il flusso di base. Un utente autenticato sul sito centrale viene reindirizzato al sito partner con un token temporaneo. Il sito partner usa il token per riconoscere l'utente senza richiedere una seconda autenticazione.

**Quando usarla:** il sito partner deve accettare utenti che provengono dal flusso di login del sito centrale.

**Requisiti aggiuntivi:** nessuno oltre alla configurazione base.

---

### `embedded_booking` — Embedded booking

Il modulo di prenotazione di (presente sul sito centrale) viene incorporato all'interno di una pagina del sito partner tramite shortcode o iframe. Il sito partner trasmette il contesto utente al centrale tramite un token firmato.

**Quando usarla:** il sito partner vuole mostrare il sistema di prenotazione del centrale nella propria interfaccia, senza che l'utente debba navigare sul sito centrale.

**Requisiti aggiuntivi:** il sito centrale deve avere configurata la chiave pubblica ECC per il partner.

---

### `payment_callback` — Payment callback

Dopo che un utente completa un pagamento sul sito partner, il sito partner invia una notifica al sito centrale con i dati della transazione (booking ID, transaction ID, importo). Il sito centrale verifica la firma HMAC e aggiorna lo stato della prenotazione.

**Quando usarla:** il sito partner gestisce il pagamento in autonomia e deve notificare il sito centrale dell'avvenuto pagamento.

**Requisiti aggiuntivi:** il sito centrale deve avere configurato il `payment_callback_secret`. Lo stesso valore deve essere inserito nel campo **Shared secret** del plugin partner.

---

### `combined` — Combined

Attiva contemporaneamente handoff login, payment callback e embedded booking. I relativi endpoint sono tutti obbligatori.

**Quando usarla:** il sito partner usa tutti e tre i flussi.

---

## Test e diagnostica

Nella sezione **Test e diagnostica** della pagina admin sono disponibili tre test reali verso il sito centrale.

### Test connessione

Verifica che il sito partner riesca a raggiungere il sito centrale a livello di rete.

- **URL testato:** `{central_base_url}/wp-json/sos-pg/v1/health`
- **Metodo:** GET
- **Esito atteso positivo:** HTTP 200 (endpoint raggiungibile)
- **Esito atteso con configurazione parziale:** HTTP 403 (endpoint raggiunto ma contesto partner non ancora validato — normale in fase di setup)

---

### Test handoff

Verifica che il flusso handoff/login sia raggiungibile dal sito partner.

- **URL testato:** `{central_base_url}/wp-json/sos-pg/v1/handoff/{partner_id}`
- **Metodo:** GET
- **Header inviati:** `X-SOS-Partner-ID`, `X-SOS-Partner-Token`
- **Esito atteso positivo:** HTTP 403 con codice `sos_pg_handoff_forbidden` ("Autenticazione richiesta")

> **Attenzione:** un HTTP 403 con questo codice specifico è il risultato **corretto** del test. Significa che l'endpoint esiste, il contesto partner è valido e solo l'autenticazione utente manca, cosa normale dal pannello admin. In produzione, la chiamata arriva da un utente autenticato sul sito centrale.

---

### Test callback

Verifica che il flusso payment callback sia raggiungibile e che la firma HMAC sia accettata dal sito centrale.

- **URL testato:** `{central_base_url}{payment_callback_path}`
- **Metodo:** POST
- **Header inviati:** `Content-Type: application/json`, `X-SOSPG-Signature: {hmac_sha256}`
- **Payload inviato:** payload di test con `booking_id: 0`
- **Esito atteso positivo:** HTTP 400 ("Dati mancanti")

> **Attenzione:** un HTTP 400 è il risultato **corretto** del test. Significa che il sito centrale ha ricevuto la richiesta, ha validato il path, ha verificato la firma HMAC e ha iniziato l'elaborazione. Il rifiuto avviene perché `booking_id=0` non è una prenotazione reale, che è esattamente il comportamento atteso in un test sicuro.

---

## Errori comuni

| Messaggio / Sintomo | Causa probabile | Soluzione |
|---|---|---|
| Test connessione: errore di connessione | URL sito centrale errato o sito non raggiungibile | Verificare URL, protocollo e connettività di rete |
| Test connessione: HTTP 404 | URL centrale errato o plugin centrale non attivo | Verificare URL e stato del plugin SOS Gateway sul centrale |
| Test handoff: HTTP 404 | Plugin centrale non attivo o URL errato | Verificare installazione plugin centrale |
| Test handoff: HTTP 403 non `sos_pg_handoff_forbidden` | Partner ID non registrato sul sito centrale | Chiedere all'amministratore centrale di verificare la configurazione del partner |
| Test callback: HTTP 401 "Firma non valida" | Il `shared_secret` non corrisponde al `payment_callback_secret` del centrale | Allineare i valori dei due campi |
| Test callback: HTTP 403 "Callback non attivato" | Il campo `payment_callback_secret` è vuoto sul sito centrale | Chiedere all'amministratore centrale di configurarlo |
| Test callback: HTTP 403 "Contesto partner non valido" | Il `payment_callback_path` non corrisponde allo slug configurato sul centrale | Verificare il campo **Endpoint callback pagamento** e lo slug sul plugin centrale |
| Test callback: HTTP 404 | Path errato o plugin centrale non attivo | Verificare URL e path |

---

## Checklist finale prima del go-live

- [ ] Plugin SOS Partner Bridge Lite installato e attivo
- [ ] Partner ID inserito e corrisponde esattamente a quello registrato sul sito centrale
- [ ] URL sito centrale inserito (con `https://`, senza slash finale)
- [ ] Shared secret inserito e allineato con il valore sul sito centrale
- [ ] Modalità di integrazione selezionata correttamente
- [ ] Endpoint path corretti (modificare solo se il sito centrale usa configurazioni non standard)
- [ ] **Test connessione** eseguito con esito positivo (HTTP 200)
- [ ] **Test handoff** eseguito con esito positivo (HTTP 403 `sos_pg_handoff_forbidden`)
- [ ] **Test callback** eseguito con esito positivo (HTTP 400 "Dati mancanti") — solo per modalità `payment_callback` o `combined`
- [ ] Sidebar "Stato configurazione" mostra **Plugin pronto**
- [ ] Flag Debug disattivato in produzione
