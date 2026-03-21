# Agent Task

## Missione
Estendere il plugin SOS Partner Gateway per supportare anche partner esterni senza WordPress, mantenendo un solo plugin e senza rompere il comportamento attuale.

## Prima di modificare codice
Leggi obbligatoriamente:
- docs/ai/00-project-context.md
- docs/ai/01-non-regression-rules.md
- docs/ai/02-target-architecture.md
- docs/ai/03-implementation-plan.md
- docs/ai/04-manual-test-checklist.md

## Regole operative
- non fare rewrite totale
- non cambiare comportamento esistente
- non rimuovere flussi funzionanti
- mantieni backward compatibility completa
- preferisci patch piccole
- spiega sempre file toccati e rischio regressione

## Output atteso ogni volta
Per ogni task svolto, restituisci:
1. riassunto del piano
2. file modificati
3. perché il cambiamento è sicuro
4. come testare regressione
5. eventuali rischi

## Primo task consentito
Solo STEP 1 dell'implementation plan:
preparazione architetturale minima, senza cambiare il comportamento funzionale del plugin.