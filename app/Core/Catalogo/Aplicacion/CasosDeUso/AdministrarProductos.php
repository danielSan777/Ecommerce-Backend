<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\Repositorios\RepositorioAdministracionProductos;
use App\Core\Catalogo\Dominio\ValueObjects\Precio;

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
        $datos['sku'] = $this->nuevoSku(); // el código siempre lo asigna Laravel, nunca se escribe a mano

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

    /**
     * Siguiente código: PREFIJO-NNN con los dígitos de config/catalogo.php. Toma el número más alto que existió
     * (incluidos los productos eliminados) y suma uno, así un código nunca se reutiliza.
     */
    public function nuevoSku(): string
    {
        $prefijo = (string) config('catalogo.sku_prefijo');
        $digitos = (int) config('catalogo.sku_digitos');

        $mayor = 0;
        foreach ($this->productos->skusConPrefijo($prefijo.'-') as $sku) {
            if (preg_match('/^'.preg_quote($prefijo, '/').'-(\d+)$/', $sku, $m)) {
                $mayor = max($mayor, (int) $m[1]);
            }
        }

        do {
            $sku = sprintf('%s-%0'.$digitos.'d', $prefijo, ++$mayor);
        } while ($this->productos->existeSku($sku));

        return $sku;
    }

    /** @return array{base_price_cents: int, discount_percent: float, discount_cents: int, final_price_cents: int} */
    public function previsualizarPrecio(int $baseCents, float $porcentaje): array
    {
        $precio = Precio::conDescuentoPorcentaje($baseCents, $porcentaje);

        return ['base_price_cents' => $baseCents, 'discount_percent' => $porcentaje, 'discount_cents' => $precio->descuentoCents(), 'final_price_cents' => $precio->finalCents];
    }
}
