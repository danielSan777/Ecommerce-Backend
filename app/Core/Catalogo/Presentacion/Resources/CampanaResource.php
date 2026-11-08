<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Resources;

use App\Core\Catalogo\Dominio\Entidades\Campana;

/** Forma del contrato "Campaña de temporada" (Paso 0). */
final class CampanaResource
{
    /** @return array<string, mixed> */
    public static function formato(Campana $c): array
    {
        return [
            'slug' => $c->slug,
            'name' => $c->nombre,
            'state' => $c->estado,
            'season_year' => $c->anioTemporada,
            'event_date' => $c->fechaEvento,
            // La fecha límite depende del calendario operativo y la capacidad (Paso 9); hasta entonces no se informa.
            'order_deadline' => null,
            'tagline' => $c->lema,
            'slogan' => $c->eslogan,
            'related_category' => ['slug' => $c->categoriaRelacionadaSlug, 'name' => $c->categoriaRelacionadaNombre, 'url' => "/{$c->categoriaRelacionadaSlug}"],
            'products' => array_map(ProductoResource::resumen(...), $c->productos),
            'seo' => SeoResource::formato($c->seoTitulo ?? $c->nombre.' | Jardín de Sele', $c->seoDescripcion ?? $c->lema, "/{$c->slug}", $c->productos[0] ?? null ? $c->productos[0]->imagenPrincipal() : null),
        ];
    }
}
