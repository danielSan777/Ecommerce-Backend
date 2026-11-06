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
    public function categoria(string $slug, ?string $subcategoria = null): Categoria;

    /** @throws CatalogoNoEncontrado */
    public function producto(string $slug): Producto;
}
