<?php

// Envío de compras reales desde el servidor a GA4, Meta y TikTok (Paso 20). Las claves van solo en el `.env`.
// Cada plataforma se activa sola cuando tiene todos sus datos; sin ellos queda apagada y no pasa nada.
return [
    'ga4' => [
        'measurement_id' => env('GA4_MEASUREMENT_ID'),
        'api_secret' => env('GA4_API_SECRET'),
        // GA4 acepta compras de hasta 72 horas atrás
        'max_horas' => 72,
        'url' => 'https://www.google-analytics.com/mp/collect',
    ],
];
