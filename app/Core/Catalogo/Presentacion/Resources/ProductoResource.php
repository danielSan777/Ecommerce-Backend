<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Resources;

use App\Core\Catalogo\Dominio\Entidades\Producto;

/** Forma del contrato "Producto" (Paso 0). El costo interno nunca sale. */
final class ProductoResource
{
    /** @return array<string, mixed> */
    public static function formato(Producto $p): array
    {
        return [
            'sku' => $p->sku,
            'slug' => $p->slug,
            'type' => $p->tipo,
            'name' => $p->nombre,
            'category' => ['slug' => $p->categoriaSlug, 'name' => $p->categoriaNombre],
            'subcategory' => $p->subcategoria ? ['slug' => $p->subcategoria->slug, 'name' => $p->subcategoria->nombre] : null,
            'available' => $p->disponible,
            'price' => PrecioResource::formato($p->precio),
            'size_text' => $p->tamano,
            'material_text' => $p->material,
            'includes' => $p->incluye,
            'description_html' => $p->descripcionHtml,
            'media' => array_map(MedioResource::formato(...), $p->medios),
            'related_skus' => $p->skusRelacionados,
            'configurator_url' => "/api/v1/products/{$p->slug}/configurator",
            'seo' => SeoResource::formato($p->seoTitulo, $p->seoDescripcion, "/producto/{$p->slug}", $p->imagenPrincipal()),
        ];
    }

    /**
     * Listado general de productos (todos, favoritos, búsqueda): el resumen más categoría, subcategoría y palabras de búsqueda.
     *
     * @return array<string, mixed>
     */
    public static function listado(Producto $p): array
    {
        return [
            ...self::resumen($p),
            'category' => ['slug' => $p->categoriaSlug, 'name' => $p->categoriaNombre],
            'subcategory' => $p->subcategoria ? ['slug' => $p->subcategoria->slug, 'name' => $p->subcategoria->nombre] : null,
            'search_terms' => $p->sinonimos,
        ];
    }

    /** Resumen de producto para listados (contrato de categoría). @return array<string, mixed> */
    public static function resumen(Producto $p): array
    {
        return [
            'sku' => $p->sku,
            'slug' => $p->slug,
            'name' => $p->nombre,
            'image' => $p->imagenPrincipal(),
            'price' => PrecioResource::formato($p->precio),
            'available' => $p->disponible,
        ];
    }
}
