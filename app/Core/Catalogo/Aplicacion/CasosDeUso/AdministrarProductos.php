<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\Repositorios\RepositorioAdministracionProductos;
use Illuminate\Support\Str;

/** Listar, ver, crear, editar y eliminar productos desde el panel. Dinero siempre en centavos. */
final class AdministrarProductos
{
    public function __construct(private readonly RepositorioAdministracionProductos $productos) {}

    /** @return list<array<string, mixed>> */
    public function listar(?string $categoria, ?string $texto): array
    {
        return $this->productos->listar($categoria, $texto);
    }

    /** @return array<string, mixed> */
    public function ver(string $sku): array
    {
        return $this->productos->obtener($sku);
    }

    /**
     * El SKU y la dirección (slug) quedan fijos para siempre. Sin SKU se genera uno que nunca se repite.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function crear(array $datos): array
    {
        $datos['sku'] ??= $this->nuevoSku();

        return $this->productos->crear($datos);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function actualizar(string $sku, array $datos): array
    {
        return $this->productos->actualizar($sku, $datos);
    }

    public function eliminar(string $sku): void
    {
        $this->productos->eliminar($sku);
    }

    public function nuevoSku(): string
    {
        do {
            $sku = 'JDS-'.strtoupper(Str::random(6));
        } while ($this->productos->existeSku($sku));

        return $sku;
    }
}
