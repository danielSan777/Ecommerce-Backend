<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Aplicacion\CasosDeUso\AdministrarPortadaCategorias;
use App\Core\Catalogo\Aplicacion\CasosDeUso\AdministrarPortadaInicio;
use App\Core\Catalogo\Aplicacion\CasosDeUso\AdministrarTextoCategorias;
use App\Core\Catalogo\Presentacion\Resources\MedioResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Portada de la página principal (pública). Siempre 200. `items` es la lista, en orden, de imágenes y videos (vacía si no hay y
 * la tienda usa su imagen de siempre); `data` es el primero (o null), por compatibilidad.
 */
final class ControladorInicio extends Controller
{
    public function hero(AdministrarPortadaInicio $portada): JsonResponse
    {
        $items = array_map(fn (array $i) => MedioResource::formato($i['medio']), $portada->lista());

        return response()->json(['data' => $items[0] ?? null, 'items' => $items]);
    }

    /** Encabezado de la página /categorias: `title` y `subtitle` (null = textos de siempre) e `items` (imágenes y videos; vacío = las fotos de las categorías). */
    public function categorias(AdministrarPortadaCategorias $portada, AdministrarTextoCategorias $texto): JsonResponse
    {
        $items = array_map(fn (array $i) => MedioResource::formato($i['medio']), $portada->lista());

        return response()->json([...$texto->obtener(), 'items' => $items]);
    }
}
