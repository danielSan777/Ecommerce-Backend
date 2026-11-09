<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Aplicacion\CasosDeUso\ObtenerCampana;
use App\Core\Catalogo\Aplicacion\CasosDeUso\ObtenerCampanaVigente;
use App\Core\Catalogo\Presentacion\Resources\CampanaResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class ControladorCampanas extends Controller
{
    /** Siempre 200: `data` es la campaña en temporada o null si hoy no hay ninguna. */
    public function current(ObtenerCampanaVigente $vigente): JsonResponse
    {
        $campana = $vigente();

        return response()->json(['data' => $campana ? CampanaResource::resumen($campana) : null]);
    }

    public function show(string $slug, ObtenerCampana $obtener): JsonResponse
    {
        return response()->json(CampanaResource::formato($obtener($slug)));
    }
}
