<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Repositorios;

use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;

/**
 * @phpstan-type CategoriaAdmin array{slug: string, name: string, headline: ?string, intro_html: ?string, sort_order: int, is_active: bool, seo_title: ?string, seo_description: ?string, products_count: int, subcategories_count: int, updated_at: string}
 */
interface RepositorioAdministracionCatalogo
{
    /**
     * Todas las categorías (activas o no) con sus conteos, para el panel.
     *
     * @return list<CategoriaAdmin>
     */
    public function listarCategorias(): array;

    /**
     * @param  array<string, mixed>  $datos
     * @return CategoriaAdmin
     */
    public function crearCategoria(array $datos): array;

    /**
     * @param  array<string, mixed>  $datos
     * @return CategoriaAdmin
     *
     * @throws CatalogoNoEncontrado
     */
    public function actualizarCategoria(string $slug, array $datos): array;

    /**
     * Productos (activos o no) que pertenecen a la categoría.
     *
     * @return list<array{sku: string, slug: string, name: string}>
     *
     * @throws CatalogoNoEncontrado
     */
    public function productosDeCategoria(string $slug): array;

    /**
     * Campañas que tienen esta categoría como relacionada.
     *
     * @return list<array{slug: string, name: string}>
     */
    public function campanasDeCategoria(string $slug): array;

    /** @throws CatalogoNoEncontrado */
    public function eliminarCategoria(string $slug): void;
}
