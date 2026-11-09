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
        $activa = null;
        foreach ($c->subcategorias as $s) {
            if ($s->slug === $c->subcategoriaActiva) {
                $activa = $s->nombre;
            }
        }
        // Sin título para Google escrito en el panel: "Nombre | Jardín de Sele" (en una pestaña: "Salud · Profesiones y Hobbies | Jardín de Sele").
        $titulo = $c->seoTitulo ?? ($activa ? "{$activa} · {$c->nombre}" : $c->nombre).' | Jardín de Sele';

        return [
            'slug' => $c->slug,
            'name' => $c->nombre,
            'headline' => $c->titular,
            'intro_html' => $c->introHtml,
            'hero' => $c->portada ? MedioResource::formato($c->portada) : null,
            'hero_video' => $c->video ? MedioResource::formato($c->video) : null,
            'price_from_cents' => $c->precioDesdeCents,
            'subcategories' => array_map(fn (Subcategoria $s) => ['slug' => $s->slug, 'name' => $s->nombre, 'url' => "/{$c->slug}/{$s->slug}"], $c->subcategorias),
            'active_subcategory' => $c->subcategoriaActiva,
            'products' => array_map(ProductoResource::resumen(...), $c->productos),
            'seo' => SeoResource::formato($titulo, $c->seoDescripcion, $ruta, $c->productos[0] ?? null ? $c->productos[0]->imagenPrincipal() : null),
        ];
    }

    /** Foto de la portada en tamaño de tarjeta (600 px), o la más pequeña que exista. */
    private static function imagenPortada(Categoria $c): ?string
    {
        if ($c->portada === null || $c->portada->tipo !== 'image' || $c->portada->urls === []) {
            return null;
        }

        return $c->portada->urls[600] ?? $c->portada->urls[array_key_first($c->portada->urls)];
    }

    /** Resumen para el listado y el menú. @return array<string, mixed> */
    public static function resumen(Categoria $c): array
    {
        return [
            'slug' => $c->slug,
            'name' => $c->nombre,
            'url' => "/{$c->slug}",
            'short_description' => $c->titular,
            'image' => self::imagenPortada($c),
            'price_from_cents' => $c->precioDesdeCents,
            'subcategories' => array_map(fn (Subcategoria $s) => ['slug' => $s->slug, 'name' => $s->nombre, 'url' => "/{$c->slug}/{$s->slug}"], $c->subcategorias),
        ];
    }
}
