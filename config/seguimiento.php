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
    'meta' => [
        'pixel_id' => env('META_PIXEL_ID'),
        'access_token' => env('META_ACCESS_TOKEN'),
        // Solo para probar en el Administrador de eventos (pestaña «Probar eventos»); vacío en producción
        'test_event_code' => env('META_TEST_EVENT_CODE'),
        'api_version' => env('META_API_VERSION', 'v25.0'),
        // Meta rechaza todo el lote si un evento tiene más de 7 días
        'max_dias' => 7,
    ],
];
