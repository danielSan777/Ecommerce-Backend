<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Aplicacion\CasosDeUso\ObtenerProducto;
use App\Core\Catalogo\Presentacion\Resources\ProductoResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class ControladorProductos extends Controller
{
    public function show(string $slug, ObtenerProducto $obtener): JsonResponse
    {
        return response()->json(ProductoResource::formato($obtener($slug)));
    }
}
