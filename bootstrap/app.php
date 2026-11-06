<?php

use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;
use App\Shared\Infraestructura\Http\Middleware\IpRealDeCloudflare;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Primero: la IP real del visitante detrás de Cloudflare
        $middleware->prepend(IpRealDeCloudflare::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Lo que no existe en el catálogo responde 404 con JSON estable (mensajes en inglés, tal cual)
        $exceptions->render(fn (CatalogoNoEncontrado $e) => response()->json(['code' => 'not_found', 'message' => $e->getMessage()], 404));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
