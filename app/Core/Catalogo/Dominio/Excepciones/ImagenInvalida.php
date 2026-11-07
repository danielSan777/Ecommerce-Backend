<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Excepciones;

use RuntimeException;

/** El archivo subido no es una imagen que podamos aceptar (se responde 422 con el motivo en español). */
final class ImagenInvalida extends RuntimeException
{
    /** @param string $campo campo del formulario al que pertenece el error (image, role) */
    public function __construct(string $mensaje, public readonly string $campo = 'image')
    {
        parent::__construct($mensaje);
    }
}
