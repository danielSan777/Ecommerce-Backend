<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Aplicacion\CasosDeUso\ListarCategorias;
use App\Core\Catalogo\Aplicacion\CasosDeUso\ObtenerCategoria;
use App\Core\Catalogo\Presentacion\Resources\CategoriaResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ControladorCategorias extends Controller
{
    public function index(ListarCategorias $listar): JsonResponse
    {
        return response()->json(['data' => array_map(CategoriaResource::resumen(...), $listar())]);
    }

    public function show(Request $request, string $slug, ObtenerCategoria $obtener): JsonResponse
    {
        $subcategoria = $request->query('subcategory');

        return response()->json(CategoriaResource::formato($obtener($slug, is_string($subcategoria) ? $subcategoria : null)));
    }
}
