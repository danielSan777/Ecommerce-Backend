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
        'type.in' => 'El tipo debe ser "personalizable" o "complemento".',
        'category.required' => 'Elige la categoría.',
        'category.exists' => 'La categoría elegida no existe.',
        'subcategory.required' => 'Elige la subcategoría.',
        'subcategory.required_with' => 'Elige la subcategoría.',
        'subcategory.exists' => 'La subcategoría elegida no existe en esa categoría.',
        'discount_percent.numeric' => 'El descuento debe ser un número, por ejemplo 10 o 12.5.',
        'discount_percent.min' => 'El descuento no puede ser negativo.',
        'discount_percent.max' => 'El descuento no puede ser mayor a :max%.',
        'discount_percent.decimal' => 'El descuento admite hasta dos decimales, por ejemplo 12.5.',
        'base_price_cents.required' => 'Escribe el precio.',
        'base_price_cents.integer' => 'El precio no es válido.',
        'base_price_cents.min' => 'El precio no puede ser negativo.',
        'base_price_cents.max' => 'El precio es demasiado alto.',
        'cost_cents.integer' => 'El costo no es válido.',
        'seo_title.max' => 'El título para Google no puede pasar de 255 caracteres.',
        'seo_description.max' => 'La descripción para Google no puede pasar de 320 caracteres.',
        'includes.max' => 'Puedes poner hasta 20 elementos en "Incluye".',
        'includes.*.max' => 'Cada elemento de "Incluye" puede tener hasta 150 caracteres.',
        'is_available.boolean' => 'Indica si el producto está disponible o no.',
        'is_active.boolean' => 'Indica si el producto está activo o no.',
    ];

    public function index(Request $request, AdministrarProductos $productos): JsonResponse
    {
        return response()->json(['data' => $productos->listar($request->query('category') ?: null, $request->query('q') ?: null)]);
    }

    /** Código que se asignaría al próximo producto (solo para mostrarlo; el definitivo lo asigna Laravel al guardar). */
    public function siguienteSku(AdministrarProductos $productos): JsonResponse
    {
        return response()->json(['sku' => $productos->nuevoSku()]);
    }

    /** Cuánto sería el descuento en dinero y el precio final. Laravel calcula; Angular solo lo muestra. */
    public function previsualizarPrecio(Request $request, AdministrarProductos $productos): JsonResponse
    {
        $validos = ValidacionFallida::validar($request->query(), [
            'base_price_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:'.config('catalogo.descuento_maximo'), 'decimal:0,2'],
        ], self::MENSAJES);

        return response()->json($productos->previsualizarPrecio((int) $validos['base_price_cents'], (float) ($validos['discount_percent'] ?? 0)));
    }

    public function show(string $sku, AdministrarProductos $productos): JsonResponse
    {
        return response()->json($productos->ver($sku));
    }

    public function store(Request $request, AdministrarProductos $productos): JsonResponse
    {
        // La dirección (slug) se genera del nombre, con guiones, y el código (SKU) lo asigna Laravel: ninguno de los dos se escribe a mano.
        $datos = $request->except(['slug', 'sku']);
        if (is_string($datos['name'] ?? null)) {
            $datos['slug'] = Str::slug($datos['name']);
        }

        $validos = ValidacionFallida::validar($datos, [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['sometimes', 'required', 'string', 'max:150', Rule::unique('products', 'slug')],
            ...$this->reglasComunes(),
            'category' => ['required', 'string', Rule::exists('categories', 'slug')->whereNull('deleted_at')],
            'subcategory' => ['required', 'string', Rule::exists(SubcategoriaModelo::class, 'slug')->where('category_id', $this->idCategoria($datos['category'] ?? null))],
        ], self::MENSAJES);

        return response()->json($productos->crear($this->sinNulos($validos)), 201);
    }

    public function update(string $sku, Request $request, AdministrarProductos $productos): JsonResponse
    {
        $datos = $request->except(['slug', 'sku']);

        $reglas = $this->reglasComunes();
        $reglas['name'] = ['sometimes', 'required', 'string', 'max:150'];
        $reglas['base_price_cents'] = ['sometimes', ...$reglas['base_price_cents']];
        $reglas['category'] = ['sometimes', 'required', 'string', Rule::exists('categories', 'slug')->whereNull('deleted_at')];

        // Si cambia de categoría tiene que elegir también una subcategoría de la nueva
        $reglas['subcategory'] = ['required_with:category', 'string', Rule::exists(SubcategoriaModelo::class, 'slug')->where('category_id', $this->idCategoria($datos['category'] ?? $productos->ver($sku)['category_slug']))];

        $validos = ValidacionFallida::validar($datos, $reglas, self::MENSAJES);

        return response()->json($productos->actualizar($sku, $this->sinNulos($validos)));
    }

    public function destroy(string $sku, AdministrarProductos $productos): Response
    {
        $productos->eliminar($sku);

        return response()->noContent();
    }

    private function idCategoria(mixed $slug): int
    {
        return is_string($slug) ? (int) CategoriaModelo::query()->where('slug', $slug)->value('id') : 0;
    }

    /**
     * Normaliza lo recibido: descuento vacío = sin descuento (la columna no admite nulo) y textos de Google sin etiquetas.
     *
     * @param  array<string, mixed>  $validos
     * @return array<string, mixed>
     */
    private function sinNulos(array $validos): array
    {
        if (array_key_exists('discount_percent', $validos) && $validos['discount_percent'] === null) {
            $validos['discount_percent'] = 0;
        }

        // Los textos de Google son texto simple: sin etiquetas HTML y, si quedan vacíos, sin valor.
        foreach (['seo_title', 'seo_description'] as $campo) {
            if (isset($validos[$campo]) && is_string($validos[$campo])) {
                $limpio = trim(strip_tags($validos[$campo]));
                $validos[$campo] = $limpio === '' ? null : $limpio;
            }
        }

        return $validos;
    }

    /** @return array<string, list<mixed>> */
    private function reglasComunes(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'type' => ['sometimes', Rule::in(['personalizable', 'complemento'])],
            'base_price_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
            'cost_cents' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:'.config('catalogo.descuento_maximo'), 'decimal:0,2'],
            'size_text' => ['nullable', 'string', 'max:255'],
            'material_text' => ['nullable', 'string', 'max:255'],
            'includes' => ['nullable', 'array', 'max:20'],
            'includes.*' => ['string', 'max:150'],
            'description_html' => ['nullable', 'string', 'max:20000'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'is_available' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'search_synonyms' => ['nullable', 'array', 'max:30'],
            'search_synonyms.*' => ['string', 'max:60'],
        ];
    }
}
