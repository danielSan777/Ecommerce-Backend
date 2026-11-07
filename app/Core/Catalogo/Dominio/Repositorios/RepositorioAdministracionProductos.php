<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Repositorios;

use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;

/**
 * @phpstan-type ProductoAdmin array<string, mixed>
 */
interface RepositorioAdministracionProductos
{
    /**
     * Productos (activos o no, sin los eliminados) para el panel; se pueden filtrar por categoría y por texto.
     *
     * @return list<ProductoAdmin>
     */
    public function listar(?string $categoria, ?string $texto): array;

    /**
     * @return ProductoAdmin
     *
     * @throws CatalogoNoEncontrado
     */
    public function obtener(string $sku): array;

    /**
     * @param  array<string, mixed>  $datos  con `category` y `subcategory` como slugs
     * @return ProductoAdmin
     */
    public function crear(array $datos): array;

    /**
     * @param  array<string, mixed>  $datos
     * @return ProductoAdmin
     *
     * @throws CatalogoNoEncontrado
     */
    public function actualizar(string $sku, array $datos): array;

    /** Borrado lógico: el producto desaparece de la tienda pero no de los pedidos antiguos. */
    public function eliminar(string $sku): void;

    public function existeSku(string $sku): bool;
}
