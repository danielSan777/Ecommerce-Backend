<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Resources;

use App\Core\Catalogo\Dominio\Entidades\Categoria;
use App\Core\Catalogo\Dominio\Entidades\Subcategoria;

/** Forma del contrato "Categoría con subcategorías" (Paso 0). */
final class CategoriaResource
{
    /** @return array<string, mixed> */
    public static function formato(Categoria $c): array
    {
        $ruta = "/{$c->slug}".($c->subcategoriaActiva ? "/{$c->subcategoriaActiva}" : '');

        return [
            'slug' => $c->slug,
            'name' => $c->nombre,
            'headline' => $c->titular,
            'intro_html' => $c->introHtml,
            'hero' => $c->portada ? MedioResource::formato($c->portada) : null,
            'price_from_cents' => $c->precioDesdeCents,
            'subcategories' => array_map(fn (Subcategoria $s) => ['slug' => $s->slug, 'name' => $s->nombre, 'url' => "/{$c->slug}/{$s->slug}"], $c->subcategorias),
            'active_subcategory' => $c->subcategoriaActiva,
            'products' => array_map(ProductoResource::resumen(...), $c->productos),
            'seo' => SeoResource::formato($c->seoTitulo, $c->seoDescripcion, $ruta, $c->productos[0] ?? null ? $c->productos[0]->imagenPrincipal() : null),
        ];
    }

    /** Resumen para el listado y el menú. @return array<string, mixed> */
    public static function resumen(Categoria $c): array
    {
        return [
            'slug' => $c->slug,
            'name' => $c->nombre,
            'url' => "/{$c->slug}",
            'price_from_cents' => $c->precioDesdeCents,
            'subcategories' => array_map(fn (Subcategoria $s) => ['slug' => $s->slug, 'name' => $s->nombre, 'url' => "/{$c->slug}/{$s->slug}"], $c->subcategorias),
        ];
    }
}
