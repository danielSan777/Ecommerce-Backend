<?php

return [
    // simulated = solo desarrollo (nunca en producción); real = Cajita de PayPhone con StoreID y Token del ambiente
    'mode' => env('PAYPHONE_MODE', 'simulated'),
    'store_id' => env('PAYPHONE_STORE_ID'),
    'token' => env('PAYPHONE_TOKEN'),
    'confirm_url' => env('PAYPHONE_CONFIRM_URL', 'https://paymentbox.payphonetodoesposible.com/api/confirm'),
    'box_css' => 'https://cdn.payphonetodoesposible.com/box/v2.0/payphone-payment-box.css',
    'box_js' => 'https://cdn.payphonetodoesposible.com/box/v2.0/payphone-payment-box.js',
    'form_minutes' => 10,      // vigencia de cada Cajita
    'reserve_minutes' => 15,   // cupo apartado mientras se paga
];
