# SOS Partner Gateway

Plugin WordPress per:
- login partner firmato ECC su endpoint unico `/partner-login/`
- protezione singole pagine partner
- redirect dinamico alla pagina partner corretta
- log integrati in database
- ban / sblocco IP
- configurazione centralizzata per partner pages
- base riusabile per F+H e partner futuri

## Flusso
1. Il partner invia una POST firmata all'endpoint pubblico `/partner-login/`
2. Il plugin verifica firma, timestamp, nonce e rate-limit
3. L'utente viene creato o recuperato
4. Il plugin salva `partner_id` e reindirizza alla pagina partner configurata
5. La pagina partner è accessibile solo se l'utente è autenticato e ha il `partner_id` corretto