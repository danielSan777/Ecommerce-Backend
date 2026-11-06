<?php

declare(strict_types=1);

namespace App\Shared\Infraestructura\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege las rutas de administración. Falla cerrado: sin un usuario autenticado responde 401.
 * (Tokens Bearer del módulo Administración; el doble factor se agrega después.)
 */
final class RequiereAdministrador
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = Auth::guard('admin')->user();

        if ($usuario === null) {
            return response()->json(['code' => 'unauthenticated', 'message' => 'Debes iniciar sesión como administrador.'], 401);
        }

        $request->setUserResolver(fn () => $usuario);

        return $next($request);
    }
}
