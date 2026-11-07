<?php

declare(strict_types=1);

namespace App\Shared\Infraestructura\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege las rutas de administración. Falla cerrado: sin sesión responde 401 y con sesión de cliente, 403.
 * El rol se lee de la base de datos en cada petición, nunca del navegador.
 */
final class RequiereAdministrador
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = Auth::guard('api')->user();

        if ($usuario === null) {
            return response()->json(['code' => 'unauthenticated', 'message' => 'Debes iniciar sesión como administrador.'], 401);
        }
        if (! $usuario->esAdministrador()) {
            return response()->json(['code' => 'forbidden', 'message' => 'No tienes permiso para esta acción.'], 403);
        }

        $request->setUserResolver(fn () => $usuario);

        return $next($request);
    }
}
