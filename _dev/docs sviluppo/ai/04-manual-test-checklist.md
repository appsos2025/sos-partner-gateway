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

## Nuove feature
Ogni nuova feature deve avere:
- test manuale dedicato
- impatto zero sul flusso esistente