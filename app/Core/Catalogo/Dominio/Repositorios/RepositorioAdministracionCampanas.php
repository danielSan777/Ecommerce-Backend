<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Repositorios;

use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;

/**
 * @phpstan-type CampanaAdmin array{slug: string, name: string, related_category: array{slug: string, name: string}, start_md: string, end_md: string, event_md: string, tagline: ?string, slogan: ?string, is_active: bool, seo_title: ?string, seo_description: ?string, products: list<array{sku: string, slug: string, name: string}>, updated_at: string}
 */
interface RepositorioAdministracionCampanas
{
    /**
     * Todas las campañas (activas o no), para el panel.
     *
     * @return list<CampanaAdmin>
     */
    public function listar(): array;

    /**
     * @return CampanaAdmin
     *
     * @throws CatalogoNoEncontrado
     */
    public function obtener(string $slug): array;

    /**
     * `related_category` es el slug de la categoría y `products` la lista de SKU en el orden elegido.
     *
     * @param  array<string, mixed>  $datos
     * @return CampanaAdmin
     */
    public function crear(array $datos): array;

    /**
     * @param  array<string, mixed>  $datos
     * @return CampanaAdmin
     *
     * @throws CatalogoNoEncontrado
     */
    public function actualizar(string $slug, array $datos): array;

    /** @throws CatalogoNoEncontrado */
    public function eliminar(string $slug): void;
}
