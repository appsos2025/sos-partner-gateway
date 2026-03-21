# Incremental Implementation Plan

## Obiettivo
Procedere per step piccoli, sicuri, verificabili.

## Step 1 - Preparazione architetturale minima
Obiettivo:
- identificare e isolare il core comune
- introdurre classi/helper nuovi senza cambiare il comportamento attuale
- zero modifiche funzionali pubbliche

Esempi:
- nuova classe registry/config partner
- nuova classe helper REST placeholder
- bootstrap ordinato di componenti futuri

## Step 2 - Endpoint REST di sola lettura
Obiettivo:
- aggiungere endpoint innocui e testabili senza impattare il flusso esistente

Esempi:
- health
- session/verify

## Step 3 - Base supporto partner esterni
Obiettivo:
- introdurre configurazione partner tipo external_api_partner
- nessuna rottura dei partner WordPress

## Step 4 - Login API partner esterni
Obiettivo:
- permettere login/handshake a partner non WordPress
- mantenendo invariato il flusso WordPress esistente

## Step 5 - Callback e integrazione servizi partner esterni
Obiettivo:
- aggiungere webhook/callback/aggiornamenti servizi lato external API
- con test manuali chiari

## Regola pratica
Mai fare Step 2+ se Step 1 non è stabile.
Mai fare Step 3+ se i test regressione dello stato attuale non passano.