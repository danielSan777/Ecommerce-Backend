<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Excepciones;

use RuntimeException;

/** Una categoría con productos no se puede eliminar: antes hay que mover o eliminar esos productos. */
final class CategoriaConProductos extends RuntimeException
{
    /** @param list<array{sku: string, slug: string, name: string}> $productos */
    public function __construct(public readonly string $slug, public readonly int $total, public readonly array $productos)
    {
        parent::__construct("No se puede eliminar la categoría '{$slug}' porque tiene {$total} producto(s). Muévelos a otra categoría o elimínalos primero.");
    }
}
