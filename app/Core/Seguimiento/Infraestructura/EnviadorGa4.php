<?php

declare(strict_types=1);

namespace App\Core\Seguimiento\Infraestructura;

use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use App\Core\Seguimiento\Dominio\EnviadorDeCompra;
use Illuminate\Support\Facades\Http;

/**
 * GA4 Measurement Protocol (Paso 20): `purchase` solo desde el servidor, con el `client_id` y el `session_id` que capturó el navegador
 * y `transaction_id` = número de pedido. El valor es el de los productos netos de descuento, sin envío ni recargo por tarjeta.
 */
final class EnviadorGa4 implements EnviadorDeCompra
{
    public function plataforma(): string
    {
        return 'ga4';
    }

    public function activo(): bool
    {
        return (string) config('seguimiento.ga4.measurement_id') !== '' && (string) config('seguimiento.ga4.api_secret') !== '';
    }

    public function omitirPorque(PedidoModelo $pedido): ?string
    {
        if (($pedido->tracking['ga_client_id'] ?? null) === null) {
            return 'Sin client_id de GA4: la persona no aceptó las cookies o no cargó Analytics.';
        }
        if ($pedido->paid_at !== null && $pedido->paid_at->lt(now('UTC')->subHours((int) config('seguimiento.ga4.max_horas', 72)))) {
            return 'La compra tiene más de 72 horas: GA4 ya no la acepta.';
        }

        return null;
    }

    public function enviar(PedidoModelo $pedido): array
    {
        $respuesta = Http::acceptJson()->asJson()->timeout(10)
            ->withQueryParameters(['measurement_id' => (string) config('seguimiento.ga4.measurement_id'), 'api_secret' => (string) config('seguimiento.ga4.api_secret')])
            ->post((string) config('seguimiento.ga4.url'), $this->cuerpo($pedido));

        return ['status' => $respuesta->status(), 'body' => mb_substr($respuesta->body(), 0, 2000)];
    }

    /** @return array<string, mixed> */
    public function cuerpo(PedidoModelo $pedido): array
    {
        $parametros = [
            'transaction_id' => $pedido->number,
            'currency' => 'USD',
            'value' => self::dolares($pedido->subtotal_cents - $pedido->discount_cents),
            'engagement_time_msec' => 1,
            'items' => $pedido->items->map(fn ($i) => [
                'item_id' => $i->product_sku, 'item_name' => $i->product_name, 'quantity' => $i->quantity, 'price' => self::dolares($i->unit_price_cents),
            ])->all(),
        ];
        $sesion = $pedido->tracking['ga_session_id'] ?? null;
        if ($sesion !== null && ctype_digit((string) $sesion)) {
            $parametros['session_id'] = (string) $sesion;
        }

        return [
            'client_id' => (string) $pedido->tracking['ga_client_id'],
            'events' => [['name' => 'purchase', 'params' => $parametros]],
        ];
    }

    /** GA4 pide el valor en dólares con decimales; la conversión se hace solo aquí, en el borde. */
    private static function dolares(int $centavos): float
    {
        return round($centavos / 100, 2);
    }
}
