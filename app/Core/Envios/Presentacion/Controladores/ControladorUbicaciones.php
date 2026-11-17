<?php

declare(strict_types=1);

namespace App\Core\Envios\Presentacion\Controladores;

use App\Core\Envios\Aplicacion\CasosDeUso\ConsultarUbicaciones;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class ControladorUbicaciones extends Controller
{
    public function __invoke(ConsultarUbicaciones $consultar): JsonResponse
    {
        return response()->json($consultar());
    }
}
