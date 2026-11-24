<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Repositorios;

use App\Core\Catalogo\Dominio\Entidades\Categoria;
use App\Core\Catalogo\Dominio\Entidades\Producto;
use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;

interface RepositorioCatalogo
{
    /** Categorías activas, en su orden, con sus subcategorías (sin productos). @return list<Categoria> */
    public function categorias(): array;

    /** @throws CatalogoNoEncontrado */
    /** `$limite` y `$desde` paginan los productos (sin `$limite` vienen todos); `Categoria::totalProductos` cuenta los de toda la lista. */
    public function categoria(string $slug, ?string $subcategoria = null, ?int $limite = null, int $desde = 0): Categoria;

    /** Productos activos, los más nuevos primero. @return list<Producto> */
    public function productos(?int $limite = null, string $tipo = 'personalizable'): array;

    /** @throws CatalogoNoEncontrado */
    public function producto(string $slug): Producto;
}
