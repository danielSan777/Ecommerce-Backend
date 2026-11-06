<?php

// CORS: solo el Angular de la tienda/panel puede llamar a la API desde el navegador.
// FRONTEND_URL admite varios orígenes separados por coma (p. ej. https://jardindesele.com,http://localhost:4200).
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('FRONTEND_URL', 'https://jardindesele.com'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false, // tokens Bearer: no se usan cookies entre dominios
];
