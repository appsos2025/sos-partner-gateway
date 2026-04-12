# Non Regression Rules

## Regola assoluta
Non rompere nulla del flusso attuale.

## Cose che NON devono cambiare comportamento
- Login partner firmato ECC già esistente
- Verifica firma lato sito principale
- Ruoli "main" e "partner"
- Shortcode/self-use per sottodomini
- Webhook booking_created verso partner
- Callback pagamento partner -> sito principale
- Log esistenti
- Pagine admin esistenti
- Opzioni già salvate nel database
- Slug e endpoint già in uso

## Divieti
- Non creare un secondo plugin
- Non rimuovere codice esistente funzionante senza motivo
- Non rinominare option key esistenti
- Non cambiare il significato dei campi esistenti
- Non sostituire ECC con una soluzione meno sicura
- Non fare refactor distruttivi
- Non fare modifiche grandi in un unico step
- Non rimuovere shortcode o flussi self-use
- Non cambiare URL/slug pubblici già usati, salvo compatibilità completa

## Regole di implementazione
- Preferire nuove classi/file invece di modifiche invasive
- Introdurre nuove feature in modo incrementale
- Ogni step deve essere testabile
- Ogni step deve poter essere rivisto in diff piccolo
- Se una modifica è rischiosa, va rinviata a uno step successivo

## Priorità
1. Sicurezza
2. Backward compatibility
3. Stabilità
4. Estensione funzionale
5. Refactor estetico

## In caso di dubbio
Se devi scegliere tra velocità e sicurezza, scegli sicurezza.