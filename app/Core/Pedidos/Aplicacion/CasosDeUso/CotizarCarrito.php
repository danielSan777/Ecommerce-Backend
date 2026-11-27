<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\ValueObjects\Precio;
use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use App\Core\Envios\Infraestructura\Modelos\CantonModelo;
use App\Core\Pedidos\Aplicacion\CalculadoraDeTotales;
use App\Shared\Presentacion\ValidacionFallida;

/**
 * Cotización del carrito: los mismos precios, descuento, envío y recargo que usará el pedido, para que Angular solo los muestre.
 * Usa `CalculadoraDeTotales` (el único lugar donde se calcula) y la misma regla de línea que `CrearPedido`.
 */
final class CotizarCarrito
{
    public function __construct(private readonly CalculadoraDeTotales $calculadora) {}

    /**
     * Precio y cantidad de una línea: una maceta personalizable siempre es 1; el resto, de 1 a 20.
     *
     * @return array{quantity: int, unit_price_cents: int, discount_cents: int, line_total_cents: int}
     */
    public static function linea(ProductoModelo $producto, mixed $cantidadPedida): array
    {
        $cantidad = $producto->type === 'personalizable' ? 1 : max(1, min(20, (int) ($cantidadPedida ?? 1)));
        $precio = Precio::conDescuentoPorcentaje((int) $producto->base_price_cents, $producto->discount_percent ?? 0);

        return ['quantity' => $cantidad, 'unit_price_cents' => $precio->baseCents, 'discount_cents' => $precio->descuentoCents() * $cantidad, 'line_total_cents' => $precio->finalCents * $cantidad];
    }

    /**
     * @param  list<array{slug: string, quantity?: int}>  $items
     * @return array<string, mixed>
     */
    public function __invoke(array $items, ?string $cantonCode): array
    {
        $lineas = [];
        foreach ($items as $i => $item) {
            $producto = ProductoModelo::query()->where('slug', (string) $item['slug'])->where('is_active', true)->first();
            if ($producto === null) {
                throw new ValidacionFallida(["items.$i.slug" => ['Uno de los productos ya no está disponible. Revisa tu carrito.']]);
            }
            // Agotado (lo marca Josué): se ve en la tienda pero no se vende
            if (! $producto->is_available) {
                throw new ValidacionFallida(["items.$i.slug" => ["«{$producto->name}» está agotado. Quítalo del carrito para continuar."]]);
            }
            $lineas[] = ['type' => $producto->type] + self::linea($producto, $item['quantity'] ?? 1);
        }

        $canton = $cantonCode === null || $cantonCode === '' ? null : CantonModelo::query()->with('zona')->where('code', $cantonCode)->first();
        $zonaDisponible = $canton?->zona?->is_available === true;
        // Sin cantón (o con una zona sin servicio) no hay tarifa: se usa $1 solo para saber si el envío resulta gratis; ese valor nunca se muestra
        $tarifa = $zonaDisponible ? $canton->zona->price_cents : 1;
        $transferencia = $this->calculadora->calcular($lineas, $tarifa, false);
        $tarjeta = $this->calculadora->calcular($lineas, $tarifa, true);
        $envioConocido = $zonaDisponible || $transferencia['free_shipping'];

        return [
            'mode' => $transferencia['mode'],
            'subtotal_cents' => $transferencia['subtotal_cents'],
            'discount_cents' => $transferencia['discount_cents'],
            // Valor de los productos netos de descuento, sin envío ni recargo: el que Meta, TikTok y GA4 reciben como `value`
            'merchandise_value_cents' => $transferencia['subtotal_cents'] - $transferencia['discount_cents'],
            'discounted_lines' => $transferencia['discounted_lines'],
            'free_shipping' => $transferencia['free_shipping'],
            'shipping_cents' => $envioConocido ? $transferencia['shipping_cents'] : null,
            'card_fee_cents' => $envioConocido ? $tarjeta['card_fee_cents'] : null,
            'total_transfer_cents' => $envioConocido ? $transferencia['total_cents'] : null,
            'total_card_cents' => $envioConocido ? $tarjeta['total_cents'] : null,
            'shipping_available' => $canton === null ? null : $zonaDisponible,
        ];
    }
}
