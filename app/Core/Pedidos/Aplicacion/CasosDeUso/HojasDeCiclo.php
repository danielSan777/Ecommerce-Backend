<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Aplicacion\CasosDeUso;

use App\Core\Entrega\Dominio\MotorDeEntrega;
use App\Core\Envios\Infraestructura\Modelos\ZonaDeEnvioModelo;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use Illuminate\Database\Eloquent\Collection;

/**
 * Paso 19: lo que Josué imprime y usa cada día, por ciclo. La hoja de producción no lleva precios. Solo entran los pedidos
 * pagados y no cancelados del ciclo; un pedido programado ya vive en el ciclo que lo despacha (se asigna al crearlo).
 */
final class HojasDeCiclo
{
    public function __construct(private readonly MotorDeEntrega $motor) {}

    /** @return Collection<int, PedidoModelo> */
    private function pedidos(string $fecha): Collection
    {
        return PedidoModelo::query()->with('items', 'cliente')->where('cycle_date', $fecha)
            ->where('payment_status', 'paid')->whereNotIn('status', ['cancelled', 'expired'])
            ->orderBy('window_start')->orderBy('id')->get();
    }

    /** @return array<string, mixed> */
    private function cabecera(string $fecha): array
    {
        $despacho = $this->motor->despacho($fecha);

        return ['date' => $fecha, 'dispatch_date' => $despacho, 'window' => $this->motor->ventana($despacho)];
    }

    /** @return array<string, int> código de zona → días adicionales */
    private function diasExtraPorZona(): array
    {
        return ZonaDeEnvioModelo::query()->pluck('extra_days', 'code')->map(fn ($d) => (int) $d)->all();
    }

    /** @return array{cycle: array<string, mixed>, orders: list<array<string, mixed>>, totals: array{orders: int, planters: int}} */
    public function produccion(string $fecha): array
    {
        $extra = $this->diasExtraPorZona();
        $macetasTotal = 0;
        $pedidos = $this->pedidos($fecha)->map(function (PedidoModelo $p) use ($extra, &$macetasTotal) {
            $macetas = 0;
            $items = $p->items->map(function ($i) use (&$macetas) {
                $esMaceta = $i->type === 'personalizable';
                $macetas += $esMaceta ? (int) $i->quantity : 0;
                $per = $i->personalization ?? [];

                return [
                    'id' => $i->id, 'name' => $i->product_name, 'type' => $i->type, 'quantity' => (int) $i->quantity,
                    'plate_text' => $esMaceta ? (string) ($per['name_text'] ?? '') : null,
                    'lens_color' => $per['lens_color'] ?? null, 'glasses_color' => $per['glasses_color'] ?? null,
                    'accessories' => array_map(fn ($a) => $a['name'] ?? '', $per['accessories'] ?? []),
                    'frame_text' => $per['frame_text'] ?? null,
                    'has_frame_photo' => $i->frame_photo_path !== null,
                    'dedication' => ($per['dedication']['mode'] ?? 'none') === 'text' ? (string) ($per['dedication']['text'] ?? '') : null,
                ];
            })->all();
            $macetasTotal += $macetas;

            return [
                'number' => $p->number, 'channel' => $p->channel, 'payment_method' => $p->payment_method, 'payment_status' => $p->payment_status, 'status' => $p->status,
                'zone' => $p->shipping_zone, 'extra_days' => $extra[$p->shipping_zone] ?? 0,
                'window' => ['start' => $p->window_start->format('Y-m-d'), 'end' => $p->window_end->format('Y-m-d')],
                'scheduled' => $p->window_type === 'scheduled',
                'planters' => $macetas, 'items' => $items,
            ];
        })->all();

        return ['cycle' => $this->cabecera($fecha), 'orders' => $pedidos, 'totals' => ['orders' => count($pedidos), 'planters' => $macetasTotal]];
    }

    /** @return array{cycle: array<string, mixed>, orders: list<array<string, mixed>>} */
    public function envios(string $fecha): array
    {
        $pedidos = $this->pedidos($fecha)->map(function (PedidoModelo $p) {
            $e = $p->shipping ?? [];

            return [
                'number' => $p->number, 'status' => $p->status,
                'name' => (string) ($e['name'] ?? ''), 'id_type' => (string) ($e['id_type'] ?? ''), 'id_number' => (string) ($e['id_number'] ?? ''),
                'phone' => (string) ($p->contact['phone'] ?? $e['phone'] ?? ''),
                'province' => (string) ($e['province_name'] ?? ''), 'canton' => (string) ($e['canton_name'] ?? ''),
                'address' => (string) ($e['address'] ?? ''), 'reference' => (string) ($e['reference'] ?? ''),
                'window' => ['start' => $p->window_start->format('Y-m-d'), 'end' => $p->window_end->format('Y-m-d')],
                'tracking_number' => $p->tracking_number,
            ];
        })->all();

        return ['cycle' => $this->cabecera($fecha), 'orders' => $pedidos];
    }
}
