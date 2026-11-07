<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Aplicacion\CasosDeUso\AdministrarCategorias;
use App\Core\Catalogo\Aplicacion\CasosDeUso\EliminarCategoria;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ControladorAdministracionCategorias extends Controller
{
    private const MENSAJES = [
        'name.required' => 'Escribe el nombre de la categoría.',
        'name.max' => 'El nombre no puede pasar de 150 caracteres.',
        'slug.regex' => 'El identificador solo admite minúsculas, números y guiones (ejemplo: profesiones-y-hobbies).',
        'slug.unique' => 'Ya existe una categoría con ese identificador (puede ser una eliminada).',
        'sort_order.integer' => 'El orden debe ser un número.',
        'is_active.boolean' => 'Indica si la categoría está activa o no.',
        'seo_description.max' => 'La descripción para buscadores no puede pasar de 320 caracteres.',
    ];

    public function index(AdministrarCategorias $categorias): JsonResponse
    {
        return response()->json(['data' => $categorias->listar()]);
    }

    public function store(Request $request, AdministrarCategorias $categorias): JsonResponse
    {
        $datos = $request->all();
        // Sin identificador enviado se genera del nombre; se valida el resultado como cualquier otro.
        if (blank($datos['slug'] ?? null) && is_string($datos['name'] ?? null)) {
            $datos['slug'] = Str::slug($datos['name']);
        }

        $validos = ValidacionFallida::validar($datos, [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')],
            ...$this->reglasComunes(),
        ], self::MENSAJES);

        return response()->json($categorias->crear($validos), 201);
    }

    public function update(string $slug, Request $request, AdministrarCategorias $categorias): JsonResponse
    {
        // El identificador (slug) no se edita: cambiarlo rompería los enlaces ya publicados.
        $validos = ValidacionFallida::validar($request->except('slug'), [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            ...$this->reglasComunes(),
        ], self::MENSAJES);

        return response()->json($categorias->actualizar($slug, $validos));
    }

    public function destroy(string $slug, EliminarCategoria $eliminar): Response
    {
        $eliminar($slug);

        return response()->noContent();
    }

    /** @return array<string, list<mixed>> */
    private function reglasComunes(): array
    {
        return [
            'headline' => ['nullable', 'string', 'max:255'],
            'intro_html' => ['nullable', 'string', 'max:20000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:320'],
        ];
    }
}
