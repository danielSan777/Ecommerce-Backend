<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Aplicacion\CasosDeUso\AdministrarPortadaInicio;
use App\Core\Catalogo\Presentacion\Resources\MedioResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Portada de la página principal (pública). Siempre 200: `data` es null si no hay portada y la tienda usa su imagen de siempre. */
final class ControladorInicio extends Controller
{
    public function hero(AdministrarPortadaInicio $portada): JsonResponse
    {
        $m = $portada->obtener();

        return response()->json(['data' => $m ? MedioResource::formato($m) : null]);
    }
}
