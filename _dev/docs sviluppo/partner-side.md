# Istruzioni lato partner

## Endpoint login
POST https://<tuo-dominio>/partner-login/

## Campi richiesti
- partner_id
- payload = email utente
- timestamp
- nonce
- signature (base64)

## Stringa da firmare
partner_id|payload|timestamp|nonce

## Form HTML esempio
```html
<form id="partnerLoginForm" action="https://<tuo-dominio>/partner-login/" method="POST">
  <input type="hidden" name="partner_id" value="<partner_id>">
  <input type="hidden" name="payload" value="utente@esempio.it">
  <input type="hidden" name="timestamp" value="<unix_timestamp>">
  <input type="hidden" name="nonce" value="<stringa_casuale>">
  <input type="hidden" name="signature" value="<BASE64_FIRMA_ECC>">
</form>
<script>document.getElementById('partnerLoginForm').submit();</script>
```

## Webhook booking_created (dal gateway al partner)
- Configurazione lato WordPress: URL e secret per ogni partner (HMAC SHA256 su body JSON) con header X-SOSPG-Signature.
- Payload inviato:
  - event (sempre booking_created)
  - partner_id, booking_id, status
  - service_id, start_date, start_time, total
  - customer_email
  - location_id = ID della location associata al partner nel sistema
  - **partner_charge** (presente solo se `pay_on_partner = true`): importo che il partner deve incassare dal cliente
  - **pay_on_partner** (presente solo se attivo): `true` = il pagamento avviene sul portale del partner

## Callback pagamento (dal partner al gateway)
- Endpoint: /partner-payment-callback (slug configurabile nelle impostazioni)
- Header: Content-Type: application/json, X-SOSPG-Signature = HMAC SHA256 sul body con secret condiviso.
- Payload minimo accettato:
  - booking_id (obbligatorio)
  - status (facoltativo, altrimenti usa quello configurato su WP)
  - transaction_id (facoltativo)
  - partner_id (facoltativo)
- Effetto: imposta status = payment_success_status configurato e payment_status = paid nel sistema di prenotazione.
- **Il callback è richiesto SOLO quando `pay_on_partner = true`** (il partner gestisce il pagamento). Se il cliente ha pagato direttamente sul sito principale, il flusso si chiude automaticamente dopo il pagamento.

## Modalità di pagamento per partner

### Pagamento sul sito principale (default)
Il cliente paga tramite il checkout del sito principale. Nessun callback richiesto dal partner.

### Pagamento sul portale del partner (`pay_on_partner = true`)
Il totale sul sito principale è impostato a 0 (il cliente non paga lì). Il webhook include:
- `total: 0` — il sito principale non incassa
- `partner_charge: <importo>` — l'importo che il partner deve incassare dal cliente

Il partner incassa il pagamento sulla propria piattaforma e invia il callback di conferma al gateway.

### Prenotazione con sconto o gratuita
Se è configurato uno sconto (fisso in € o percentuale %), il totale viene ridotto prima del checkout.
Se il totale risultante è 0, il partner deve comunque inviare il callback per aggiornare lo stato.

```php
// Alla ricezione del webhook, gestione per prenotazione con pay_on_partner
if (!empty($data['pay_on_partner'])) {
    $amount_to_charge = $data['partner_charge'] ?? 0;
    // ... gestisci il pagamento sulla tua piattaforma ...
    // Dopo l'incasso, invia il callback:
    send_payment_confirmation($data['booking_id'], 'TX-' . $data['booking_id'], $data['partner_id']);
}
```

## Utilizzo centralizzato multi-portale (es. sospediatra.org)

Il plugin supporta uno scenario in cui **un unico sistema di prenotazione centralizzato** riceve prenotazioni
da portali diversi. Ogni portale ha un proprio `partner_id` e una propria **location**
(`location_id`) dedicata.

Flusso:
1. Il medico inserisce disponibilità in un solo posto (es. su sospediatra.org)
2. Ogni portale partner (es. portale1.it, portale2.it) presenta un pulsante "Prenota"
3. Al click: il portale costruisce un login firmato verso `/partner-login/` di sospediatra.org
4. Il gateway autentica il partner, carica la pagina prenotazione giusta (location dedicata)
5. Il cliente prenota → il webhook arriva al portale di origine
6. Il portale gestisce il pagamento e invia il callback di conferma

Vantaggi:
- Il medico gestisce slot in un solo posto
- Gli slot vengono occupati automaticamente da tutti i portali
- Ogni prenotazione è tracciata con il `partner_id` e `location_id` del portale di origine
- Nessuna gestione manuale su più siti

Ogni portale deve configurare:
- `partner_id` univoco
- Chiave privata ECC per firmare il login
- URL webhook per ricevere le prenotazioni
- Secret HMAC per la verifica firma
- URL e secret per inviare il callback di pagamento

## Plugin installato direttamente sul sito partner (modalità WordPress)

Se il portale partner è basato su WordPress, il plugin può essere installato direttamente su di esso e configurato in **modalità partner** (`Impostazioni → Ruolo sito → Sito partner`). Non è necessario sviluppare un ricevitore webhook personalizzato: il plugin gestisce l'intera logica lato partner.

### Campi di configurazione visibili in modalità partner

| Campo | Descrizione |
|---|---|
| **Partner ID** | ID che identifica questo sito sul sito principale (es. `hf`). |
| **URL endpoint login (sito principale)** | URL completo del `/partner-login/` del sito principale. |
| **Chiave privata ECC (PEM)** | Chiave privata per firmare le richieste di login. Deve corrispondere alla chiave pubblica configurata sul sito principale. |
| **URL webhook in entrata** | Generato automaticamente: `https://<tuo-dominio>/?sos_pg_webhook=1`. Copialo nella configurazione webhook del sito principale. |
| **Secret webhook in entrata (HMAC)** | Lo stesso secret configurato sul sito principale per questo partner. Usato per verificare la firma delle notifiche in arrivo. |
| **URL callback pagamento (sito principale)** | URL dell'endpoint `/partner-payment-callback/` del sito principale. |
| **Secret callback pagamento** | Secret per firmare le richieste di conferma pagamento inviate al sito principale. |

I campi esclusivi del sito principale (rate limit, chiave pubblica, slug callback, pagina di cortesia, ecc.) sono nascosti in modalità partner perché non rilevanti.

### Cambio ruolo e preservazione dei valori

Quando si modifica il selettore **Ruolo sito** e si salva (es. passando da `main` a `partner`), il form visibile appartiene ancora al vecchio ruolo e non include i campi partner-only. Per evitare di azzerare i valori già configurati, il plugin applica i seguenti controlli:

- `partner_webhook_secret`, `partner_callback_url`, `partner_callback_secret` vengono aggiornati **solo se presenti nel POST**.
- Se il POST proviene dal form del sito principale (passaggio `main → partner`), questi campi sono assenti → i valori già salvati vengono **conservati**.
- Se il POST proviene dal form del sito partner, i campi sono presenti → vengono aggiornati normalmente.
- Svuotare esplicitamente un campo (valore vuoto, ma chiave presente) **cancella** il valore: l'intento esplicito dell'utente viene rispettato.

### Redirect dopo il salvataggio

Il redirect post-salvataggio punta alla pagina admin corretta in base al ruolo attivo:
- Modalità `partner` → `admin.php?page=sos-partner-gateway&msg=saved`
- Modalità `main` → `admin.php?page=sos-partner-gateway-settings&msg=saved`

Questo evita la schermata "Non autorizzato" che si verificava in precedenza quando si veniva reindirizzati al slug della pagina del ruolo opposto.

## Shortcode [sos_partner_prenota] — pulsante "Prenota" self-service

Per lo scenario in cui il **proprietario del sito** vuole aggiungere un pulsante
"Prenota" direttamente su una pagina WordPress propria (senza un portale partner
separato), è disponibile lo shortcode `[sos_partner_prenota]`.

### Configurazione (Impostazioni → SOS Partner Gateway)
- **Partner ID self-use**: il `partner_id` da usare per le richieste firmate dallo shortcode
- **Chiave privata self-use (PEM)**: la chiave privata ECC che firma la richiesta — deve
  corrispondere alla chiave pubblica configurata per la verifica

### Utilizzo shortcode

```
[sos_partner_prenota]
[sos_partner_prenota partner_id="hf" label="Prenota una visita"]
[sos_partner_prenota partner_id="hf" label="Prenota" email_field="no"]
```

| Attributo    | Default                  | Descrizione |
|---|---|---|
| `partner_id` | `self_login_partner_id`  | Partner ID; sovrascrive quello nelle impostazioni |
| `label`      | `Prenota`                | Testo del pulsante |
| `email_field`| `yes` (non loggato)      | `yes` = mostra campo email; `no` = usa email WP dell'utente loggato |
| `class`      | vuoto                    | Classi CSS aggiuntive sul form |

### Flusso tecnico

1. Il visitatore compila email (se richiesta) e clicca il pulsante
2. Il form fa POST a `/?sos_pg_book_now=1` (endpoint interno al plugin)
3. Il plugin firma la richiesta con la chiave privata e fa auto-POST a `/partner-login/`
4. Il gateway autentica → crea/aggiorna utente WP → redirige alla pagina booking del partner
5. Il cliente completa la prenotazione nel sistema

### Errori mostrati in-page (solo ad admin)
- Chiave privata non configurata
- Partner ID mancante

### Errori di invio (parametro `sos_pg_err` nella URL)
| Codice | Messaggio |
|---|---|
| `email` | Email non valida |
| `partner` | Partner ID mancante |
| `key` | Chiave privata non configurata o non valida |
| `sign` | Errore di firma |
