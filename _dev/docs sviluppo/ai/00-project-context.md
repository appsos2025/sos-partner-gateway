# Project Context

## Nome progetto
SOS Partner Gateway

## Obiettivo attuale del plugin
Plugin WordPress che gestisce:
- login firmato partner -> sito principale
- verifica firma ECC
- creazione/routing accesso lato sito principale
- integrazione con LatePoint
- webhook booking_created verso partner
- callback pagamento dal partner verso sito principale
- shortcode self-use per uso sui nostri sottodomini o nello stesso ecosistema WordPress

## Modalità già esistenti
1. Sito principale (gateway)
2. Sito partner WordPress
3. Self-use / shortcode per sottodomini o uso interno

## Stato attuale
Il flusso WordPress esistente funziona e NON deve essere rotto.

## Nuovo obiettivo
Aggiungere supporto per partner esterni che NON hanno WordPress, mantenendo un solo plugin e la piena backward compatibility.

## Vincolo strategico
Non vogliamo un secondo plugin.
Vogliamo un solo plugin che supporti:
- partner WordPress
- shortcode/self-use
- partner esterni via REST API

## Regola fondamentale
L’attuale comportamento funzionante è la baseline da preservare.