<?php

declare(strict_types=1);

namespace App\Shared\Infraestructura\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Exige una sesión válida (cliente o administrador). Falla cerrado: sin token válido responde 401. */
final class RequiereSesion
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = Auth::guard('api')->user();

        if ($usuario === null) {
            return response()->json(['code' => 'unauthenticated', 'message' => 'Debes iniciar sesión.'], 401);
        }

        $request->setUserResolver(fn () => $usuario);

        return $next($request);
    }
}
