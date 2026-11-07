<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Repositorios;

use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;

/**
 * @phpstan-type SubcategoriaAdmin array{slug: string, name: string, category_slug: string, sort_order: int, is_active: bool, products_count: int}
 */
interface RepositorioAdministracionSubcategorias
{
    /**
     * @return list<SubcategoriaAdmin>
     *
     * @throws CatalogoNoEncontrado si la categoría no existe
     */
    public function listar(string $categoria): array;

    /**
     * @param  array<string, mixed>  $datos
     * @return SubcategoriaAdmin
     */
    public function crear(string $categoria, array $datos): array;

    /**
     * @param  array<string, mixed>  $datos
     * @return SubcategoriaAdmin
     */
    public function actualizar(string $categoria, string $slug, array $datos): array;

    /** @return list<array{sku: string, slug: string, name: string}> */
    public function productos(string $categoria, string $slug): array;

    public function eliminar(string $categoria, string $slug): void;
}
