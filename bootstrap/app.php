<?php

use App\Core\Administracion\Infraestructura\Consola\CrearAdministrador;
use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;
use App\Core\Catalogo\Dominio\Excepciones\CategoriaConProductos;
use App\Shared\Infraestructura\Http\Middleware\IpRealDeCloudflare;
use App\Shared\Infraestructura\Http\Middleware\RequiereAdministrador;
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
    ->withCommands([CrearAdministrador::class])
    ->withMiddleware(function (Middleware $middleware): void {
        // Primero: la IP real del visitante detrás de Cloudflare
        $middleware->prepend(IpRealDeCloudflare::class);
        $middleware->alias(['admin' => RequiereAdministrador::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Lo que no existe en el catálogo responde 404 con JSON estable (mensajes en inglés, tal cual)
        $exceptions->render(fn (CatalogoNoEncontrado $e) => response()->json(['code' => 'not_found', 'message' => $e->getMessage()], 404));
        // Categoría con productos: 409 con la lista, para que Angular muestre cuáles son
        $exceptions->render(fn (CategoriaConProductos $e) => response()->json([
            'code' => 'category_has_products',
            'message' => $e->getMessage(),
            'products_count' => $e->total,
            'products' => $e->productos,
        ], 409));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
