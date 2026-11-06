<?php

declare(strict_types=1);

namespace App\Core\Configuracion\Dominio\Repositorios;

/** Parámetros del negocio editables desde el panel (tabla `settings`). */
interface RepositorioConfiguracion
{
    public function obtener(string $clave, mixed $porDefecto = null): mixed;

    public function guardar(string $clave, mixed $valor): void;

    /** @return array<string, mixed> */
    public function todos(): array;
}
