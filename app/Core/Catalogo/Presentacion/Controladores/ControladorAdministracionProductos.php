<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Aplicacion\CasosDeUso\AdministrarProductos;
use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\SubcategoriaModelo;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ControladorAdministracionProductos extends Controller
{
    private const MENSAJES = [
        'name.required' => 'Escribe el nombre del producto.',
        'name.max' => 'El nombre no puede pasar de 150 caracteres.',
        'slug.required' => 'El nombre debe tener al menos una letra o un número.',
        'slug.unique' => 'Ya existe un producto con un nombre igual o muy parecido (puede ser uno eliminado).',
        'sku.regex' => 'El código (SKU) solo admite mayúsculas, números y guiones (ejemplo: JDS-DOCTOR-01).',
        'sku.unique' => 'Ya existe un producto con ese código (SKU). Los códigos no se reutilizan, ni siquiera de productos eliminados.',
        'type.in' => 'El tipo debe ser "personalizable" o "complemento".',
        'category.required' => 'Elige la categoría.',
        'category.exists' => 'La categoría elegida no existe.',
        'subcategory.exists' => 'La subcategoría elegida no existe.',
        'base_price_cents.required' => 'Escribe el precio.',
        'base_price_cents.integer' => 'El precio no es válido.',
        'base_price_cents.min' => 'El precio no puede ser negativo.',
        'base_price_cents.max' => 'El precio es demasiado alto.',
        'cost_cents.integer' => 'El costo no es válido.',
        'includes.max' => 'Puedes poner hasta 20 elementos en "Incluye".',
        'includes.*.max' => 'Cada elemento de "Incluye" puede tener hasta 150 caracteres.',
        'is_available.boolean' => 'Indica si el producto está disponible o no.',
        'is_active.boolean' => 'Indica si el producto está activo o no.',
    ];

    public function index(Request $request, AdministrarProductos $productos): JsonResponse
    {
        return response()->json(['data' => $productos->listar($request->query('category') ?: null, $request->query('q') ?: null)]);
    }

    public function show(string $sku, AdministrarProductos $productos): JsonResponse
    {
        return response()->json($productos->ver($sku));
    }

    public function store(Request $request, AdministrarProductos $productos): JsonResponse
    {
        // La dirección (slug) siempre se genera del nombre, con guiones, y el SKU no se puede cambiar después.
        $datos = $request->except('slug');
        if (is_string($datos['name'] ?? null)) {
            $datos['slug'] = Str::slug($datos['name']);
        }

        $validos = ValidacionFallida::validar($datos, [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['sometimes', 'required', 'string', 'max:150', Rule::unique('products', 'slug')],
            'sku' => ['nullable', 'string', 'max:60', 'regex:/^[A-Z0-9][A-Z0-9-]*$/', Rule::unique('products', 'sku')],
            ...$this->reglasComunes($datos['category'] ?? null),
            'category' => ['required', 'string', Rule::exists('categories', 'slug')->whereNull('deleted_at')],
        ], self::MENSAJES);

        return response()->json($productos->crear(array_filter($validos, fn ($v, $k) => $k !== 'sku' || $v !== null, ARRAY_FILTER_USE_BOTH)), 201);
    }

    public function update(string $sku, Request $request, AdministrarProductos $productos): JsonResponse
    {
        $datos = $request->except(['slug', 'sku']);

        $reglas = $this->reglasComunes($datos['category'] ?? $productos->ver($sku)['category_slug']);
        $reglas['name'] = ['sometimes', 'required', 'string', 'max:150'];
        $reglas['base_price_cents'] = ['sometimes', ...$reglas['base_price_cents']];
        $reglas['category'] = ['sometimes', 'required', 'string', Rule::exists('categories', 'slug')->whereNull('deleted_at')];

        $validos = ValidacionFallida::validar($datos, $reglas, self::MENSAJES);

        return response()->json($productos->actualizar($sku, $validos));
    }

    public function destroy(string $sku, AdministrarProductos $productos): Response
    {
        $productos->eliminar($sku);

        return response()->noContent();
    }

    /** @return array<string, list<mixed>> */
    private function reglasComunes(mixed $categoria): array
    {
        $categoriaId = is_string($categoria) ? CategoriaModelo::query()->where('slug', $categoria)->value('id') : null;

        return [
            'name' => ['required', 'string', 'max:150'],
            'type' => ['sometimes', Rule::in(['personalizable', 'complemento'])],
            // La subcategoría debe pertenecer a la categoría elegida
            'subcategory' => ['nullable', 'string', Rule::exists(SubcategoriaModelo::class, 'slug')->where('category_id', $categoriaId ?? 0)],
            'base_price_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
            'cost_cents' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'size_text' => ['nullable', 'string', 'max:255'],
            'material_text' => ['nullable', 'string', 'max:255'],
            'includes' => ['nullable', 'array', 'max:20'],
            'includes.*' => ['string', 'max:150'],
            'description_html' => ['nullable', 'string', 'max:20000'],
            'is_available' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'search_synonyms' => ['nullable', 'array', 'max:30'],
            'search_synonyms.*' => ['string', 'max:60'],
        ];
    }
}
