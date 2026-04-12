# SOS Partner Bridge Lite

Lightweight WordPress plugin for partner sites integrating with SOS central gateway.

## Goal

Provide a minimal, maintainable foundation for:
- partner request bootstrap toward central site
- login/handoff flow toward central site
- payment callback toward central site
- embedded booking partner integration base

## Current Scope (Scaffold)

This package includes:
- admin settings page (minimal fields)
- config helper and bootstrap wiring
- HTTP client abstraction for central communication
- handoff service placeholder
- payment callback placeholder

No final business logic is enforced yet. Endpoints and payload contracts are placeholders and will be finalized in next phase.

## Admin Settings

- Partner ID
- Central Base URL
- Integration Mode
- Shared Secret / Token (placeholder)
- Handoff Endpoint Path (mode dependent)
- Payment Callback Path (mode dependent)
- Embedded Entrypoint Path (mode dependent)
- Debug enable/disable

## Admin UX Sections

- Configurazione base
- Connessione al sito centrale
- Integrazione attiva
- Test e diagnostica
- Stato configurazione (checklist + summary)

## Integration Modes

- handoff_login
- embedded_booking
- payment_callback
- combined

## Installation

1. Copy folder into `wp-content/plugins/`.
2. Activate plugin from WordPress admin.
3. Open "SOS Partner Bridge" menu and save settings.

## Notes

- Keep central private keys on central infrastructure only.
- This plugin is intended to stay lightweight and zip-ready.
