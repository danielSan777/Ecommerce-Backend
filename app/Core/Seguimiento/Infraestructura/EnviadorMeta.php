<?php

declare(strict_types=1);

namespace App\Core\Seguimiento\Infraestructura;

use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use App\Core\Seguimiento\Aplicacion\AvisoDeCookies;
use App\Core\Seguimiento\Dominio\EnviadorDeCompra;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Meta, API de Conversiones (Paso 20): `Purchase` desde el servidor al validar el pago. El `event_id` es el del pedido, el mismo que usará
 * el navegador con tarjeta, para que Meta junte los dos y cuente una sola compra. Correo, teléfono e id del cliente viajan en hash.
 * Si la persona rechazó las cookies de marketing no se envía nada.
 */
final class EnviadorMeta implements EnviadorDeCompra
{
    public function plataforma(): string
    {
        return 'meta';
    }

    public function activo(): bool
    {
        return (string) config('seguimiento.meta.pixel_id') !== '' && (string) config('seguimiento.meta.access_token') !== '';
    }

    public function omitirPorque(PedidoModelo $pedido): ?string
    {
        if (! app(AvisoDeCookies::class)->permiteMarketing($pedido)) {
            return 'La persona no aceptó las cookies de marketing.';
        }
        if ($pedido->paid_at !== null && $pedido->paid_at->lt(now('UTC')->subDays((int) config('seguimiento.meta.max_dias', 7)))) {
            return 'La compra tiene más de 7 días: Meta ya no la acepta.';
        }

        return null;
    }

    public function enviar(PedidoModelo $pedido): array
    {
        $cuerpo = ['data' => [$this->evento($pedido)], 'access_token' => (string) config('seguimiento.meta.access_token')];
        if ((string) config('seguimiento.meta.test_event_code') !== '') {
            $cuerpo['test_event_code'] = (string) config('seguimiento.meta.test_event_code');
        }
        $url = sprintf('https://graph.facebook.com/%s/%s/events', config('seguimiento.meta.api_version'), config('seguimiento.meta.pixel_id'));
        $respuesta = Http::acceptJson()->asJson()->timeout(10)->post($url, $cuerpo);

        return ['status' => $respuesta->status(), 'body' => mb_substr($respuesta->body(), 0, 2000)];
    }

    /** @return array<string, mixed> */
    public function evento(PedidoModelo $pedido): array
    {
        $t = $pedido->tracking ?? [];
        $cliente = $pedido->cliente;
        $usuario = array_filter([
            'em' => self::hashes([self::correo((string) ($cliente?->email ?? ''))]),
            'ph' => self::hashes([self::telefono((string) ($cliente?->phone ?? ''))]),
            'external_id' => self::hashes([$pedido->customer_id !== null ? (string) $pedido->customer_id : null]),
            'country' => self::hashes(['ec']),
            'client_ip_address' => $pedido->ip,
            'client_user_agent' => $pedido->user_agent,
            'fbp' => $t['fbp'] ?? null,
            'fbc' => $this->fbc($pedido),
        ], fn ($v) => $v !== null && $v !== []);

        return array_filter([
            'event_name' => 'Purchase',
            'event_time' => ($pedido->paid_at ?? CarbonImmutable::now('UTC'))->getTimestamp(),
            'event_id' => $pedido->event_id,
            'action_source' => 'website',
            'event_source_url' => $t['page_url'] ?? null,
            'user_data' => $usuario,
            'custom_data' => [
                'currency' => 'USD',
                'value' => round(($pedido->subtotal_cents - $pedido->discount_cents) / 100, 2),   // sin envío ni recargo por tarjeta
                'content_type' => 'product',
                'content_ids' => $pedido->items->pluck('product_sku')->unique()->values()->all(),
                'num_items' => (int) $pedido->items->sum('quantity'),
                'order_id' => $pedido->number,
            ],
        ], fn ($v) => $v !== null);
    }

    /** Meta pide `fbc`; si falta la cookie se arma desde el clic del anuncio con el formato `fb.1.<milisegundos>.<fbclid>`. */
    private function fbc(PedidoModelo $pedido): ?string
    {
        $t = $pedido->tracking ?? [];
        if (is_string($t['fbc'] ?? null) && $t['fbc'] !== '') {
            return $t['fbc'];
        }
        $fbclid = $t['fbclid'] ?? $t['last_touch']['fbclid'] ?? $t['first_touch']['fbclid'] ?? null;
        if (! is_string($fbclid) || $fbclid === '') {
            return null;
        }
        $fecha = $t['last_touch']['date'] ?? $t['first_touch']['date'] ?? null;
        try {
            $momento = is_string($fecha) ? CarbonImmutable::parse($fecha) : ($pedido->created_at ?? CarbonImmutable::now('UTC'));
        } catch (\Throwable) {
            $momento = $pedido->created_at ?? CarbonImmutable::now('UTC');
        }

        return 'fb.1.'.($momento->getTimestamp() * 1000).'.'.$fbclid;
    }

    /** Meta pide el correo en minúsculas y sin espacios antes del hash. */
    private static function correo(string $correo): ?string
    {
        $c = mb_strtolower(trim($correo));

        return $c === '' ? null : $c;
    }

    /** Meta pide el teléfono solo con dígitos y con el código de país (593…), sin el «+». */
    private static function telefono(string $telefono): ?string
    {
        $d = preg_replace('/\D/', '', $telefono) ?? '';

        return $d === '' ? null : $d;
    }

    /**
     * @param  list<?string>  $valores
     * @return list<string>
     */
    private static function hashes(array $valores): array
    {
        return array_values(array_map(fn (string $v) => hash('sha256', $v), array_filter($valores, fn ($v) => $v !== null && $v !== '')));
    }
}
