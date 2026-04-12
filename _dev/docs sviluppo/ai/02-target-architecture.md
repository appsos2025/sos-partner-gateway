# Target Architecture

## Obiettivo
Estendere il plugin esistente per supportare anche partner non WordPress.

## Modello desiderato
Un solo plugin con architettura a strati:

### 1. Core condiviso
Responsabilità:
- registry/configurazione partner
- regole di autenticazione
- log
- routing partner
- validazioni comuni
- helper per callback/webhook/sessione

### 2. Adapter WordPress partner
Responsabilità:
- compatibilità con flusso partner WordPress già esistente
- login firmato ECC
- webhook verso partner WordPress
- callback pagamento come già implementato

### 3. Adapter external API partner
Responsabilità:
- supporto partner esterni senza WordPress
- endpoint REST sul sito principale
- session verify
- partner login API
- logging API se necessario
- eventuale callback sicura partner -> principale

## Principio chiave
Il supporto external API deve essere un’estensione, non una sostituzione del flusso attuale.

## Endpoint nuovi desiderati
Esempi:
- /wp-json/sos/v1/health
- /wp-json/sos/v1/session/verify
- /wp-json/sos/v1/partner/login
- /wp-json/sos/v1/partner/callback

## Nota sicurezza
Il flusso ECC esistente resta valido e intoccato.
Per i partner esterni si può introdurre un layer REST, ma senza indebolire la sicurezza del sistema esistente.

## Configurazione partner unificata
Il plugin dovrà supportare tipi partner come:
- wordpress_partner
- external_api_partner
- self_use_shortcode