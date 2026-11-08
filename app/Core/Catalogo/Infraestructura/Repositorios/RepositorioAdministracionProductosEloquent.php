<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Infraestructura\Repositorios;

use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;
use App\Core\Catalogo\Dominio\Repositorios\RepositorioAdministracionProductos;
use App\Core\Catalogo\Dominio\ValueObjects\Precio;
use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use App\Core\Catalogo\Infraestructura\Modelos\SubcategoriaModelo;

final class RepositorioAdministracionProductosEloquent implements RepositorioAdministracionProductos
{
    public function listar(?string $categoria, ?string $texto): array
    {
        return ProductoModelo::query()->with(['categoria', 'subcategoria'])
            ->when($categoria, fn ($q) => $q->whereHas('categoria', fn ($c) => $c->where('slug', $categoria)))
            ->when($texto, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($texto, '%_\\').'%')->orWhere('sku', 'like', '%'.addcslashes($texto, '%_\\').'%')))
            ->orderBy('name')->get()->map(fn (ProductoModelo $p) => $this->formato($p))->all();
    }

    public function obtener(string $sku): array
    {
        return $this->formato($this->producto($sku));
    }

    public function crear(array $datos): array
    {
        $producto = ProductoModelo::query()->create($this->columnas($datos))->refresh();

        return $this->formato($producto->load(['categoria', 'subcategoria']));
    }

    public function actualizar(string $sku, array $datos): array
    {
        $producto = $this->producto($sku);
        $producto->update($this->columnas($datos));

        return $this->formato($producto->refresh()->load(['categoria', 'subcategoria']));
    }

    public function eliminar(string $sku): void
    {
        $this->producto($sku)->delete();
    }

    public function existeSku(string $sku): bool
    {
        return ProductoModelo::withTrashed()->where('sku', $sku)->exists();
    }

    public function skusConPrefijo(string $prefijo): array
    {
        return ProductoModelo::withTrashed()->where('sku', 'like', addcslashes($prefijo, '%_\\').'%')->pluck('sku')->all();
    }

    private function producto(string $sku): ProductoModelo
    {
        return ProductoModelo::query()->with(['categoria', 'subcategoria'])->where('sku', $sku)->first() ?? throw CatalogoNoEncontrado::producto($sku);
    }

    /**
     * Convierte los slugs `category` y `subcategory` en las llaves foráneas.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function columnas(array $datos): array
    {
        if (array_key_exists('category', $datos)) {
            $categoria = CategoriaModelo::query()->where('slug', $datos['category'])->first() ?? throw CatalogoNoEncontrado::categoria((string) $datos['category']);
            $datos['category_id'] = $categoria->id;

            if (array_key_exists('subcategory', $datos)) {
                $datos['subcategory_id'] = $datos['subcategory'] === null ? null
                    : (SubcategoriaModelo::query()->where('category_id', $categoria->id)->where('slug', $datos['subcategory'])->first()
                        ?? throw CatalogoNoEncontrado::subcategoria((string) $datos['subcategory']))->id;
            } else {
                // Cambió de categoría sin indicar subcategoría: la anterior ya no corresponde
                $datos['subcategory_id'] = null;
            }
        }

        unset($datos['category'], $datos['subcategory']);

        return $datos;
    }

    /** @return array<string, mixed> */
    private function formato(ProductoModelo $p): array
    {
        $precio = Precio::conDescuentoPorcentaje((int) $p->base_price_cents, $p->discount_percent ?? 0);

        return [
            'sku' => $p->sku,
            'slug' => $p->slug,
            'name' => $p->name,
            'type' => $p->type,
            'category_slug' => $p->categoria->slug,
            'category_name' => $p->categoria->name,
            'subcategory_slug' => $p->subcategoria?->slug,
            'base_price_cents' => (int) $p->base_price_cents,
            'cost_cents' => $p->cost_cents === null ? null : (int) $p->cost_cents,
            'discount_percent' => (float) $p->discount_percent,
            'discount_cents' => $precio->descuentoCents(),
            'final_price_cents' => $precio->finalCents,
            'size_text' => $p->size_text,
            'material_text' => $p->material_text,
            'includes' => $p->includes ?? [],
            'description_html' => $p->description_html,
            'seo_title' => $p->seo_title,
            'seo_description' => $p->seo_description,
            'is_available' => (bool) $p->is_available,
            'is_active' => (bool) $p->is_active,
            'search_synonyms' => $p->search_synonyms ?? [],
            'updated_at' => $p->updated_at->toIso8601String(),
        ];
    }
}
