<?php

declare(strict_types=1);

namespace App\Core\Configurador\Presentacion\Controladores;

use App\Core\Configurador\Aplicacion\CasosDeUso\ObtenerConfiguradorDelProducto;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** API pública: lo que el personalizador necesita para mostrar y colocar los accesorios de un producto. */
final class ControladorConfigurador extends Controller
{
    public function show(string $slug, ObtenerConfiguradorDelProducto $configurador): JsonResponse
    {
        return response()->json($configurador($slug))->header('Cache-Control', 'public, max-age=60');
    }
}
