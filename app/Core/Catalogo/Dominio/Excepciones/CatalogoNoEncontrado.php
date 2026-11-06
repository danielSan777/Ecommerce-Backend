<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Excepciones;

use RuntimeException;

final class CatalogoNoEncontrado extends RuntimeException
{
    public static function categoria(string $slug): self
    {
        return new self("Category '{$slug}' not found.");
    }

    public static function subcategoria(string $slug): self
    {
        return new self("Subcategory '{$slug}' not found.");
    }

    public static function campana(string $slug): self
    {
        return new self("Campaign '{$slug}' not found.");
    }

    public static function producto(string $slug): self
    {
        return new self("Product '{$slug}' not found.");
    }
}
