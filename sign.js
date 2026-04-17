const crypto = require('crypto');
const fs = require('fs');

// CARICA LA TUA CHIAVE PRIVATA
const privateKey = fs.readFileSync('private.pem', 'utf8');

// DATI (CAMBIA QUESTI OGNI TEST)
const partner_id = 'stgfake';
const email = 'utente3@testolo3.com';
const timestamp = Math.floor(Date.now()/1000);
const nonce = Math.random().toString(36).substring(2, 12);

// STRINGA DA FIRMARE
const message = `${partner_id}|${email}|${timestamp}|${nonce}`;

// FIRMA
const sign = crypto.createSign('RSA-SHA256');
sign.update(message);
sign.end();

const signature = sign.sign(privateKey, 'base64');

// OUTPUT
console.log('timestamp:', timestamp);
console.log('nonce:', nonce);
console.log('signature:', signature);
console.log('message:', message);