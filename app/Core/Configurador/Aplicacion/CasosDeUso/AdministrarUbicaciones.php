<?php

declare(strict_types=1);

namespace App\Core\Configurador\Aplicacion\CasosDeUso;

use App\Core\Configurador\Dominio\Repositorios\RepositorioConfigurador;

/** Las ubicaciones (slots) de la maceta y las categorías de accesorios que admite cada una. */
final class AdministrarUbicaciones
{
    public function __construct(private readonly RepositorioConfigurador $configurador) {}

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return $this->configurador->ubicaciones();
    }

    /** @param array<string, mixed> $datos @return array<string, mixed> */
    public function crear(array $datos): array
    {
        return $this->configurador->crearUbicacion($datos);
    }

    /** @param array<string, mixed> $datos @return array<string, mixed> */
    public function actualizar(int $id, array $datos): array
    {
        return $this->configurador->actualizarUbicacion($id, $datos);
    }

    public function eliminar(int $id): void
    {
        $this->configurador->eliminarUbicacion($id);
    }
}
