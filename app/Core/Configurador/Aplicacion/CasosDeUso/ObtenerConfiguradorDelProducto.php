<?php

declare(strict_types=1);

namespace App\Core\Configurador\Aplicacion\CasosDeUso;

use App\Core\Configurador\Dominio\Repositorios\RepositorioConfigurador;

/** Lo que necesita el personalizador del cliente: la configuración propia del producto o, si no la tiene, la de su categoría. */
final class ObtenerConfiguradorDelProducto
{
    public function __construct(private readonly RepositorioConfigurador $configurador) {}

    /** @return array<string, mixed> */
    public function __invoke(string $slugDelProducto): array
    {
        return $this->configurador->paraElCliente($slugDelProducto);
    }
}
