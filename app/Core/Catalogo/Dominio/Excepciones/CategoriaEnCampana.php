<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Excepciones;

use RuntimeException;

/** Una categoría que es la relacionada de una campaña no se puede eliminar: la campaña dejaría de funcionar. */
final class CategoriaEnCampana extends RuntimeException
{
    /** @param list<array{slug: string, name: string}> $campanas */
    public function __construct(public readonly string $slug, public readonly array $campanas)
    {
        $nombres = implode(', ', array_column($campanas, 'name'));
        parent::__construct("No se puede eliminar la categoría '{$slug}' porque es la categoría relacionada de la campaña: {$nombres}. Cambia la categoría de esa campaña o elimínala primero.");
    }
}
