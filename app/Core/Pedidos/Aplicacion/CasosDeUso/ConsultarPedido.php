<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Aplicacion\CasosDeUso;

use App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion;
use App\Core\Pedidos\Dominio\PedidoRechazado;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use Carbon\CarbonImmutable;

/**
 * Lo que ve el comprador con su enlace privado /pedido/{token}: pedido pendiente de pago (transferencia) o seguimiento.
 * El token largo es la única llave: sin datos personales en la vista previa ni en el mensaje de WhatsApp.
 */
final class ConsultarPedido
{
    private const DIAS = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    private const MESES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(private readonly RepositorioConfiguracion $ajustes) {}

    public function porToken(string $token): PedidoModelo
    {
        return PedidoModelo::query()->with('items', 'cliente')->where('token', $token)->first()
            ?? throw new PedidoRechazado('not_found', 'No encontramos ese pedido.', 404);
    }

    /** @return array<string, mixed> */
    public function vista(PedidoModelo $p, ?CarbonImmutable $ahora = null): array
    {
        $ahora ??= CarbonImmutable::now('UTC');

        return $p->payment_status === 'paid' || $p->status === 'cancelled' ? $this->seguimiento($p) : $this->pendiente($p, $ahora);
    }

    /** @return array<string, mixed> */
    private function pendiente(PedidoModelo $p, CarbonImmutable $ahora): array
    {
        $limite = CarbonImmutable::instance($p->transfer_deadline_at);

        return [
            'order_number' => $p->number,
            'status' => $p->status === 'expired' || $limite <= $ahora ? 'expired' : 'pending_payment',
            'total_cents' => $p->total_cents,
            'deadline_at' => $limite->utc()->format('Y-m-d\TH:i:s\Z'),
            'deadline_label' => $this->etiquetaLimite($limite),
            'window' => ['start' => $p->window_start->format('Y-m-d'), 'end' => $p->window_end->format('Y-m-d')],
            'bank_accounts' => $this->ajustes->obtener('bank_accounts', []),
            'receipt' => ['uploaded' => $p->receipt_uploaded_at !== null],
            'whatsapp_url' => url("/api/v1/orders/{$p->token}/whatsapp"),
            'share' => $this->compartir($p),
        ];
    }

    /** @return array<string, mixed> */
    private function seguimiento(PedidoModelo $p): array
    {
        return [
            'order_number' => $p->number,
            'visible_status' => $p->status === 'cancelled' ? 'cancelado' : 'confirmado',
            'payment' => ['method' => $p->payment_method, 'status' => $p->payment_status, 'paid_at' => $p->paid_at?->utc()->format('Y-m-d\TH:i:s\Z')],
            'window' => ['start' => $p->window_start->format('Y-m-d'), 'end' => $p->window_end->format('Y-m-d')],
            'shipment' => ['carrier' => 'Servientrega', 'tracking_number' => null],
            'items' => $p->items->map(fn ($i) => ['name' => $i->product_name, 'name_text' => $i->personalization['name_text'] ?? null])->all(),
            'total_cents' => $p->total_cents,
            'share' => $this->compartir($p),
        ];
    }

    /** Título, descripción e imagen para la vista previa de WhatsApp. Sin datos personales. @return array<string, mixed> */
    public function compartir(PedidoModelo $p): array
    {
        $nombres = $p->items->where('type', 'personalizable')->pluck('product_name')->unique()->values()->all();
        $tiene = $p->items->contains(fn ($i) => $i->preview_path !== null && $i->type === 'personalizable');

        return [
            'title' => "Pedido {$p->number} · Jardín de Sele",
            'description' => ($nombres === [] ? 'Tu pedido' : implode(', ', $nombres)).' · Total $'.number_format($p->total_cents / 100, 2, '.', ''),
            'image' => $tiene ? url("/api/v1/orders/{$p->token}/preview") : null,
        ];
    }

    /** Mensaje corto del comprobante: número, total, hasta 3 productos y el enlace al pedido (de ahí sale la vista previa). */
    public function mensajeDeComprobante(PedidoModelo $p): string
    {
        $lineas = ["Hola 🌿 Te envío el comprobante de mi pedido {$p->number}.", 'Total: $'.number_format($p->total_cents / 100, 2, '.', '')];
        $items = $p->items->values();
        foreach ($items->take(3) as $item) {
            $lineas[] = '• '.$item->product_name;
        }
        if ($items->count() > 3) {
            $lineas[] = '• y '.($items->count() - 3).' más';
        }
        $lineas[] = rtrim(explode(',', (string) config('app.frontend_url'))[0], '/').'/pedido/'.$p->token;

        return implode("\n", $lineas);
    }

    public function enlaceDeWhatsapp(PedidoModelo $p): string
    {
        $telefono = preg_replace('/\D/', '', (string) $this->ajustes->obtener('whatsapp_phone', '')) ?? '';

        return "https://wa.me/{$telefono}?text=".rawurlencode($this->mensajeDeComprobante($p));
    }

    private function etiquetaLimite(CarbonImmutable $instante): string
    {
        $l = $instante->setTimezone('America/Guayaquil');

        return sprintf('Paga antes del %s %d de %s, %s', self::DIAS[$l->dayOfWeekIso], $l->day, self::MESES[$l->month], $l->format('H:i'));
    }
}
