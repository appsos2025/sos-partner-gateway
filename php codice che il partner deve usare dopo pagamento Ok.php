<?php

$url = "https://videoconsulto.sospediatra.org/partner-payment-callback/";

$secret = "IL_TUO_PAYMENT_CALLBACK_SECRET"; // condiviso con il main site

$data = [
    "booking_id" => 14553,
    "transaction_id" => "caf-14553-" . time(),
    "status" => "paid_partner",
    "partner_id" => "caf",
    "amount_paid" => 25.00,
    "currency" => "EUR",
    "payment_provider" => "stripe", // o paypal / bonifico / ecc
    "external_reference" => "ORDER-12345"
];

// BODY JSON
$body = json_encode($data, JSON_UNESCAPED_SLASHES);

// FIRMA HMAC
$signature = hash_hmac('sha256', $body, $secret);

// CURL
$ch = curl_init($url);

curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-SOSPG-Signature: ' . $signature
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

// DEBUG
echo "HTTP: " . $http_code . "\n";
echo "Response: " . $response . "\n";