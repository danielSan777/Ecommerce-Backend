<?php

declare(strict_types=1);

namespace App\Shared\Infraestructura\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege las rutas de administración. Falla cerrado: sin un usuario autenticado responde 401.
 * (La autenticación real con tokens y doble factor se agrega en el módulo Administración.)
 */
final class RequiereAdministrador
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            return response()->json(['code' => 'unauthenticated', 'message' => 'Debes iniciar sesión como administrador.'], 401);
        }

        return $next($request);
    }
}
