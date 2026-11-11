<?php

declare(strict_types=1);

namespace App\Core\Configurador\Dominio\Excepciones;

use RuntimeException;

/** La configuración rompe una regla del negocio (se responde 422 con los mensajes por campo). */
final class ConfiguracionInvalida extends RuntimeException
{
    /** @param array<string, list<string>> $errores */
    public function __construct(public readonly array $errores)
    {
        parent::__construct('Revisa los datos ingresados.');
    }
}
