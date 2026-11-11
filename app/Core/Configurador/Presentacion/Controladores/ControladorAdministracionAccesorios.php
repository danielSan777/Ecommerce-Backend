<?php

declare(strict_types=1);

namespace App\Core\Configurador\Presentacion\Controladores;

use App\Core\Catalogo\Dominio\Excepciones\ImagenInvalida;
use App\Core\Catalogo\Presentacion\ArchivoSubido;
use App\Core\Configurador\Aplicacion\CasosDeUso\AdministrarAccesorios;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class ControladorAdministracionAccesorios extends Controller
{
    private const MENSAJES = [
        'name.required' => 'Escribe el nombre.',
        'name.max' => 'El nombre no puede pasar de 150 caracteres.',
        'name.unique' => 'Ya existe una categoría de accesorios con ese nombre.',
        'sort_order.integer' => 'El orden debe ser un número.',
        'accessory_category_id.required' => 'Elige la categoría del accesorio.',
        'accessory_category_id.exists' => 'La categoría de accesorios elegida no existe.',
        'type.in' => 'El tipo debe ser imagen o texto.',
        'default_text.required_if' => 'Escribe el texto de ejemplo del accesorio de tipo texto.',
        'default_text.max' => 'El texto de ejemplo no puede pasar de 150 caracteres.',
        'is_active.boolean' => 'Indica si el accesorio está activo o no.',
        'image.required' => 'Elige la imagen del accesorio.',
        'alt.required' => 'Escribe el texto alternativo de la imagen (lo que se ve en ella).',
        'alt.max' => 'El texto alternativo no puede pasar de 200 caracteres.',
    ];

    public function categorias(AdministrarAccesorios $accesorios): JsonResponse
    {
        return response()->json(['data' => $accesorios->categorias()]);
    }

    public function crearCategoria(Request $request, AdministrarAccesorios $accesorios): JsonResponse
    {
        $validos = ValidacionFallida::validar($request->all(), [
            'name' => ['required', 'string', 'max:100', Rule::unique('accessory_categories', 'name')],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ], self::MENSAJES);

        return response()->json($accesorios->crearCategoria($validos), 201);
    }

    public function actualizarCategoria(int $id, Request $request, AdministrarAccesorios $accesorios): JsonResponse
    {
        $validos = ValidacionFallida::validar($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('accessory_categories', 'name')->ignore($id)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ], self::MENSAJES);

        return response()->json($accesorios->actualizarCategoria($id, $validos));
    }

    public function eliminarCategoria(int $id, AdministrarAccesorios $accesorios): Response
    {
        $accesorios->eliminarCategoria($id);

        return response()->noContent();
    }

    public function index(AdministrarAccesorios $accesorios): JsonResponse
    {
        return response()->json(['data' => $accesorios->listar()]);
    }

    public function store(Request $request, AdministrarAccesorios $accesorios): JsonResponse
    {
        $validos = ValidacionFallida::validar($request->all(), [
            'name' => ['required', 'string', 'max:150'],
            'accessory_category_id' => ['required', 'integer', Rule::exists('accessory_categories', 'id')],
            'type' => ['sometimes', Rule::in(['imagen', 'texto'])],
            'default_text' => ['required_if:type,texto', 'nullable', 'string', 'max:150'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ], self::MENSAJES);

        return response()->json($accesorios->crear($validos), 201);
    }

    public function update(int $id, Request $request, AdministrarAccesorios $accesorios): JsonResponse
    {
        $validos = ValidacionFallida::validar($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'accessory_category_id' => ['sometimes', 'required', 'integer', Rule::exists('accessory_categories', 'id')],
            'type' => ['sometimes', Rule::in(['imagen', 'texto'])],
            'default_text' => ['required_if:type,texto', 'nullable', 'string', 'max:150'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ], self::MENSAJES);

        return response()->json($accesorios->actualizar($id, $validos));
    }

    /** Imagen del accesorio (multipart, campo `image`). Reemplaza la anterior. */
    public function imagen(int $id, Request $request, AdministrarAccesorios $accesorios): JsonResponse
    {
        ArchivoSubido::verificar($request, ['image']);
        $validos = ValidacionFallida::validar($request->all(), [
            'image' => ['required', 'file'],
            'alt' => ['required', 'string', 'max:200'],
        ], self::MENSAJES);

        try {
            return response()->json($accesorios->subirImagen($id, (string) file_get_contents($request->file('image')->getRealPath()), $validos['alt']), 201);
        } catch (ImagenInvalida $e) {
            throw new ValidacionFallida([$e->campo => [$e->getMessage()]]);
        }
    }

    public function destroy(int $id, AdministrarAccesorios $accesorios): Response
    {
        $accesorios->eliminar($id);

        return response()->noContent();
    }
}
