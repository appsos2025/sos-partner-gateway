# Esempio handoff URL

Endpoint handoff (issue token):

GET https://tuosito.it/wp-json/sos-pg/v1/handoff/caf_bari

Endpoint handoff verify:

GET https://tuosito.it/wp-json/sos-pg/v1/handoff/verify?token={TOKEN}

Header partner consigliati:
- X-SOS-Partner-ID: caf_bari
- X-SOS-Partner-Token: {shared_secret}
