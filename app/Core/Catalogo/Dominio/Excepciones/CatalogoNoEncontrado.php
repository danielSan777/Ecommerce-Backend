<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Excepciones;

use RuntimeException;

final class CatalogoNoEncontrado extends RuntimeException
{
    public static function categoria(string $slug): self
    {
        return new self("La categoría '{$slug}' no existe.");
    }

    public static function subcategoria(string $slug): self
    {
        return new self("La subcategoría '{$slug}' no existe.");
    }

    public static function campana(string $slug): self
    {
        return new self("La campaña '{$slug}' no existe.");
    }

    public static function producto(string $slug): self
    {
        return new self("El producto '{$slug}' no existe.");
    }
}
