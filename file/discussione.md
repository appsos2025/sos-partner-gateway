un altro partner invece sempre con il nostro form di latepoint ma su un altro sottodominio vuole che sul suo sito appaiono i nostri medici con disponibilità e orari e poi credo che debbano pagar loro, e loro magari mostrano con embed, in questo caso per non farli pagare associamo partner e sconto 100% . analizza quello che mi hanno detto e capiamo il flusso più facile e veloce. tieni in considerazione che se devono mostrare l'embed dobbiamo personalizzare noi tutto se invece passiamo a loro i dati come medico orari e slot disponibili devo fare tutto loro, ma poi loro devono anche passarci i dati per inserire l'appuntmanto 
Ragionato per 34s

La via più facile e veloce è questa:

non passare dati/slot al partner
non far prenotare dal loro backend
fagli usare un embed o una pagina/iframe del tuo LatePoint
e, se non devono pagare sul tuo sito, gestisci il partner con sconto 100% lato tuo.

Per LatePoint hai già strade native per mostrare il booking sul sito del partner:

[latepoint_book_form] per mettere il form direttamente in pagina; puoi anche prefiltrare agenti/servizi con attributi del shortcode.

[latepoint_resources items="agents"] per mostrare l’elenco dei medici/agent con pulsante prenota.

[latepoint_calendar] per mostrare un calendario servizi, anche filtrando servizi specifici.

le Instant Booking Pages per creare URL diretti verso un servizio/agente specifico.

Analisi pratica delle 3 opzioni
Opzione A — Embed/iframe del tuo booking sul loro sito

È la migliore per partire.

Flusso:

sul tuo sottodominio crei una pagina partner dedicata

dentro metti:

elenco medici LatePoint

oppure form LatePoint già filtrato

loro la incorporano con iframe oppure aprono una pagina tua embedded

utente prenota nel tuo LatePoint

appuntamento viene creato direttamente nel tuo sistema

se il partner deve “non far pagare” al cliente sul tuo sito, applichi sconto 100% al partner

Vantaggi

non devi sviluppare API custom per disponibilità

non devi sincronizzare slot e regole calendario

l’appuntamento nasce già nel tuo LatePoint

eviti mismatch tra disponibilità mostrata e disponibilità reale

è la soluzione più veloce da mettere online

Svantaggi

lato grafica, se vogliono integrazione perfetta col loro sito, devi personalizzare tu la pagina o l’iframe

se vogliono UX totalmente nativa nel loro frontend, l’iframe è meno elegante

Opzione B — Tu passi a loro medici, disponibilità e slot

La sconsiglio come v1.

Flusso:

tu esponi dati medici + slot disponibili

loro costruiscono frontend

quando l’utente sceglie, loro devono rimandarti i dati

tu devi creare l’appuntamento in LatePoint

Problemi

devi costruire una vera API

devi gestire slot in tempo reale

rischi di mostrare slot liberi che poi non lo sono più

devi passare anche regole di durata, buffer, ferie, blocchi agenda, medici, servizi

loro devono comunque rimandarti tutti i dati paziente e booking

Questa è una soluzione da fase 2, non “più facile e veloce”.

Opzione C — Loro mostrano solo i medici, ma il click apre il tuo form già prefiltrato

Questa per me è la migliore in assoluto come compromesso.

Flusso:

loro mostrano sul loro sito la scheda medico

il bottone “Prenota” apre:

una tua pagina partner

oppure una tua instant booking page

oppure un tuo [latepoint_book_form] con selected_agent già impostato

il resto del flusso resta nel tuo LatePoint

LatePoint supporta preselezione di agent/service/location con shortcode e trigger custom.

La mia raccomandazione netta

Per il partner nuovo farei così:

Fase 1

pagina dedicata sul tuo sottodominio

dentro metti:

[latepoint_resources items="agents"] per mostrare i medici

oppure più pagine/URL con form prefiltrato per medico/servizio

loro incorporano la pagina con iframe oppure linkano il bottone prenota

tu applichi partner_id = partnerX

sconto partner = 100% per quel partner

Perché è meglio

Così:

il cliente vede i medici e le disponibilità reali

non devi trasferire slot a loro

non devono rimandarti i dati per creare appuntamento

l’appuntamento si crea direttamente nel tuo LatePoint

hai zero rischio di doppia logica calendario

Sul pagamento

Se “devono pagar loro” può significare due cose:

Caso 1 — il cliente non deve pagare nel booking

Allora la cosa più semplice è:

partner_id = partner_nuovo

sconto fisso = 100% del totale

oppure meglio ancora: flusso partner con pagamento saltato / totale a zero

Questo è coerente con quello che già stai costruendo.

Caso 2 — il partner ti paga dopo, fuori piattaforma

Ancora meglio:

prenotazione confermata a 0€

rendicontazione a fine mese al partner

Caso 3 — il partner incassa sul suo sito

Questo complica molto:

loro devono gestire il pagamento

poi rimandarti conferma e dati booking

aumentano i punti di errore

Io lo eviterei nella prima versione.

Flusso più facile e veloce da implementare

Te lo sintetizzo così:

Soluzione consigliata

crei un nuovo partner_id

nel tuo pannello “Sconti Partner” metti quel partner a sconto 100%

crei una pagina dedicata tipo:

/prenotazioni-partner-2/

nella pagina inserisci:

elenco medici LatePoint

oppure form LatePoint filtrato

il partner incorpora la pagina con iframe o apre il link diretto

il booking si chiude sul tuo sistema

pagamento lato tuo = 0€

Questo è il motivo

È il solo flusso che:

è rapido

non richiede API custom

non richiede sincronizzazione slot

non richiede sviluppo loro complesso

Se vuoi fare bene la v1

Io farei due modalità:

Modalità 1 — catalogo medici

Usi latepoint_resources items="agents" per mostrare i medici.

Modalità 2 — prenotazione diretta

Per ogni medico/servizio crei un link diretto o una booking page con preselected agent/service. LatePoint supporta preselected agent/service/location e anche Instant Booking Pages.

Cosa eviterei adesso

Non farei subito:

API slot custom

sincronizzazione disponibilità verso il loro frontend

creazione appuntamento da dati inviati da loro

pagamento gestito da loro e booking creato da te dopo

Tutte queste cose sono più lente e più fragili.

Decisione pratica

Quindi la mia proposta è:

Partner nuovo = pagina dedicata + embed/link al tuo LatePoint + sconto 100% + prenotazione direttamente nel tuo sistema

Se vuoi, nel prossimo messaggio ti scrivo proprio il piano operativo minimo:

quali shortcode usare

come impostare la pagina

come distinguere partner 2 dal partner 1

come gestire lo sconto 100% senza rompere il booking.

# SOS Medici integration

# Video consultation

1. The user create a request in the F+H request selecting “Video Consulto”
2. In the request details we embed SOS Medici bookig form and flow 
    1. Passed parameters
        1. name
        2. email
        3. phone
        4. validation token
3. the user selects the specialization and the professional profile
4. the user books a session
5. the embedded form/flow sends back an event with booking time stamp
6. F+H webapp register the booking as a request event using the same flow and automation as nutritionists and psychologists to generate the payment link 
    1. We must handle the case of sessions with no upfront cost for the user

# Home Vist

1. The user create a request in the F+H request selecting “Visita a domicilio”
2. An email is sent to a SOS Medici address
    1. Passed parameters
        1. name
        2. email
        3. phone
        4. request ID
3. SOS Medici operation center contacts the user and plans the visit
4. Confirmation email in CCed to a F+H address
    1. request ID is passed to reconcile on F+H side
5. We manually create the event and the payment link for the request 

Questo documento chiarisce bene il flusso. Ti direi che ci sono due integrazioni diverse:

1. Video consulto

Qui vogliono una vera integrazione applicativa.

Il flusso più pulito è:

l’utente crea la richiesta su F+H

nella scheda richiesta loro aprono o incorporano il tuo booking flow

ti passano:

name

email

phone

validation_token

l’utente sceglie specializzazione e medico

prenota

il tuo sistema restituisce un evento di conferma con:

booking id

data/ora

servizio

medico

stato

loro usano quell’evento per generare il payment link nel loro flusso

Lettura pratica

Qui non vogliono solo un iframe passivo.
Vogliono che il tuo flow comunichi indietro a F+H quando la prenotazione è stata fatta.

Quindi per il video consulto hai 2 opzioni:

Opzione A — Embed + callback evento

È quella giusta.

Tu fai una pagina booking dedicata per F+H, con form embedded o iframe.
Loro ti passano i dati utente e un token.
Quando la prenotazione va a buon fine, tu mandi indietro un evento.

Questo evento può essere in 2 modi:

redirect con parametri

webhook server-to-server

La soluzione migliore è webhook server-to-server.

Evento da restituire

Io restituirei almeno questi dati:

request_id
booking_id
status
service_name
agent_name
start_datetime
end_datetime
customer_name
customer_email
customer_phone
partner_id
validation_token
price
currency
payment_required

Se vogliono gestire i casi “no upfront cost”, allora devi mandare anche:

payment_required = true/false
amount_due_now = 0 oppure importo

Così loro sanno se generare link pagamento oppure no.

2. Visita a domicilio

Qui invece non stanno chiedendo integrazione realtime.

Il flusso è molto più semplice:

utente crea richiesta F+H

F+H manda mail a SOS Medici con:

nome

email

telefono

request ID

il tuo centro operativo gestisce tutto a mano

quando confermi, mandi mail con in CC F+H

dentro ci deve essere il request_id

loro riconciliano e creano evento + payment link manualmente

Lettura pratica

Per la visita domiciliare non serve LatePoint embed.
Qui basta un flusso email/manuale ben strutturato.

Conclusione tecnica

Quindi io separerei così:

Video consulto

integrazione embedded + callback automatico

Visita a domicilio

integrazione email/manuale

Soluzione più facile e veloce

La più veloce da implementare per il video consulto è questa:

Lato F+H

Aprono una tua pagina dedicata in iframe o embed.

Ti passano in querystring o POST iniziale:

request_id
name
email
phone
validation_token
partner_id=fh
mode=video
Lato tuo

Tu:

precompili i dati paziente

filtri i servizi su “Video Consulto”

fai scegliere specializzazione e medico

concludi la prenotazione

Alla conferma

Tu mandi un webhook a F+H tipo:

{
  "request_id": "REQ123",
  "booking_id": "SOS456",
  "status": "confirmed",
  "service_name": "Video Consulto Cardiologico",
  "agent_name": "Dr. Rossi",
  "start_datetime": "2026-03-20T15:00:00+01:00",
  "end_datetime": "2026-03-20T15:20:00+01:00",
  "customer_name": "Mario Bianchi",
  "customer_email": "mario@email.it",
  "customer_phone": "+39333...",
  "payment_required": true,
  "amount_due_now": 25.00,
  "currency": "EUR",
  "validation_token": "..."
}
Perché questa è la strada giusta

Perché evita la soluzione più pesante:

tu non devi esportare slot e disponibilità a loro

loro non devono costruire un booking engine

tu non devi ricevere da loro i dati per creare l’appuntamento dopo

la disponibilità resta sempre la tua reale

In pratica:

F+H mostra il tuo booking, ma la logica resta tua.

Cosa ti serve costruire davvero

Per partire bene ti servono 4 cose.

1. Una pagina booking dedicata a F+H

Solo video consulti, con branding minimo.

2. Precompilazione dati

Nome, email, telefono, token, request_id.

3. Un identificatore partner

Per esempio:

partner_id = fh

Così puoi:

loggare accessi

applicare sconti o no-payment

distinguere i booking F+H

4. Un callback finale

Meglio webhook che redirect.

Il punto critico vero

La parte più importante non è l’embed.
È capire quando il booking è confermato e come inviare l’evento a F+H.

Quindi la prima domanda tecnica da chiudere con loro è:

vogliono ricevere un webhook server-to-server o basta un redirect del browser con i dati?

Io spingerei fortemente per:

webhook

opzionalmente anche redirect finale utente

La versione minima che farei io

Per andare veloci:

Video consulto

pagina dedicata /fh-video-consulto/

prefill dati cliente

servizi filtrati a video consulti

partner_id fh

webhook a F+H alla conferma

Visita a domicilio

indirizzo mail dedicato

template mail con request_id

conferma manuale con mail a F+H

Una nota importante

Per “sessions with no upfront cost”, non farei sconto 100% nel booking se poi loro devono generare pagamento esterno in certi casi.

Meglio distinguere due campi:

booking_total

amount_due_now

Per esempio:

booking totale 70€

amount due now 0€

F+H genera payment link dopo

Così non perdi il valore economico della prestazione.

Ti suggerisco questo assetto

Non usare il partner con sconto 100% come unica logica per F+H.

Meglio:

partner_id = fh

payment_mode = external

amount_due_now = 0

service_price = vero importo

Così:

lato utente non pagano subito

lato amministrativo sai quanto vale la prestazione

F+H può generare il pagamento dopo

Risposta netta

Il flusso più facile e veloce è:

Video consulto = tuo booking embedded + callback webhook
Visita domiciliare = email/manuale

Non farei:

export slot a F+H

frontend booking costruito da loro

creazione appuntamento da dati rimandati da loro

È più lento e più fragile.

Se vuoi, il prossimo passo utile è che ti scrivo proprio lo schema tecnico finale da mandare a loro:

parametri in ingresso

evento webhook in uscita

campi obbligatori

casi “payment_required yes/no”.

C'è anche se dedico una pagina dedicata con un form personalizzato o dei campi obbligatori tipo termini e condizioni da passare e che l'utente deve accettare. Una volta creato l'appuntamento sul nostro portale vengono inviate delle mail, mentre qui le mail le devono inviarle loro e io devo anche personalizzare questa cosa, creando una posizione dedicata magari, TSH e inserendo tutti i vari email senza errori. Magari una sola mail con il link di conferma in modo da non avere errori. o sempre in attesa in modo che la nostra centrale abbia il quadro sempre sotto controllo. Ma se pagano loro a me che non l'appuntamento, tornano su di loro e pagano, e se su loro non pagano, su me hanno creato l'appuntamento. Come si scambiano i dati? Come creano l'appuntamento su di me? Non ho capito questa cosa.

Hai centrato il punto: se l’appuntamento nasce già sul tuo sistema, ma il pagamento avviene sul loro, devi decidere come gestire gli appuntamenti non pagati.

Il nodo vero è questo:

chi crea l’appuntamento

quando viene creato

chi invia le mail

cosa succede se il pagamento fallisce o non avviene

La soluzione più pulita è questa:

Non creare subito un appuntamento confermato

Per F+H io farei così:

Flusso corretto

l’utente apre il tuo form dedicato F+H

compila o riceve già precompilati:

nome

email

telefono

request_id

validation_token

vede i tuoi medici e gli slot reali

sceglie specializzazione, medico, data e ora

sul tuo sistema non confermi subito l’appuntamento come definitivo

invii a F+H i dati della scelta

F+H genera il pagamento sul loro lato

solo quando il pagamento è riuscito:

o confermi l’appuntamento sul tuo sistema

oppure togli uno stato di attesa

Quindi la risposta alla tua domanda è:

non devono pagare dopo che hai già creato un appuntamento definitivo e operativo.
Devi usare uno stato intermedio.

Il punto chiave: stato in attesa / hold

La cosa più semplice per te è avere un flusso tipo:

utente seleziona slot

tu crei una prenotazione in attesa

mandi i dati a F+H

F+H gestisce il pagamento

quando il pagamento arriva, tu confermi

se non arriva, annulli o scade

Questo ti evita il problema:

“su di me hanno creato l’appuntamento ma su di loro non pagano”

Perché sul tuo lato non lo consideri davvero confermato finché loro non ti confermano.

Come si scambiano i dati

Il flusso corretto è in 2 chiamate.

1. Tu mandi a loro la scelta fatta

Quando l’utente seleziona lo slot e completa il tuo form, il tuo sistema manda a F+H qualcosa tipo:

{
  "request_id": "REQ123",
  "validation_token": "abc123",
  "booking_temp_id": "SOS_TMP_456",
  "service": "Video Consulto Cardiologico",
  "doctor": "Dr Rossi",
  "start_at": "2026-03-20 15:00:00",
  "end_at": "2026-03-20 15:20:00",
  "patient_name": "Mario Bianchi",
  "patient_email": "mario@email.it",
  "patient_phone": "+39333..."
}

Loro usano questi dati per creare il payment link.

2. Loro ti richiamano dopo il pagamento

Quando il pagamento è andato bene, loro ti chiamano con un webhook tipo:

{
  "request_id": "REQ123",
  "validation_token": "abc123",
  "booking_temp_id": "SOS_TMP_456",
  "payment_status": "paid"
}

A quel punto tu:

confermi la prenotazione

mandi eventuale mail finale

sblocchi il flusso

Se invece il pagamento non arriva entro X minuti:

annulli la prenotazione in attesa

liberi lo slot

Quindi chi crea l’appuntamento?

Ci sono 2 modelli.

Modello A — lo crei tu subito ma in attesa

È quello che consiglio.

Pro:

lo slot è bloccato

non rischi che nel frattempo un altro lo prenda

controlli tutto tu

Contro:

devi avere uno stato “pending / in attesa pagamento”

Modello B — lo crei tu solo dopo pagamento

Loro ti mandano i dati dopo il pagamento e tu crei l’appuntamento finale.

Pro:

niente appuntamenti non pagati sul tuo sistema

Contro:

lo slot non è bloccato davvero

rischi overbooking

se nel frattempo uno prende quello slot, si rompe tutto

Quindi il modello B è più rischioso.

La soluzione più sicura

Per me la migliore è:

Slot bloccato temporaneamente + conferma successiva

Quindi:

sul tuo sistema crei un record di prenotazione “in attesa”

non mandi le mail standard definitive

mandi a F+H l’evento

F+H incassa

F+H ti richiama

tu confermi

Le mail

Hai ragione anche su questo: se il flusso è F+H, non vuoi che partano mail sbagliate o duplicate.

Quindi io farei:

Per F+H

disattivi o personalizzi le mail automatiche standard

mandi al massimo una sola mail tecnica o interna

oppure niente mail finché non c’è il pagamento confermato

Possibile schema

alla creazione della prenotazione in attesa:

nessuna mail paziente

opzionale mail interna alla tua centrale

al pagamento confermato:

parte la mail definitiva

oppure la manda F+H e tu mandi solo quella operativa interna

Pagina dedicata con campi obbligatori

Sì, ha senso.

Puoi fare una pagina F+H dedicata con:

branding specifico

servizi solo video consulto

medico/specializzazione filtrati

campi aggiuntivi richiesti da loro

checkbox obbligatorio:

termini

privacy

consenso

Questo è corretto.

Ma quei campi devono poi:

essere salvati

o essere passati a F+H nel callback

Il flusso più semplice e veloce davvero

Se vuoi fare una v1 senza impazzire, io farei così:

V1 consigliata

pagina dedicata F+H

form tuo, con servizi video consulto

campi extra e checkbox obbligatori

prenotazione salvata da te come pending

nessuna mail standard al paziente

webhook a F+H con dati prenotazione

loro generano link pagamento

loro ti richiamano con esito

tu confermi o annulli

Questo evita tutti i problemi grossi

Perché così:

non devi esportare disponibilità a loro

non devono costruire booking logic

non rischi slot doppi

non mandi mail sbagliate

non hai appuntamenti confermati senza pagamento

In una frase

La chiave è questa:

non creare un appuntamento finale subito; crea una prenotazione in attesa, scambia i dati con F+H, e conferma solo dopo il loro esito di pagamento.

Decisione pratica

Quindi io ti consiglio:

Video consulto → pagina dedicata + prenotazione pending + webhook bidirezionale

Visita domiciliare → flusso email/manuale

Se vuoi, nel prossimo messaggio ti scrivo proprio lo schema tecnico definitivo step-by-step, con:

campi in ingresso

stato prenotazione

evento che mandi a loro

webhook che loro devono mandare a te

quando inviare o bloccare le mail.

In latepoint creo uno stato che si chiama f+h e che è non invierà email ma solo una noi del’lapountmbaot creato con questo stato di attesa. Quando sappiamo che il pagamento è creato allora dobbiamo metterlo in sospeso e lì partono le email di conferma e poi manualmente se gli orari e tutto è corretto mettiamo confermato manualmente per l’invio dei link per la call. Creo anche unaposizione fh su latepoint e vi metto i servizi prenotabili a costo zero visto che non ci devono pagare oppure devo inserire e far visualizzare il costo per passare loro il prezzo? In latepoint ci sono prezzi visualizzati costo del servizio e quota da versare, magari il costo é 100 e inserisco quota da versare zero e quindi passiamo il dato senza richiedere pagmanto 
Ragionato per 44s

Sì, il flusso che proponi ha senso, ma farei una distinzione netta:

non metterei il servizio a prezzo 0
metterei prezzo reale e quota da versare ora = 0, se LatePoint te lo consente nel tuo setup.

Il motivo è semplice: così conservi il valore economico reale della prestazione per rendicontazione e riconciliazione, ma l’utente non paga sul tuo portale. Se invece metti proprio il servizio a 0, perdi il riferimento economico nel booking e rischi di complicarti report, controlli interni e riconciliazione con F+H.

Come impostarlo

Io farei così:

Stato dedicato

Crea uno stato dedicato, tipo:

F+H
oppure

In attesa pagamento F+H

Usalo come stato tecnico iniziale. In questo stato:

non vuoi mail standard al paziente

vuoi al massimo una notifica interna tua

la centrale vede subito che è un booking “partner esterno”

Stato successivo

Quando F+H ti conferma che il pagamento/link è stato gestito correttamente:

sposti la prenotazione in uno stato tipo Sospeso o Pagato/FH

lì fai partire le mail corrette

poi, quando il tuo team verifica tutto, metti Confermato

Questa parte è una tua regola di processo, non una regola nativa “obbligatoria” di LatePoint, quindi è una scelta organizzativa sensata.

Posizione dedicata F+H

Sì, la farei.

Una location/posizione dedicata F+H ti aiuta a:

separare questi flussi dagli altri

filtrare servizi/medici dedicati

avere reporting più pulito

riconoscere subito i booking partner

Prezzo reale vs prezzo zero

Qui la mia raccomandazione è chiara:

Meglio

Prezzo servizio = reale

Quota da versare ora = 0

Così:

il cliente non paga da te

tu sai che la prestazione vale, per esempio, 100€

F+H può gestire il pagamento esterno

tu non perdi il valore della prestazione

Peggio

Prezzo servizio = 0

Così:

il booking è più semplice

ma per te diventa meno chiaro quanto valeva davvero quella prestazione

rischi confusione su report, analytics e controlli amministrativi

Quando avrebbe senso prezzo 0

Solo se per te quel flusso deve essere trattato davvero come:

servizio gratuito

nessun incasso

nessuna rendicontazione economica sul booking

LatePoint ha anche una funzione per nascondere il price breakdown quando il prezzo servizio è zero, quindi una UI “gratis” è supportata.

Ma nel tuo caso non lo farei, perché qui non è una prestazione gratuita: è una prestazione pagata altrove.

Sulle email

La tua idea è giusta: evitare email automatiche sbagliate o duplicate.

Tieni conto che LatePoint delega l’invio delle email a WordPress, quindi puoi e devi controllare con precisione cosa parte e quando.

Quindi, per F+H, farei:

stato iniziale F+H → niente mail paziente standard

opzionale: una sola mail interna alla tua centrale

stato successivo dopo ok F+H → partono le mail operative corrette

confermato finale → eventuali link call / comunicazioni definitive

Flusso che ti consiglio

Il più semplice e robusto è:

F+H apre la tua pagina dedicata

utente prenota sul tuo LatePoint

booking nasce in stato F+H

location = F+H

prezzo servizio = reale

quota da versare ora = 0

nessuna mail standard paziente

tu mandi a F+H i dati della prenotazione

loro gestiscono pagamento/link

quando ti danno esito:

metti Sospeso o Pagato/FH

poi Confermato quando il tuo team valida tutto

Perché questa è la soluzione migliore

Perché evita il problema principale che avevi individuato:

se su loro non pagano, su me hanno creato l’appuntamento

Con questo schema, sì, l’appuntamento viene creato, ma non come confermato operativo. Viene creato come booking tecnico in attesa partner.

Risposta netta alla tua domanda

Quindi:

sì a stato dedicato F+H

sì a location dedicata F+H

no al prezzo servizio a 0, salvo casi davvero gratuiti

meglio prezzo reale + quota da versare ora 0

sì a email limitate all’inizio

sì a conferma finale solo dopo esito F+H

Il prossimo passo giusto è definire esattamente:

nome dello stato iniziale

quali mail spegnere

quali campi mandare a F+H

quale evento F+H deve rimandarti per sbloccare la conferma.

latepoint ha una  gestione di email con flusso personalizzato da stati o servizi quindi posso inserire lo stato o il servizio personalizzato su quelle standand per non inviare e invece posso inviare solo agli utenti fh quello che voglio in base alla posizione o servizio.. ora quello che mi preme è invece la creazione del file md con tutti i dettagli per farlo funzionare rispettando quello che ci hanno inviato più dettagli, poi dimmi se l'implementazione del caf possiamo usarla in modod da avere sempre lo stesso sitema funzionanante, anczi vorrei anche pensare di creareil anche l aloro parte in modo da sarla anche con altri partner ovviamente loro dovranno compilarla ma vorrei che noi creiamoil 90% e lasciare a loro solo quello che decicdiamo noi, una sorta di widget da passare  
Ragionato per 17s

Ti ho preparato il file Markdown completo:

Scarica il file .md

Sì, l’implementazione partner che hai già costruito per il CAF è una base ottima da riusare:

login partner firmato

partner_id

logging

ban/sblocco IP

gestione regole partner

La direzione giusta è proprio trasformarla in un framework partner riutilizzabile, e per F+H ha molto senso costruire un widget standard SOS Medici dove:

noi controlliamo i parametri ammessi

noi gestiamo sicurezza e booking

loro compilano solo i campi che decidiamo noi

Se vuoi, il passo successivo migliore è trasformare questo .md in una checklist tecnica operativa con:

cosa fare in LatePoint

cosa fare in WordPress

cosa deve fare F+H

ordine di implementazione. 

functions.php
File
partner-login-log-manager.php
File
public.pem
File
si facciamolo, il dominio questa volta è videoconsulto.sospediatra.org è il sito non  è bloccato da un accesso per l'intero sito ma dobbiamo autorizzare e  bloccare singola pagina partner, facendo sempre pagina login  e lasciando a noi il redirect alla pagina del partner giusto: partner1 /prenotazioni-partner1/ sempre stesso ragionamento se non autenticato nessun permesso. voglio anche creare il progetto su github in modo da averso sempre aggiornato per tutti 
Ragionato per 22s

Sì, per videoconsulto.sospediatra.org va cambiato approccio: il functions.php che hai ora blocca di fatto tutto il sito ai non loggati, tranne pagina 35 e partner-login, quindi non è adatto a un sito pubblico dove vuoi proteggere solo alcune pagine partner. Inoltre il redirect del login partner è ancora fisso a /prenotazioni-partner/, mentre tu vuoi una destinazione per partner, tipo /prenotazioni-partner1/. Questo si vede chiaramente nel file attuale.

Il plugin log invece va bene come base e lo puoi riusare quasi identico anche qui.

Direzione giusta

Per questo dominio farei così:

sito pubblico

protezione solo delle pagine partner

endpoint unico partner-login

login firmato come hai già

redirect dinamico in base al partner_id

salvataggio partner_id e partner_target_page

stessa logica di log/sblocco IP

struttura già pronta per futuri partner

codice versionato su GitHub da subito

Checklist tecnica operativa
Fase 1 — Base WordPress / sicurezza

Creare sul dominio:

pagina partner-login

pagina partner 1: /prenotazioni-partner1/

eventuale pagina partner 2: /prenotazioni-partner2/

Lasciare il sito pubblico

Proteggere solo le pagine partner

Tenere partner-login pubblica come endpoint tecnico

Mantenere firma ECC, anti abuse, log, sblocco IP

Fase 2 — Logica partner

Mappa partner:

partner1 → /prenotazioni-partner1/

fh → /fh-video-consulto/

ecc.

Salvataggio su utente:

partner_id

partner_last_login

partner_target_page

Redirect automatico alla pagina giusta dopo login partner

Fase 3 — LatePoint / partner pages

Ogni partner ha una pagina dedicata

Solo utenti autenticati con quel partner possono entrare

Nella pagina puoi:

mettere il booking LatePoint

filtrare servizi / medici / flow

aggiungere testi/consensi dedicati

Fase 4 — GitHub

Creare repo GitHub dedicata

Tenere dentro:

functions.php

plugin log manager

documentazione .md

file di configurazione partner

Lavorare a branch:

main

staging

feature/fh-widget

feature/partner-routing

Cosa cambiare nel functions.php
Problema attuale

Adesso myCourtesyPage() manda tutti i non loggati fuori dal sito, tranne poche pagine. Questo su un sito pubblico non va.

Soluzione

Cambiare la logica così:

non bloccare tutto il sito

bloccare solo alcune pagine protette

partner-login resta pubblica

ogni partner page richiede login

Logica target

Le pagine protette diventano, per esempio:

prenotazioni-partner1

fh-video-consulto

prenotazioni-partner2

e il controllo deve essere:

se l’utente non è loggato e prova ad aprire una pagina protetta → redirect alla pagina cortesia o login

se è loggato ma non ha partner_id corretto → blocco

se è loggato col partner corretto → accesso

Struttura riusabile consigliata

Invece di hardcodare sempre tutto, ti conviene centralizzare una mappa partner.

Esempio
function sos_partner_config_map() {
    return [
        'partner1' => [
            'target_page' => '/prenotazioni-partner1/',
            'allowed_pages' => ['prenotazioni-partner1'],
            'discount' => 3.00,
        ],
        'fh' => [
            'target_page' => '/fh-video-consulto/',
            'allowed_pages' => ['fh-video-consulto'],
            'discount' => 0.00,
        ],
    ];
}

Così hai un sistema unico per:

redirect

protezione pagina

sconti

personalizzazioni

Cosa farei io adesso, in concreto
1. Sostituire la protezione globale con protezione pagina singola

Nel functions.php, myCourtesyPage() deve diventare a pagine protette, non più “blocco tutto”.

2. Cambiare il redirect partner-login

Adesso è fisso verso /prenotazioni-partner/. Va reso dinamico in base a partner_id.

3. Salvare partner_target_page

Così puoi usare sempre lo stesso endpoint e lo stesso sistema per più partner.

4. Tenere il plugin log

Il plugin attuale va già bene per:

monitorare accessi

vedere ban/fail/ok

sbloccare IP

distinguere i partner dai log.

Come strutturare GitHub

Ti consiglio questa struttura repo:

videoconsulto-sospediatra/
├── README.md
├── docs/
│   ├── fh-integration.md
│   ├── partner-login-flow.md
│   └── deployment-checklist.md
├── wordpress/
│   ├── theme/
│   │   └── functions.php
│   └── plugins/
│       └── partner-login-log-manager.php
├── config/
│   └── partners.example.json
└── changelog.md
README.md

Dentro metti:

obiettivo progetto

partner supportati

flusso login partner

flusso F+H

deployment steps

Branching

main = produzione

staging = test

feature/... = sviluppo

Workflow consigliato

modifichi in locale

commit su GitHub

testi su staging

deploy su produzione

Risposta alla tua domanda pratica

Sì, il ragionamento corretto è:

partner-login sempre endpoint pubblico

partner page protetta

redirect deciso da te

nessun accesso se non autenticato

stessa architettura per tutti i partner

Cosa ti propongo come prossimo step

Nel prossimo messaggio ti scrivo direttamente:

il nuovo functions.php per sito pubblico + pagine partner protette

la mappa partner dinamica

il redirect partner → pagina dedicata

la checklist GitHub pronta da incollare nel README

Così parti subito con videoconsulto.sospediatra.org.

wp-config.php
File
questo è il function del sottodominio e e non ho un child attivo, che potrei attivare. si voglio centralizzare quindi tutti arrivano su partner-login e poi essere reindirizzati nella proprio pagina partner creata come ora ad esempio /prenotazioni-fh/ queste pagine devo essere pbloccate quindi il blocco deve essere su queste pagine e possiamo prevedere di inserire un campo nella pagina per il blocco tipo blocca pagina partner inserendo il codice direttamente nelle pagine wordpress oppure come fatto per gli sconti se dobbiamo applicarli lo inserisco e posso applicare e collegare anche la pagina del partner e il suo percorso alla pagina prenotazioni dedicata e io inserisco manualmente il percorso /prenotazioni-fh/ 
Ragionato per 22s

Sì: per questo sottodominio la soluzione migliore è non mettere questa logica nel tema, soprattutto se non hai un child theme attivo.

Ti consiglio questa architettura

Farei così:

child theme solo per grafica/CSS

plugin dedicato per tutta la logica partner

Perché:

non dipende dal tema attivo

non perdi nulla se cambi tema

è perfetto per GitHub

riusi lo stesso sistema su più domini/partner

Centralizzazione: sì, ma fatta bene

La tua idea è giusta: tutti passano da:

/partner-login/

poi il sistema legge il partner_id e reindirizza automaticamente alla pagina giusta, per esempio:

/prenotazioni-fh/

E quelle pagine devono essere protette.

Come centralizzerei i partner

Hai due strade.

Strada 1 — configurazione in codice

Una mappa nel plugin tipo:

partner1 => /prenotazioni-partner1/
fh       => /prenotazioni-fh/
caf      => /prenotazioni-caf/

È veloce, ma ogni volta devi modificare codice.

Strada 2 — configurazione da backend WordPress

È quella che ti consiglio.

Per ogni pagina partner aggiungi campi personalizzati tipo:

partner_protected = 1

partner_id = fh

partner_redirect_path = /prenotazioni-fh/

partner_discount = 0 oppure 100

partner_location = FH

partner_initial_status = FH

Così:

tu crei la pagina in WordPress

compili i campi

il sistema usa quei dati

niente hardcode sparso

La soluzione migliore per te

Io farei una metabox nella pagina WordPress.

Quando modifichi una pagina, vedi un box tipo:

Configurazione Partner

Proteggi questa pagina: sì/no

Partner ID: fh

Redirect partner: /prenotazioni-fh/

Sconto partner: 100

Stato iniziale booking: FH

Location dedicata: FH

Questa è la cosa più semplice da gestire da admin.

Flusso finale
Login

il partner manda la POST a /partner-login/

validi firma, timestamp, nonce

salvi partner_id sull’utente

cerchi la pagina che ha partner_id = fh

fai redirect a quella pagina

Accesso pagina

Quando un utente apre /prenotazioni-fh/:

se non è loggato → blocco

se è loggato ma partner_id non coincide → blocco

se è loggato e partner_id = fh → accesso

Quindi sì:

il blocco deve essere sulla singola pagina partner, non su tutto il sito.

Quello che non farei

Non userei:

slug hardcodati in tanti punti

controlli sparsi nel tema

logica diversa per ogni partner

Meglio un solo sistema centralizzato.

Implementazione consigliata
Plugin unico

Tipo:

sos-partner-gateway

Dentro metti:

endpoint /partner-login/

verifica firma

log accessi

ban/sblocco IP

campi pagina partner

protezione pagina partner

redirect automatico

config sconti partner

future callback F+H

GitHub

Sì, farlo ora è perfetto.

Repo consigliata
videoconsulto-sospediatra
Struttura
README.md
docs/
plugin/
  sos-partner-gateway.php
  includes/
    class-partner-login.php
    class-partner-pages.php
    class-partner-logs.php
    class-partner-discounts.php
theme/
  child-theme-notes.md
Nota importante sul wp-config.php

Hai già:

WP_DEBUG attivo

WP_DEBUG_LOG attivo

DISALLOW_FILE_EDIT attivo

Questo va bene per sviluppo, ma non lasciare sempre debug attivo in produzione a lungo.

La mia raccomandazione netta
Sì:

attiva un child theme se vuoi fare personalizzazioni grafiche

ma la logica partner mettila in un plugin

usa campi nella pagina WordPress per collegare:

partner_id

pagina protetta

percorso redirect

sconto

stato

location

No:

non continuare ad accumulare tutto nel functions.php

Prossimo passo giusto

Il passo corretto ora è:

ti preparo il plugin base sos-partner-gateway

con:

metabox pagina partner

protezione pagina

redirect partner-login

mappa backend, non hardcoded

Questa è la base giusta per F+H e per tutti i partner futuri.

si crea tutt i file compilali, crea anche le istruzioni lato partnere e tutti gli md per gli step di sviluppo da aggiornare sempre . e partiamo con il plugin intero completo e inseriamo già dei log per analizzare gli errori utilizzando in nostro plugin, attiva anche un tasto disattiva log per lo sviluppo in modo da lasciare solo quelli degli utenti. e così possiamo partire e avere log e alla fine basterà disattivare. con la base attuale dobbiamo solo migliorare senza rompere e usare i miglioramenti proposti da te. prepara lo zip completo da caricare 
Ragionato per 42s
Analisi sospesa
Ragionato per 15m 25s
Ragionato per 15m 46s

Non sono riuscito a generare lo zip nel container: i tool di scrittura file stanno restituendo errore lato ambiente. Ti lascio quindi il bundle completo pronto da copiare nei file del plugin.

Struttura cartelle

Crea questa cartella:

wp-content/plugins/sos-partner-gateway/

dentro crea:

sos-partner-gateway.php
includes/class-sos-pg-plugin.php
README.md
docs/partner-side.md
docs/development-steps.md
docs/github-workflow.md
docs/fh-integration.md
1) sos-partner-gateway.php
<?php
/**
 * Plugin Name: SOS Partner Gateway
 * Description: Gateway partner con login firmato ECC, protezione pagine partner, log integrati, sblocco IP e configurazione centralizzata per WordPress/LatePoint.
 * Version: 1.0.0
 * Author: OpenAI
 */

if (!defined('ABSPATH')) exit;

define('SOS_PG_FILE', __FILE__);
define('SOS_PG_DIR', plugin_dir_path(__FILE__));
define('SOS_PG_TABLE_LOGS', 'sos_partner_gateway_logs');

require_once SOS_PG_DIR . 'includes/class-sos-pg-plugin.php';

SOS_PG_Plugin::instance();
2) includes/class-sos-pg-plugin.php
<?php
if (!defined('ABSPATH')) exit;

class SOS_PG_Plugin {
    private static $instance = null;
    private $table_logs = '';
    private $settings_key = 'sos_pg_settings';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_logs = $wpdb->prefix . SOS_PG_TABLE_LOGS;

        register_activation_hook(SOS_PG_FILE, [$this, 'activate']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('add_meta_boxes', [$this, 'register_partner_page_metabox']);
        add_action('save_post_page', [$this, 'save_partner_page_meta'], 10, 2);

        add_action('init', [$this, 'handle_partner_login'], 1);
        add_action('template_redirect', [$this, 'protect_partner_pages'], 1);

        add_action('admin_post_sos_pg_unlock_ip', [$this, 'handle_unlock_ip']);
        add_action('admin_post_sos_pg_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_sos_pg_clear_logs', [$this, 'handle_clear_logs']);
    }

    public function activate() {
        global $wpdb;
        $table = $this->table_logs;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            level VARCHAR(20) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            partner_id VARCHAR(191) DEFAULT '' NOT NULL,
            email VARCHAR(191) DEFAULT '' NOT NULL,
            ip VARCHAR(64) DEFAULT '' NOT NULL,
            reason TEXT NULL,
            user_agent TEXT NULL,
            context LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY partner_id (partner_id),
            KEY email (email),
            KEY ip (ip)
        ) {$charset_collate};";

        dbDelta($sql);

        if (get_option($this->settings_key) === false) {
            add_option($this->settings_key, [
                'endpoint_slug' => 'partner-login',
                'courtesy_page_id' => 0,
                'debug_logging_enabled' => 1,
                'max_fail_short' => 10,
                'max_fail_long' => 25,
                'ban_short_minutes' => 60,
                'ban_long_minutes' => 1440,
                'window_short_minutes' => 10,
                'window_long_minutes' => 1440,
                'public_key_pem' => '',
                'enable_latepoint_discount_hooks' => 0,
            ]);
        }
    }

    private function get_settings() {
        $defaults = [
            'endpoint_slug' => 'partner-login',
            'courtesy_page_id' => 0,
            'debug_logging_enabled' => 1,
            'max_fail_short' => 10,
            'max_fail_long' => 25,
            'ban_short_minutes' => 60,
            'ban_long_minutes' => 1440,
            'window_short_minutes' => 10,
            'window_long_minutes' => 1440,
            'public_key_pem' => '',
            'enable_latepoint_discount_hooks' => 0,
        ];

        $settings = get_option($this->settings_key, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        return wp_parse_args($settings, $defaults);
    }

    private function current_endpoint_path() {
        $slug = trim((string) $this->get_settings()['endpoint_slug'], '/');
        return '/' . $slug;
    }

    private function current_request_path() {
        return (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    }

    private function get_ip() {
        return sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }

    private function ban_key($ip) {
        return 'sos_pg_ban_' . md5($ip);
    }

    private function fail_short_key($ip) {
        return 'sos_pg_fail_short_' . md5($ip);
    }

    private function fail_long_key($ip) {
        return 'sos_pg_fail_long_' . md5($ip);
    }

    private function public_key_resource() {
        $pem = trim((string) ($this->get_settings()['public_key_pem'] ?? ''));
        if ($pem === '') {
            return false;
        }
        return openssl_pkey_get_public($pem);
    }

    private function log_event($level, $event_type, $args = []) {
        $settings = $this->get_settings();

        if ($level === 'DEBUG' && empty($settings['debug_logging_enabled'])) {
            return;
        }

        global $wpdb;
        $wpdb->insert(
            $this->table_logs,
            [
                'created_at' => current_time('mysql'),
                'level' => substr((string) $level, 0, 20),
                'event_type' => substr((string) $event_type, 0, 50),
                'partner_id' => sanitize_text_field((string) ($args['partner_id'] ?? '')),
                'email' => sanitize_email((string) ($args['email'] ?? '')),
                'ip' => sanitize_text_field((string) ($args['ip'] ?? '')),
                'reason' => isset($args['reason']) ? wp_strip_all_tags((string) $args['reason']) : '',
                'user_agent' => isset($args['user_agent']) ? wp_strip_all_tags((string) $args['user_agent']) : '',
                'context' => !empty($args['context']) ? wp_json_encode($args['context']) : '',
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    public function register_partner_page_metabox() {
        add_meta_box(
            'sos-pg-partner-page',
            'SOS Partner Gateway',
            [$this, 'render_partner_page_metabox'],
            'page',
            'normal',
            'high'
        );
    }

    public function render_partner_page_metabox($post) {
        wp_nonce_field('sos_pg_save_partner_page', 'sos_pg_partner_page_nonce');
        $enabled = (int) get_post_meta($post->ID, '_sos_pg_partner_enabled', true);
        ?>
        <p>
            <label>
                <input type="checkbox" name="sos_pg_partner_enabled" value="1" <?php checked($enabled, 1); ?>>
                Proteggi questa pagina come pagina partner
            </label>
        </p>
        <table class="form-table">
            <tr>
                <th>Partner ID</th>
                <td>
                    <input type="text" class="regular-text" name="sos_pg_partner_id" value="<?php echo esc_attr(get_post_meta($post->ID, '_sos_pg_partner_id', true)); ?>" placeholder="fh">
                </td>
            </tr>
            <tr>
                <th>Redirect path</th>
                <td>
                    <input type="text" class="regular-text" name="sos_pg_redirect_path" value="<?php echo esc_attr(get_post_meta($post->ID, '_sos_pg_redirect_path', true)); ?>" placeholder="/prenotazioni-fh/">
                </td>
            </tr>
            <tr>
                <th>Sconto partner (€)</th>
                <td>
                    <input type="number" step="0.01" min="0" name="sos_pg_discount_amount" value="<?php echo esc_attr(get_post_meta($post->ID, '_sos_pg_discount_amount', true)); ?>" placeholder="0.00">
                </td>
            </tr>
            <tr>
                <th>Stato iniziale LatePoint</th>
                <td>
                    <input type="text" class="regular-text" name="sos_pg_initial_status" value="<?php echo esc_attr(get_post_meta($post->ID, '_sos_pg_initial_status', true)); ?>" placeholder="F+H">
                </td>
            </tr>
            <tr>
                <th>Location / Posizione</th>
                <td>
                    <input type="text" class="regular-text" name="sos_pg_location_label" value="<?php echo esc_attr(get_post_meta($post->ID, '_sos_pg_location_label', true)); ?>" placeholder="F+H">
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_partner_page_meta($post_id, $post) {
        if (!isset($_POST['sos_pg_partner_page_nonce']) || !wp_verify_nonce($_POST['sos_pg_partner_page_nonce'], 'sos_pg_save_partner_page')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_page', $post_id)) {
            return;
        }

        update_post_meta($post_id, '_sos_pg_partner_enabled', isset($_POST['sos_pg_partner_enabled']) ? 1 : 0);
        update_post_meta($post_id, '_sos_pg_partner_id', sanitize_text_field(wp_unslash($_POST['sos_pg_partner_id'] ?? '')));
        update_post_meta($post_id, '_sos_pg_redirect_path', sanitize_text_field(wp_unslash($_POST['sos_pg_redirect_path'] ?? '')));
        update_post_meta($post_id, '_sos_pg_discount_amount', sanitize_text_field(wp_unslash($_POST['sos_pg_discount_amount'] ?? '')));
        update_post_meta($post_id, '_sos_pg_initial_status', sanitize_text_field(wp_unslash($_POST['sos_pg_initial_status'] ?? '')));
        update_post_meta($post_id, '_sos_pg_location_label', sanitize_text_field(wp_unslash($_POST['sos_pg_location_label'] ?? '')));
    }

    private function find_partner_page_by_partner_id($partner_id) {
        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => 1,
            'meta_query' => [
                ['key' => '_sos_pg_partner_enabled', 'value' => 1, 'compare' => '='],
                ['key' => '_sos_pg_partner_id', 'value' => $partner_id, 'compare' => '='],
            ],
        ]);

        return !empty($pages) ? $pages[0] : null;
    }

    private function get_redirect_url_for_page($page_id) {
        $custom = (string) get_post_meta($page_id, '_sos_pg_redirect_path', true);
        if ($custom !== '') {
            return home_url('/' . ltrim($custom, '/'));
        }
        return get_permalink($page_id);
    }

    public function protect_partner_pages() {
        if (is_admin() || wp_doing_ajax() || !is_page()) {
            return;
        }

        $post_id = get_queried_object_id();
        if (!$post_id) {
            return;
        }

        $enabled = (int) get_post_meta($post_id, '_sos_pg_partner_enabled', true);
        if (!$enabled) {
            return;
        }

        $required_partner = (string) get_post_meta($post_id, '_sos_pg_partner_id', true);
        $courtesy_page_id = (int) $this->get_settings()['courtesy_page_id'];

        if (!is_user_logged_in()) {
            wp_safe_redirect($courtesy_page_id ? get_permalink($courtesy_page_id) : home_url('/'));
            exit;
        }

        $user_partner = (string) get_user_meta(get_current_user_id(), 'partner_id', true);

        if ($required_partner && $user_partner !== $required_partner) {
            $this->log_event('WARN', 'PAGE_BLOCKED_PARTNER_MISMATCH', [
                'partner_id' => $required_partner,
                'email' => wp_get_current_user()->user_email,
                'ip' => $this->get_ip(),
                'reason' => 'Utente con partner_id diverso',
            ]);
            wp_die('Accesso non consentito a questa pagina partner.', 'Accesso negato', ['response' => 403]);
        }
    }

    private function register_fail($reason, $partner_id = '', $email = '') {
        $settings = $this->get_settings();
        $ip = $this->get_ip();
        $ua = sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));

        $short_key = $this->fail_short_key($ip);
        $long_key = $this->fail_long_key($ip);
        $ban_key = $this->ban_key($ip);

        $short = (int) get_transient($short_key) + 1;
        $long = (int) get_transient($long_key) + 1;

        set_transient($short_key, $short, max(1, (int) $settings['window_short_minutes']) * MINUTE_IN_SECONDS);
        set_transient($long_key, $long, max(1, (int) $settings['window_long_minutes']) * MINUTE_IN_SECONDS);

        $this->log_event('WARN', 'PARTNER_LOGIN_FAIL', [
            'partner_id' => $partner_id,
            'email' => $email,
            'ip' => $ip,
            'reason' => $reason,
            'user_agent' => $ua,
            'context' => [
                'short_fails' => $short,
                'long_fails' => $long,
            ],
        ]);

        if ($short >= (int) $settings['max_fail_short']) {
            set_transient($ban_key, 1, max(1, (int) $settings['ban_short_minutes']) * MINUTE_IN_SECONDS);
            $this->log_event('WARN', 'PARTNER_LOGIN_BAN', [
                'partner_id' => $partner_id,
                'email' => $email,
                'ip' => $ip,
                'reason' => $reason . ' | durata: ' . (int) $settings['ban_short_minutes'] . ' minuti',
                'user_agent' => $ua,
            ]);
        } elseif ($long >= (int) $settings['max_fail_long']) {
            set_transient($ban_key, 1, max(1, (int) $settings['ban_long_minutes']) * MINUTE_IN_SECONDS);
            $this->log_event('WARN', 'PARTNER_LOGIN_BAN', [
                'partner_id' => $partner_id,
                'email' => $email,
                'ip' => $ip,
                'reason' => $reason . ' | durata: ' . (int) $settings['ban_long_minutes'] . ' minuti',
                'user_agent' => $ua,
            ]);
        }
    }

    public function handle_partner_login() {
        $endpoint = $this->current_endpoint_path();
        $request_path = $this->current_request_path();

        if ($request_path !== $endpoint && $request_path !== $endpoint . '/') {
            return;
        }

        $ip = $this->get_ip();
        $ua = sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));

        if (get_transient($this->ban_key($ip))) {
            $this->log_event('WARN', 'PARTNER_LOGIN_BLOCKED', [
                'ip' => $ip,
                'reason' => 'IP in ban temporaneo',
                'user_agent' => $ua,
            ]);
            status_header(429);
            exit('Troppi tentativi. Riprova più tardi.');
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->register_fail('Metodo non consentito');
            status_header(405);
            exit('Metodo non consentito');
        }

        $partner_id = sanitize_text_field(wp_unslash($_POST['partner_id'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['payload'] ?? ''));
        $timestamp = (int) wp_unslash($_POST['timestamp'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        $signature_b64 = (string) wp_unslash($_POST['signature'] ?? '');
        $signature = base64_decode($signature_b64, true);

        if ($partner_id === '') {
            $this->register_fail('partner_id mancante');
            status_header(400);
            exit('Partner non valido');
        }

        if ($email === '' || !is_email($email)) {
            $this->register_fail('email non valida', $partner_id, $email);
            status_header(400);
            exit('Email non valida');
        }

        if (!$timestamp || abs(time() - $timestamp) > 180) {
            $this->register_fail('timestamp scaduto', $partner_id, $email);
            status_header(403);
            exit('Richiesta scaduta');
        }

        if ($nonce === '') {
            $this->register_fail('nonce mancante', $partner_id, $email);
            status_header(400);
            exit('Nonce mancante');
        }

        if ($signature === false || empty($signature)) {
            $this->register_fail('firma mancante/non valida', $partner_id, $email);
            status_header(400);
            exit('Firma non valida');
        }

        $public_key = $this->public_key_resource();
        if (!$public_key) {
            $this->log_event('ERROR', 'PARTNER_LOGIN_KEY_ERROR', [
                'partner_id' => $partner_id,
                'email' => $email,
                'ip' => $ip,
                'reason' => 'Chiave pubblica non configurata o non valida',
                'user_agent' => $ua,
            ]);
            status_header(500);
            exit('Chiave pubblica non valida');
        }

        $message = $partner_id . '|' . $email . '|' . $timestamp . '|' . $nonce;
        $ok = openssl_verify($message, $signature, $public_key, OPENSSL_ALGO_SHA256);

        if ($ok !== 1) {
            $this->register_fail('firma non valida', $partner_id, $email);
            status_header(403);
            exit('Firma non valida');
        }

        $page = $this->find_partner_page_by_partner_id($partner_id);
        if (!$page) {
            $this->register_fail('pagina partner non configurata', $partner_id, $email);
            status_header(404);
            exit('Pagina partner non configurata');
        }

        $redirect_url = $this->get_redirect_url_for_page($page->ID);

        $user = get_user_by('email', $email);
        $is_new_user = false;

        if (!$user) {
            $user_id = wp_create_user($email, wp_generate_password(20, true, true), $email);

            if (is_wp_error($user_id)) {
                $this->log_event('ERROR', 'PARTNER_LOGIN_CREATE_USER_ERROR', [
                    'partner_id' => $partner_id,
                    'email' => $email,
                    'ip' => $ip,
                    'reason' => $user_id->get_error_message(),
                    'user_agent' => $ua,
                ]);
                status_header(500);
                exit('Errore creazione utente');
            }

            $user = get_user_by('id', $user_id);
            $is_new_user = true;
        }

        update_user_meta($user->ID, 'partner_id', $partner_id);
        update_user_meta($user->ID, 'partner_last_login', time());
        update_user_meta($user->ID, 'partner_target_page', $redirect_url);

        wp_clear_auth_cookie();
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        do_action('wp_login', $user->user_login, $user);

        delete_transient($this->fail_short_key($ip));
        delete_transient($this->fail_long_key($ip));
        delete_transient($this->ban_key($ip));

        $this->log_event('INFO', 'PARTNER_LOGIN_OK', [
            'partner_id' => $partner_id,
            'email' => $email,
            'ip' => $ip,
            'reason' => $is_new_user ? 'new_user' : 'existing_user',
            'user_agent' => $ua,
            'context' => [
                'timestamp' => $timestamp,
                'nonce' => $nonce,
                'redirect' => $redirect_url,
            ],
        ]);

        wp_safe_redirect($redirect_url);
        exit;
    }

    private function get_logs($limit = 300) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_logs} ORDER BY id DESC LIMIT %d",
                max(1, min(1000, (int) $limit))
            )
        );
    }

    private function get_partner_pages() {
        return get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'draft', 'private'],
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_key' => '_sos_pg_partner_enabled',
            'meta_value' => 1,
        ]);
    }

    private function notice() {
        $msg = sanitize_text_field(wp_unslash($_GET['msg'] ?? ''));
        $map = [
            'saved' => 'Impostazioni salvate.',
            'unlocked' => 'IP sbloccato correttamente.',
            'ip_missing' => 'IP mancante.',
            'cleared' => 'Log svuotati.',
        ];

        if (isset($map[$msg])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($map[$msg]) . '</p></div>';
        }
    }

    public function admin_menu() {
        add_menu_page('SOS Partner Gateway', 'SOS Partner Gateway', 'manage_options', 'sos-partner-gateway', [$this, 'render_logs_page'], 'dashicons-shield', 58);
        add_submenu_page('sos-partner-gateway', 'Log', 'Log', 'manage_options', 'sos-partner-gateway', [$this, 'render_logs_page']);
        add_submenu_page('sos-partner-gateway', 'Impostazioni', 'Impostazioni', 'manage_options', 'sos-partner-gateway-settings', [$this, 'render_settings_page']);
        add_submenu_page('sos-partner-gateway', 'Pagine Partner', 'Pagine Partner', 'manage_options', 'sos-partner-gateway-pages', [$this, 'render_pages_page']);
    }

    public function render_logs_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        $logs = $this->get_logs();
        $settings = $this->get_settings();

        echo '<div class="wrap"><h1>SOS Partner Gateway — Log</h1>';
        $this->notice();
        echo '<p><strong>Endpoint:</strong> <code>' . esc_html(home_url($this->current_endpoint_path() . '/')) . '</code></p>';
        echo '<p><strong>Debug logs:</strong> ' . (!empty($settings['debug_logging_enabled']) ? 'attivi' : 'disattivati') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:16px 0;">';
        wp_nonce_field('sos_pg_clear_logs');
        echo '<input type="hidden" name="action" value="sos_pg_clear_logs"><button class="button">Svuota log</button></form>';

        echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Evento</th><th>Partner</th><th>Email</th><th>IP</th><th>Motivo</th><th>Context</th><th>Azione</th></tr></thead><tbody>';

        if (!$logs) {
            echo '<tr><td colspan="8">Nessun log.</td></tr>';
        }

        foreach ((array) $logs as $log) {
            echo '<tr>';
            echo '<td>' . esc_html($log->created_at) . '</td>';
            echo '<td>' . esc_html($log->event_type) . '</td>';
            echo '<td>' . esc_html($log->partner_id) . '</td>';
            echo '<td>' . esc_html($log->email) . '</td>';
            echo '<td>' . esc_html($log->ip) . '</td>';
            echo '<td>' . esc_html($log->reason) . '</td>';
            echo '<td style="max-width:300px;word-break:break-word;">' . esc_html((string) $log->context) . '</td>';
            echo '<td>';
            if (!empty($log->ip)) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('sos_pg_unlock_ip');
                echo '<input type="hidden" name="action" value="sos_pg_unlock_ip">';
                echo '<input type="hidden" name="ip" value="' . esc_attr($log->ip) . '">';
                echo '<button class="button button-small">Sblocca IP</button>';
                echo '</form>';
            } else {
                echo '—';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        $settings = $this->get_settings();
        $pages = get_pages(['sort_column' => 'post_title']);

        echo '<div class="wrap"><h1>SOS Partner Gateway — Impostazioni</h1>';
        $this->notice();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('sos_pg_save_settings');
        echo '<input type="hidden" name="action" value="sos_pg_save_settings">';
        echo '<table class="form-table">';
        echo '<tr><th>Slug endpoint login</th><td><input type="text" class="regular-text" name="endpoint_slug" value="' . esc_attr($settings['endpoint_slug']) . '"></td></tr>';

        echo '<tr><th>Pagina di cortesia</th><td><select name="courtesy_page_id"><option value="0">— Nessuna —</option>';
        foreach ($pages as $p) {
            echo '<option value="' . esc_attr($p->ID) . '" ' . selected((int) $settings['courtesy_page_id'], (int) $p->ID, false) . '>' . esc_html($p->post_title) . ' (#' . $p->ID . ')</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th>Debug logs sviluppo</th><td><label><input type="checkbox" name="debug_logging_enabled" value="1" ' . checked(!empty($settings['debug_logging_enabled']), true, false) . '> Attiva</label></td></tr>';

        echo '<tr><th>Rate limit breve</th><td><input type="number" name="max_fail_short" value="' . esc_attr($settings['max_fail_short']) . '" min="1"> errori in <input type="number" name="window_short_minutes" value="' . esc_attr($settings['window_short_minutes']) . '" min="1"> minuti → ban <input type="number" name="ban_short_minutes" value="' . esc_attr($settings['ban_short_minutes']) . '" min="1"> minuti</td></tr>';

        echo '<tr><th>Rate limit lungo</th><td><input type="number" name="max_fail_long" value="' . esc_attr($settings['max_fail_long']) . '" min="1"> errori in <input type="number" name="window_long_minutes" value="' . esc_attr($settings['window_long_minutes']) . '" min="1"> minuti → ban <input type="number" name="ban_long_minutes" value="' . esc_attr($settings['ban_long_minutes']) . '" min="1"> minuti</td></tr>';

        echo '<tr><th>Chiave pubblica PEM</th><td><textarea class="large-text code" rows="12" name="public_key_pem">' . esc_textarea($settings['public_key_pem']) . '</textarea></td></tr>';
        echo '</table>';
        submit_button('Salva impostazioni');
        echo '</form></div>';
    }

    public function render_pages_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        $pages = $this->get_partner_pages();

        echo '<div class="wrap"><h1>SOS Partner Gateway — Pagine Partner</h1>';
        echo '<p>Configura il box <strong>SOS Partner Gateway</strong> dentro l’editor pagina.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Pagina</th><th>Partner ID</th><th>Redirect</th><th>Sconto</th><th>Stato</th><th>Location</th></tr></thead><tbody>';

        if (!$pages) {
            echo '<tr><td colspan="6">Nessuna pagina partner configurata.</td></tr>';
        }

        foreach ((array) $pages as $page) {
            echo '<tr>';
            echo '<td><a href="' . esc_url(get_edit_post_link($page->ID)) . '">' . esc_html($page->post_title) . '</a></td>';
            echo '<td>' . esc_html(get_post_meta($page->ID, '_sos_pg_partner_id', true)) . '</td>';
            echo '<td>' . esc_html(get_post_meta($page->ID, '_sos_pg_redirect_path', true) ?: get_permalink($page->ID)) . '</td>';
            echo '<td>' . esc_html(get_post_meta($page->ID, '_sos_pg_discount_amount', true)) . '</td>';
            echo '<td>' . esc_html(get_post_meta($page->ID, '_sos_pg_initial_status', true)) . '</td>';
            echo '<td>' . esc_html(get_post_meta($page->ID, '_sos_pg_location_label', true)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        check_admin_referer('sos_pg_save_settings');

        $settings = $this->get_settings();
        $settings['endpoint_slug'] = sanitize_title(wp_unslash($_POST['endpoint_slug'] ?? 'partner-login'));
        $settings['courtesy_page_id'] = absint($_POST['courtesy_page_id'] ?? 0);
        $settings['debug_logging_enabled'] = !empty($_POST['debug_logging_enabled']) ? 1 : 0;
        $settings['max_fail_short'] = max(1, absint($_POST['max_fail_short'] ?? 10));
        $settings['max_fail_long'] = max(1, absint($_POST['max_fail_long'] ?? 25));
        $settings['ban_short_minutes'] = max(1, absint($_POST['ban_short_minutes'] ?? 60));
        $settings['ban_long_minutes'] = max(1, absint($_POST['ban_long_minutes'] ?? 1440));
        $settings['window_short_minutes'] = max(1, absint($_POST['window_short_minutes'] ?? 10));
        $settings['window_long_minutes'] = max(1, absint($_POST['window_long_minutes'] ?? 1440));
        $settings['public_key_pem'] = trim((string) wp_unslash($_POST['public_key_pem'] ?? ''));

        update_option($this->settings_key, $settings);

        wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway-settings', 'msg' => 'saved'], admin_url('admin.php')));
        exit;
    }

    public function handle_unlock_ip() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        check_admin_referer('sos_pg_unlock_ip');

        $ip = sanitize_text_field(wp_unslash($_POST['ip'] ?? ''));
        if ($ip === '') {
            wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway', 'msg' => 'ip_missing'], admin_url('admin.php')));
            exit;
        }

        delete_transient($this->ban_key($ip));
        delete_transient($this->fail_short_key($ip));
        delete_transient($this->fail_long_key($ip));

        $this->log_event('INFO', 'PARTNER_LOGIN_MANUAL_UNLOCK', [
            'ip' => $ip,
            'reason' => 'Sblocco manuale da admin',
        ]);

        wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway', 'msg' => 'unlocked'], admin_url('admin.php')));
        exit;
    }

    public function handle_clear_logs() {
        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato');
        }

        check_admin_referer('sos_pg_clear_logs');

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_logs}");

        wp_safe_redirect(add_query_arg(['page' => 'sos-partner-gateway', 'msg' => 'cleared'], admin_url('admin.php')));
        exit;
    }
}
3) README.md
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
4) docs/partner-side.md
# Istruzioni lato partner

## Endpoint
`POST https://videoconsulto.sospediatra.org/partner-login/`

## Campi richiesti
- `partner_id`
- `payload` = email utente
- `timestamp`
- `nonce`
- `signature`

## Stringa da firmare
`partner_id|payload|timestamp|nonce`

## Form HTML esempio
```html
<form id="partnerLoginForm" action="https://videoconsulto.sospediatra.org/partner-login/" method="POST">
  <input type="hidden" name="partner_id" value="fh">
  <input type="hidden" name="payload" value="mario.rossi@example.com">
  <input type="hidden" name="timestamp" value="1710267000">
  <input type="hidden" name="nonce" value="abc123xyz">
  <input type="hidden" name="signature" value="BASE64_SIGNATURE">
</form>
<script>document.getElementById('partnerLoginForm').submit();</script>

---

## 5) `docs/development-steps.md`

```md
# Step di sviluppo da aggiornare sempre

## Fase 1 — Base partner gateway
- [x] Plugin autonomo, non dipendente dal tema
- [x] Endpoint unico `/partner-login/`
- [x] Firma ECC
- [x] Log e sblocco IP
- [x] Protezione pagine partner
- [x] Redirect dinamico per partner

## Fase 2 — F+H
- [ ] Pagina dedicata `/prenotazioni-fh/`
- [ ] Stato LatePoint dedicato `F+H`
- [ ] Location LatePoint dedicata `F+H`
- [ ] Servizi video consulto dedicati
- [ ] Quota da versare = 0
- [ ] Email differenziate per stato/servizio/posizione
- [ ] Parametri ingresso da F+H
- [ ] Callback booking verso F+H
- [ ] Callback esito pagamento da F+H
6) docs/github-workflow.md
# GitHub workflow consigliato

## Repository
`videoconsulto-sospediatra`

## Branch
- `main` → produzione
- `staging` → test
- `feature/...` → nuove funzionalità
7) docs/fh-integration.md
# F+H integration checklist

## Dominio
`https://videoconsulto.sospediatra.org`

## Endpoint login partner
`/partner-login/`

## Pagina partner prevista
`/prenotazioni-fh/`

## Configurazione pagina WordPress
- Proteggi come pagina partner = sì
- Partner ID = `fh`
- Redirect path = `/prenotazioni-fh/`
- Stato iniziale = `F+H`
- Location = `F+H`
Cosa fare subito

crea la cartella plugin

incolla questi file

attiva il plugin

vai in SOS Partner Gateway → Impostazioni

incolla la public.pem nel campo Chiave pubblica PEM

crea la pagina /prenotazioni-fh/

nella pagina, nel box SOS Partner Gateway metti:

Proteggi questa pagina come pagina partner = sì

Partner ID = fh

Redirect path = /prenotazioni-fh/

Stato iniziale LatePoint = F+H

Location = F+H

fai il test partner-login

Nota importante

Questo plugin è la base stabile:

login partner

protezione singole pagine

redirect centralizzato

log e sblocco IP

debug toggle

La parte LatePoint avanzata, callback F+H e pricing la farei nel passo successivo, sopra questa base, così non rompi nulla.

Se vuoi, nel prossimo messaggio ti preparo la v2 già pronta con:

funzione helper get_current_partner_id()

mappa partner page

base callback webhook F+H

file README più completo in stile repo GitHub.

functions.php
File
partner-login-log-manager-db-clean-fixed-v134 (1).php
File
ho  creato i file ma voglio aggiornarti sulle modfiche per i log e la sicurezza del plugin di partenza del caf. capiamo cosa tenere per i log e poi procedi con lo zip aggiornato con i file per github 
Ragionato per 20s
Ragionato per 8m 19s
Analisi sospesa

Ho confrontato i due file e la direzione giusta è questa:

da tenere del CAF

verifica firma partner_id|email|timestamp|nonce

controllo timestamp a 180 secondi

ban con transients a finestra breve/lunga

sblocco manuale IP

salvataggio partner_id sull’utente

riduzione dei log OK con finestra temporale, che nel functions.php hai già impostato a 5 minuti ed è molto utile per non sporcare i log.

da non portare nel nuovo plugin

parsing del file error_log_php come fonte principale

sync file → DB come base del sistema

Per il nuovo plugin partner conviene loggare direttamente in DB, perché il plugin log manager che hai adesso è molto buono per analisi e filtri, ma nasce per “recuperare” i log da file e ripulirli. Nel nuovo sistema possiamo prendere il meglio di quella UI e delle azioni manuali, ma senza passare da error_log_php. Il plugin Partner Login Log Manager mostra bene cosa vale la pena tenere: blocco/sblocco IP, paginazione, sintesi, distinzione eventi, filtro per partner/email/IP.

Quindi, per i log del nuovo plugin, io terrei

eventi tipizzati direttamente in DB:

PARTNER_LOGIN_OK

PARTNER_LOGIN_FAIL

PARTNER_LOGIN_BAN

PARTNER_LOGIN_BLOCKED

PARTNER_LOGIN_MANUAL_UNLOCK

PARTNER_LOGIN_MANUAL_BAN

PAGE_BLOCKED_PARTNER_MISMATCH

campi:

partner_id

email

ip

reason

duration

user_agent

context

il toggle “Debug logs sviluppo”

la riduzione dei log OK

blocco/sblocco manuale IP da admin

impostazioni rate limit da backend

Cosa non terrei come base

sync del file log

import da error_log_php

deduplica via hash del file log

diagnostica “sporco/duplicato” del file

Quella parte è utile solo se vuoi continuare ad analizzare un file server esterno. Per il nuovo gateway non serve.

Sicurezza da portare nel nuovo plugin

Dal functions.php del CAF terrei praticamente tutto il blocco di sicurezza:

metodo solo POST

chiave pubblica PEM

firma ECC

timestamp

nonce obbligatorio

ban con due finestre

reset contatori su login riuscito

partner_id salvato nel profilo

throttle del log OK anti-spam.

Una correzione importante sul CAF

Nel file CAF il redirect finale è ancora fisso su:

wp_safe_redirect(home_url('/prenotazioni-partner/'));

quindi nel plugin nuovo va assolutamente sostituito con redirect dinamico verso la pagina partner configurata.

Scelta finale consigliata

Per il nuovo SOS Partner Gateway:

base sicurezza CAF + UI/admin del log manager
ma con log nativi su DB, non su file.

È la combinazione migliore.

Sullo zip

Ho provato a generarlo più volte, ma in questo momento l’ambiente sta restituendo errore anche sulle scritture file, quindi non riesco a produrre lo zip scaricabile in modo affidabile adesso.

Per non bloccarti, nel prossimo messaggio ti restituisco direttamente il bundle finale completo file per file, già allineato a questa decisione:

plugin SOS Partner Gateway

file GitHub/README/docs

log DB integrati

toggle debug

blocco/sblocco IP

metabox pagina partner

redirect dinamico

e lo faccio in forma pronta da incollare senza cambiare architettura.

scrivimi tutto per creare la nuova repository 

Ecco tutto il necessario per creare la nuova repository GitHub in modo ordinato e riusabile.

Nome repo consigliato
videoconsulto-sospediatra
Struttura cartelle

Crea questa struttura:

videoconsulto-sospediatra/
├── README.md
├── .gitignore
├── plugin/
│   └── sos-partner-gateway/
│       ├── sos-partner-gateway.php
│       ├── includes/
│       │   └── class-sos-pg-plugin.php
│       └── docs/
│           ├── partner-side.md
│           ├── development-steps.md
│           ├── github-workflow.md
│           └── fh-integration.md
├── docs/
│   ├── architecture.md
│   ├── deployment.md
│   └── changelog.md
└── releases/
1) README.md
# videoconsulto-sospediatra

Repository progetto per il dominio:

`https://videoconsulto.sospediatra.org`

## Obiettivo
Centralizzare tutta la logica partner in un plugin WordPress dedicato, indipendente dal tema, per gestire:

- login partner firmato ECC
- endpoint unico `/partner-login/`
- protezione singole pagine partner
- redirect dinamico alla pagina corretta
- log integrati in database
- ban / sblocco IP
- base riusabile per partner futuri
- supporto futuro a integrazioni tipo F+H

## Plugin principale
Il plugin si trova in:

`plugin/sos-partner-gateway/`

## Flusso base
1. Il partner invia una POST firmata a `/partner-login/`
2. WordPress verifica firma, timestamp, nonce e rate limiting
3. L’utente viene creato o recuperato
4. Viene salvato il `partner_id`
5. Il sistema reindirizza alla pagina partner configurata
6. La pagina partner è accessibile solo se l’utente è autenticato con il `partner_id` corretto

## Principi architetturali
- logica partner nel plugin, non nel tema
- sito pubblico con protezione solo sulle pagine partner
- pagina partner configurata da backend WordPress
- partner pages riusabili per più integrazioni
- documentazione tecnica sempre versionata insieme al codice

## Branch consigliati
- `main` → produzione
- `staging` → test
- `feature/...` → sviluppo nuove funzionalità

## Step operativi
- configurare il plugin
- creare la pagina partner
- assegnare `partner_id`
- testare il login firmato
- monitorare i log nel backend

## Partner previsti
- `partner1`
- `fh`
- altri partner futuri

## Note
La base tecnica deriva dal sistema già costruito per il CAF, migliorata con:
- log DB
- redirect dinamico
- protezione pagina partner
- configurazione backend più pulita
2) .gitignore
# OS
.DS_Store
Thumbs.db

# IDE
.vscode/
.idea/

# Logs
*.log

# Archives
*.zip

# Environment
.env
.env.local

# WordPress junk
node_modules/
vendor/
3) docs/architecture.md
# Architecture

## Dominio
`videoconsulto.sospediatra.org`

## Obiettivo
Gestire partner esterni tramite un gateway unico che controlla autenticazione, sicurezza, redirect e accesso alle pagine partner.

## Componenti principali
### 1. Endpoint partner login
Percorso pubblico:
`/partner-login/`

Riceve:
- `partner_id`
- `payload` = email utente
- `timestamp`
- `nonce`
- `signature`

Verifica:
- firma ECC
- timestamp
- nonce presente
- rate limiting
- ban IP

### 2. Pagine partner protette
Le pagine WordPress possono essere marcate come:
- pagina partner protetta
- associate a un `partner_id`
- collegate a un redirect path

### 3. Redirect dinamico
Dopo login valido:
- utente creato/recuperato
- `partner_id` salvato
- redirect verso la pagina partner configurata

### 4. Log integrati
I log vengono salvati in DB, non su file server.

Eventi principali:
- `PARTNER_LOGIN_OK`
- `PARTNER_LOGIN_FAIL`
- `PARTNER_LOGIN_BAN`
- `PARTNER_LOGIN_BLOCKED`
- `PARTNER_LOGIN_MANUAL_UNLOCK`
- `PARTNER_LOGIN_MANUAL_BAN`
- `PAGE_BLOCKED_PARTNER_MISMATCH`

### 5. Admin backend
Il plugin deve offrire:
- vista log
- impostazioni generali
- sblocco IP
- blocco manuale IP
- gestione pagine partner

## Riutilizzo
La stessa architettura può essere usata per:
- CAF
- F+H
- altri partner futuri

## Scelta tecnica
La logica va nel plugin e non nel tema, per:
- riuso
- versionamento GitHub
- minore rischio in caso di cambio tema
4) docs/deployment.md
# Deployment

## Ambiente target
WordPress su:
`videoconsulto.sospediatra.org`

## Installazione plugin
1. Copiare la cartella:
   `plugin/sos-partner-gateway/`
   in:
   `wp-content/plugins/`
2. Attivare il plugin da backend WordPress

## Configurazione iniziale
### Impostazioni plugin
- endpoint slug: `partner-login`
- pagina di cortesia: scegliere pagina blocco non autenticati
- incollare `public.pem`
- configurare rate limit
- attivare/disattivare debug log

### Pagine partner
Per ogni pagina partner:
- aprire la pagina
- usare il box `SOS Partner Gateway`
- attivare protezione pagina
- impostare `partner_id`
- impostare redirect path
- opzionalmente sconto/stato/location

## Test minimi
1. POST firmata corretta
2. firma errata
3. timestamp scaduto
4. troppi errori → ban
5. sblocco IP da backend
6. accesso diretto a pagina partner senza login
7. accesso con partner_id errato

## Produzione
Prima del go-live:
- lasciare attivi solo i log utente/sicurezza
- disattivare debug logs sviluppo se non servono
5) docs/changelog.md
# Changelog

## 1.0.0
- base plugin partner gateway
- endpoint unico `/partner-login/`
- firma ECC
- protezione singole pagine partner
- redirect dinamico
- log DB
- blocco/sblocco IP
- documentazione iniziale

## 1.0.1
- consolidamento regole log dal progetto CAF
- riduzione log `OK`
- miglioramento struttura repository
- documentazione GitHub e deployment
6) plugin/sos-partner-gateway/docs/partner-side.md
# Istruzioni lato partner

## Endpoint
`POST https://videoconsulto.sospediatra.org/partner-login/`

## Content-Type
`application/x-www-form-urlencoded`

## Campi richiesti
- `partner_id`
- `payload` = email utente
- `timestamp`
- `nonce`
- `signature`

## Stringa da firmare
`partner_id|payload|timestamp|nonce`

## Firma
- usare la chiave privata del partner
- inviare `signature` in Base64

## Esempio
### Dati
- `partner_id=fh`
- `payload=mario.rossi@example.com`
- `timestamp=1710267000`
- `nonce=abc123xyz`

### Stringa firmata
`fh|mario.rossi@example.com|1710267000|abc123xyz`

## POST HTML esempio
```html
<form id="partnerLoginForm" action="https://videoconsulto.sospediatra.org/partner-login/" method="POST">
  <input type="hidden" name="partner_id" value="fh">
  <input type="hidden" name="payload" value="mario.rossi@example.com">
  <input type="hidden" name="timestamp" value="1710267000">
  <input type="hidden" name="nonce" value="abc123xyz">
  <input type="hidden" name="signature" value="BASE64_SIGNATURE">
</form>
<script>
document.getElementById('partnerLoginForm').submit();
</script>
Risultato atteso

Se la richiesta è valida:

utente creato o recuperato

login automatico

redirect alla pagina partner assegnata


---

# 7) `plugin/sos-partner-gateway/docs/development-steps.md`

```md
# Step di sviluppo

## Fase 1 — Base gateway
- [x] Plugin autonomo
- [x] Endpoint unico `/partner-login/`
- [x] Firma ECC
- [x] Rate limit breve/lungo
- [x] Ban / sblocco IP
- [x] Pagine partner protette
- [x] Redirect dinamico

## Fase 2 — Hardening log e sicurezza
- [x] Log DB
- [x] Toggle debug logs sviluppo
- [x] Riduzione log `OK`
- [x] blocco manuale IP
- [x] sblocco manuale IP

## Fase 3 — F+H
- [ ] Pagina `/prenotazioni-fh/`
- [ ] Stato LatePoint `F+H`
- [ ] Location `F+H`
- [ ] Email dedicate
- [ ] Callback booking verso F+H
- [ ] Callback esito pagamento da F+H

## Fase 4 — Widget standard partner
- [ ] normalizzazione parametri ingresso
- [ ] prefill dati utente
- [ ] embed/iframe standard
- [ ] framework riusabile per futuri partner
8) plugin/sos-partner-gateway/docs/github-workflow.md
# GitHub workflow

## Repo
`videoconsulto-sospediatra`

## Branch
- `main`
- `staging`
- `feature/...`

## Flusso
1. sviluppare in `feature/...`
2. testare in `staging`
3. merge su `main`
4. creare release zip
5. installare sul sito

## File da aggiornare sempre
- `README.md`
- `docs/architecture.md`
- `docs/deployment.md`
- `docs/changelog.md`
- `plugin/sos-partner-gateway/docs/*.md`
9) plugin/sos-partner-gateway/docs/fh-integration.md
# F+H integration checklist

## Dominio
`https://videoconsulto.sospediatra.org`

## Endpoint
`/partner-login/`

## Pagina partner prevista
`/prenotazioni-fh/`

## Configurazione pagina WordPress
- Proteggi come pagina partner = sì
- Partner ID = `fh`
- Redirect path = `/prenotazioni-fh/`
- Stato iniziale = `F+H`
- Location = `F+H`

## Flusso target
- booking su SOS Medici
- pagamento su F+H
- prenotazione iniziale in stato tecnico `F+H`
- conferma finale dopo esito corretto

## Da completare
- callback booking → F+H
- callback payment status ← F+H
- regole email dedicate
Comandi Git da fare in locale

Dentro la cartella videoconsulto-sospediatra:

git init
git branch -M main
git add .
git commit -m "Initial structure for SOS Partner Gateway project"

Poi crea la repo su GitHub e collega il remote:

git remote add origin https://github.com/TUO-USERNAME/videoconsulto-sospediatra.git
git push -u origin main
Primo step pratico dopo la repo

Metti dentro per primi:

README.md

docs/*

la cartella plugin/sos-partner-gateway/

Poi iniziamo a versionare il plugin vero.

Nel prossimo messaggio ti posso scrivere anche il contenuto completo del plugin in formato pronto da incollare nella repo.