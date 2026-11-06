<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Repositorios;

use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;

interface RepositorioAdministracionCatalogo
{
    /**
     * Productos (activos o no) que pertenecen a la categoría.
     *
     * @return list<array{sku: string, slug: string, name: string}>
     *
     * @throws CatalogoNoEncontrado
     */
    public function productosDeCategoria(string $slug): array;

    /** @throws CatalogoNoEncontrado */
    public function eliminarCategoria(string $slug): void;
}
