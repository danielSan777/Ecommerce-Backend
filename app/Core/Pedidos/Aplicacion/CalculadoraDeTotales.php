<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Aplicacion;

use App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion;

/**
 * Único lugar donde se calculan envío, descuento, recargo por tarjeta y total.
 *
 * Hay dos modos de venta, que el administrador activa desde el panel (ajuste `sales_mode`):
 *  - `standard`: envío por zona, gratis desde `free_shipping_min_planters` macetas, y recargo por tarjeta (`card_surcharge_percent`).
 *  - `free_shipping_discount`: envío gratis en todas las zonas, sin recargo por tarjeta (el total es el final) y, desde 2 macetas,
 *    `second_planter_discount_percent` % de descuento a todas las macetas menos la de mayor valor (esa se paga completa).
 */
final class CalculadoraDeTotales
{
    public const ESTANDAR = 'standard';

    public const TODO_GRATIS = 'free_shipping_discount';

    public function __construct(private readonly RepositorioConfiguracion $ajustes) {}

    public function modo(): string
    {
        $modo = (string) $this->ajustes->obtener('sales_mode', self::ESTANDAR);

        return $modo === self::TODO_GRATIS ? self::TODO_GRATIS : self::ESTANDAR;
    }

    /**
     * Valores efectivos del modo vigente, para que Angular solo los muestre.
     *
     * @return array{mode: string, free_shipping_min_planters: int, card_surcharge_percent: float, discount_percent: float, free_shipping_everywhere: bool}
     */
    public function parametros(bool $zonasTodasGratis = false): array
    {
        $todoGratis = $this->modo() === self::TODO_GRATIS;

        return [
            'mode' => $this->modo(),
            'free_shipping_min_planters' => (int) $this->ajustes->obtener('free_shipping_min_planters', 2),
            'card_surcharge_percent' => $todoGratis ? 0.0 : (float) $this->ajustes->obtener('card_surcharge_percent', 6),
            'discount_percent' => $todoGratis ? $this->porcentajeDescuento() : 0.0,
            'free_shipping_everywhere' => $todoGratis || $zonasTodasGratis,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lineas  líneas con `type` y `line_total_cents` (ya netas de descuentos de producto)
     * @param  int  $tarifaZonaCents  tarifa de la zona de destino
     * @return array{mode: string, subtotal_cents: int, discount_cents: int, shipping_cents: int, card_fee_cents: int, total_cents: int, free_shipping: bool, discounted_lines: list<int>}
     */
    public function calcular(array $lineas, int $tarifaZonaCents, bool $tarjeta): array
    {
        $modo = $this->modo();
        $subtotal = (int) array_sum(array_column($lineas, 'line_total_cents'));

        $macetas = [];
        foreach ($lineas as $i => $linea) {
            if (($linea['type'] ?? '') === 'personalizable') {
                $macetas[$i] = (int) $linea['line_total_cents'];
            }
        }

        $descuento = 0;
        $lineasConDescuento = [];
        if ($modo === self::TODO_GRATIS && count($macetas) >= 2) {
            // Se paga completa solo la maceta de mayor valor (si empatan, la primera del carrito); todas las demás llevan el descuento
            arsort($macetas);
            $completa = array_key_first($macetas);
            foreach ($macetas as $i => $valor) {
                if ($i === $completa) {
                    continue;
                }
                $lineasConDescuento[] = (int) $i;
                $descuento += $this->porcentaje($valor, $this->porcentajeDescuento());   // cada línea al centavo (mitad hacia arriba)
            }
            sort($lineasConDescuento);
        }

        $gratis = $modo === self::TODO_GRATIS || count($macetas) >= (int) $this->ajustes->obtener('free_shipping_min_planters', 2);
        $envio = $gratis ? 0 : $tarifaZonaCents;

        // Recargo por tarjeta sobre productos − descuento + envío, al centavo (mitad hacia arriba). Sin recargo en el modo "todo gratis".
        $recargo = ($tarjeta && $modo === self::ESTANDAR)
            ? $this->porcentaje($subtotal - $descuento + $envio, (float) $this->ajustes->obtener('card_surcharge_percent', 6))
            : 0;

        return [
            'mode' => $modo, 'subtotal_cents' => $subtotal, 'discount_cents' => $descuento, 'shipping_cents' => $envio,
            'card_fee_cents' => $recargo, 'total_cents' => $subtotal - $descuento + $envio + $recargo,
            'free_shipping' => $envio === 0, 'discounted_lines' => $lineasConDescuento,
        ];
    }

    private function porcentajeDescuento(): float
    {
        return max(0.0, min(100.0, (float) $this->ajustes->obtener('second_planter_discount_percent', 30)));
    }

    private function porcentaje(int $centavos, float $porcentaje): int
    {
        return (int) floor($centavos * $porcentaje / 100 + 0.5);
    }
}
