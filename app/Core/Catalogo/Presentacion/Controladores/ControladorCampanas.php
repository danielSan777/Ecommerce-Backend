<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Aplicacion\CasosDeUso\ObtenerCampana;
use App\Core\Catalogo\Presentacion\Resources\CampanaResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class ControladorCampanas extends Controller
{
    public function show(string $slug, ObtenerCampana $obtener): JsonResponse
    {
        return response()->json(CampanaResource::formato($obtener($slug)));
    }
}
