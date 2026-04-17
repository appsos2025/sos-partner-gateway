# Manual Test Checklist

## Prima di ogni modifica
Verificare lo stato attuale funzionante.

## Regressione minima obbligatoria
### Login / accesso
- Il login partner WordPress continua a funzionare
- Il login self-use / shortcode continua a funzionare
- Nessun errore di firma ECC

### Partner webhook
- booking_created continua a essere inviato
- firma webhook continua a essere valida
- il partner riceve il payload atteso

### Callback pagamento
- il partner riesce a inviare callback pagamento
- nessun errore 401 di firma
- lo stato di pagamento viene aggiornato correttamente

### Admin
- le pagine admin si aprono correttamente
- ruoli main/partner continuano a mostrare i campi giusti
- i log continuano a essere scritti

### Compatibilità configurazione
- option key esistenti restano leggibili
- nessuna configurazione salvata viene persa

## Flusso confermato da preservare
- POST diretto a `/partner-login/` con firma valida
- accesso alla pagina partner corretta dopo il redirect
- creazione prenotazione nel browser lato partner
- invio webhook `booking_created` al ricevitore partner
- callback pagamento HMAC valida verso `/partner-payment-callback/`
- aggiornamento finale booking a stato configurato, ad esempio `pagato`

## Failure case da verificare
- timestamp scaduto: richiesta rifiutata
- signature non valida: richiesta rifiutata
- replay nonce: richiesta rifiutata
- callback HMAC errata: HTTP 401 atteso
- partner/page non configurati: HTTP 404 atteso
- IP in ban temporaneo: HTTP 429 atteso

## Opzionale / non richiesto per Family+Happy
- endpoint `/embedded-booking/create`
- strategia `passthrough`
- first_name, last_name, phone nel flusso partner diretto

## Nuove feature
Ogni nuova feature deve avere:
- test manuale dedicato
- impatto zero sul flusso esistente