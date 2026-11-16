<?php

// Router de `php artisan serve` (Laravel usa este archivo si existe en la raíz). Es el de Laravel más la cabecera CORS de /media.

$publicPath = getcwd();

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// This file allows us to emulate Apache's "mod_rewrite" functionality from the
// built-in PHP web server. This provides a convenient way to test a Laravel
// application without having installed a "real" web server software here.
if ($uri !== '/' && file_exists($publicPath.$uri)) {
    // Solo para `php artisan serve` (en el servidor real lo hace public/.htaccess): las fotos de /media
    // llevan la cabecera CORS para que el personalizador pueda exportar la foto del carrito.
    // (El servidor integrado de PHP descarta las cabeceras si devuelve `false`, así que aquí se entrega el archivo.)
    if (str_starts_with($uri, '/media/') && is_file($publicPath.$uri) && ! str_contains($uri, '..')) {
        $tipos = ['webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
            'svg' => 'image/svg+xml', 'mp4' => 'video/mp4', 'webm' => 'video/webm'];
        $tipo = $tipos[strtolower(pathinfo($uri, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

        header('Access-Control-Allow-Origin: *');
        header('Content-Type: '.$tipo);
        header('Content-Length: '.filesize($publicPath.$uri));
        readfile($publicPath.$uri);

        return true;
    }

    return false;
}

$formattedDateTime = date('D M j H:i:s Y');

$requestMethod = $_SERVER['REQUEST_METHOD'];
$remoteAddress = $_SERVER['REMOTE_ADDR'].':'.$_SERVER['REMOTE_PORT'];

file_put_contents('php://stdout', "[$formattedDateTime] $remoteAddress [$requestMethod] URI: $uri\n");

require_once $publicPath.'/index.php';
