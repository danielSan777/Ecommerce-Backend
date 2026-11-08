<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Aplicacion\CasosDeUso\ListarProductos;
use App\Core\Catalogo\Aplicacion\CasosDeUso\ObtenerProducto;
use App\Core\Catalogo\Presentacion\Resources\ProductoResource;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ControladorProductos extends Controller
{
    /** Productos activos, los más nuevos primero. `?limit=6` limita la cantidad (de 1 a 100; sin límite, hasta 100). */
    public function index(Request $request, ListarProductos $listar): JsonResponse
    {
        $validos = ValidacionFallida::validar($request->query(), ['limit' => ['sometimes', 'integer', 'min:1', 'max:100']], [
            'limit.integer' => 'El límite debe ser un número.',
            'limit.min' => 'El límite mínimo es 1.',
            'limit.max' => 'El límite máximo es 100.',
        ]);

        return response()->json(['data' => array_map(ProductoResource::listado(...), $listar((int) ($validos['limit'] ?? 100)))]);
    }

    public function show(string $slug, ObtenerProducto $obtener): JsonResponse
    {
        return response()->json(ProductoResource::formato($obtener($slug)));
    }
}
