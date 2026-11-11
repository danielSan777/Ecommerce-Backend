<?php

declare(strict_types=1);

namespace App\Core\Configurador\Dominio\Excepciones;

use RuntimeException;

final class ConfiguradorNoEncontrado extends RuntimeException
{
    public static function accesorio(int $id): self
    {
        return new self("El accesorio '{$id}' no existe.");
    }

    public static function categoriaDeAccesorio(int $id): self
    {
        return new self("La categoría de accesorios '{$id}' no existe.");
    }

    public static function ubicacion(int $id): self
    {
        return new self("La ubicación '{$id}' no existe.");
    }
}
