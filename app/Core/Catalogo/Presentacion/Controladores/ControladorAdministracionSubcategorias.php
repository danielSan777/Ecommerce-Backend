<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Aplicacion\CasosDeUso\AdministrarSubcategorias;
use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;
use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ControladorAdministracionSubcategorias extends Controller
{
    private const MENSAJES = [
        'name.required' => 'Escribe el nombre de la subcategoría.',
        'name.max' => 'El nombre no puede pasar de 150 caracteres.',
        'slug.required' => 'El nombre debe tener al menos una letra o un número.',
        'slug.unique' => 'Ya existe una subcategoría con un nombre igual o muy parecido en esta categoría.',
        'sort_order.integer' => 'El orden debe ser un número.',
        'is_active.boolean' => 'Indica si la subcategoría está activa o no.',
    ];

    public function index(string $categoria, AdministrarSubcategorias $subcategorias): JsonResponse
    {
        return response()->json(['data' => $subcategorias->listar($categoria)]);
    }

    public function store(string $categoria, Request $request, AdministrarSubcategorias $subcategorias): JsonResponse
    {
        $id = CategoriaModelo::query()->where('slug', $categoria)->value('id') ?? throw CatalogoNoEncontrado::categoria($categoria);

        // La dirección (slug) siempre se genera del nombre, con guiones, y no se puede elegir ni cambiar.
        $datos = $request->except('slug');
        if (is_string($datos['name'] ?? null)) {
            $datos['slug'] = Str::slug($datos['name']);
        }

        $validos = ValidacionFallida::validar($datos, [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('subcategories', 'slug')->where('category_id', $id)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ], self::MENSAJES);

        return response()->json($subcategorias->crear($categoria, $validos), 201);
    }

    public function update(string $categoria, string $slug, Request $request, AdministrarSubcategorias $subcategorias): JsonResponse
    {
        $validos = ValidacionFallida::validar($request->except('slug'), [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ], self::MENSAJES);

        return response()->json($subcategorias->actualizar($categoria, $slug, $validos));
    }

    public function destroy(string $categoria, string $slug, AdministrarSubcategorias $subcategorias): Response
    {
        $subcategorias->eliminar($categoria, $slug);

        return response()->noContent();
    }
}
