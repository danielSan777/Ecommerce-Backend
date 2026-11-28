<?php

declare(strict_types=1);

namespace App\Core\Configuracion\Presentacion\Controladores;

use App\Core\Seguimiento\Aplicacion\AvisoDeCookies;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Interruptor «Mostrar aviso de cookies»: la web lo lee al cargar (público) y el administrador lo cambia desde el panel. */
final class ControladorAvisoDeCookies extends Controller
{
    public function mostrar(AvisoDeCookies $aviso): JsonResponse
    {
        return response()->json(['show_cookie_notice' => $aviso->encendido()]);
    }

    public function guardar(Request $request, AvisoDeCookies $aviso): JsonResponse
    {
        $datos = ValidacionFallida::validar($request->all(), [
            'show_cookie_notice' => ['required', 'boolean'],
        ], [
            'show_cookie_notice.required' => 'Indica si se muestra el aviso de cookies.',
            'show_cookie_notice.boolean' => 'El aviso de cookies solo puede estar encendido o apagado.',
        ]);
        $aviso->cambiar((bool) $datos['show_cookie_notice']);

        return $this->mostrar($aviso);
    }
}
