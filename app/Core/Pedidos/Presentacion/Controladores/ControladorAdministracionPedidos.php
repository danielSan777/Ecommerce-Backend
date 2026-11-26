<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Presentacion\Controladores;

use App\Core\Pedidos\Aplicacion\CasosDeUso\GestionarPedido;
use App\Core\Pedidos\Dominio\PedidoRechazado;
use App\Core\Pedidos\Infraestructura\Modelos\EventoDePedidoModelo;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ControladorAdministracionPedidos extends Controller
{
    /** @return array<string, mixed> */
    private function fila(PedidoModelo $p): array
    {
        return [
            'number' => $p->number, 'channel' => $p->channel, 'payment_method' => $p->payment_method, 'payment_status' => $p->payment_status, 'status' => $p->status,
            'customer' => ['name' => $p->cliente->name, 'email' => $p->cliente->email, 'phone' => $p->contact['phone'] ?? $p->cliente->phone],
            'total_cents' => $p->total_cents, 'discount_cents' => $p->discount_cents, 'card_fee_cents' => $p->card_fee_cents, 'shipping_cents' => $p->shipping_cents, 'shipping_zone' => $p->shipping_zone,
            'cycle_date' => $p->cycle_date->format('Y-m-d'), 'dispatch_date' => $p->dispatch_date->format('Y-m-d'),
            'window' => ['start' => $p->window_start->format('Y-m-d'), 'end' => $p->window_end->format('Y-m-d'), 'type' => $p->window_type],
            // Se envía un día antes del inicio de la entrega; el plazo vence a las 7:00 de ese día en Guayaquil.
            'send_by' => $p->window_start->subDay()->format('Y-m-d'),
            'send_by_at' => CarbonImmutable::parse($p->window_start->subDay()->format('Y-m-d'), 'America/Guayaquil')->setTime(7, 0)->utc()->format('Y-m-d\TH:i:s\Z'),
            'delay' => $p->delayed_at === null ? null : [
                'reason' => $p->delay_reason, 'at' => $p->delayed_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'original_window' => ['start' => $p->original_window_start?->format('Y-m-d'), 'end' => $p->original_window_end?->format('Y-m-d')],
            ],
            'cancel_reason' => $p->cancel_reason,
            'cancelled_at' => $p->cancelled_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'refund' => $p->refund_cents === null ? null : ['cents' => $p->refund_cents, 'at' => $p->refunded_at?->utc()->format('Y-m-d\TH:i:s\Z')],
            'deadline_at' => $p->transfer_deadline_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'receipt_uploaded_at' => $p->receipt_uploaded_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'whatsapp_clicked_at' => $p->whatsapp_clicked_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'paid_at' => $p->paid_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'production_started_at' => $p->production_started_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'invoiced_at' => $p->invoiced_at?->utc()->format('Y-m-d\TH:i:s\Z'), 'invoice_number' => $p->invoice_number,
            'shipped_at' => $p->shipped_at?->utc()->format('Y-m-d\TH:i:s\Z'), 'tracking_number' => $p->tracking_number,
            'created_at' => $p->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'items' => $p->items->map(fn ($i) => ['id' => $i->id, 'has_preview' => $i->preview_path !== null, 'has_frame_photo' => $i->frame_photo_path !== null, 'sku' => $i->product_sku, 'name' => $i->product_name, 'type' => $i->type, 'line_total_cents' => $i->line_total_cents, 'personalization' => $i->personalization])->all(),
            'shipping' => $p->shipping, 'billing' => $p->billing,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filtro = (string) $request->query('status', 'all');
        // Búsqueda por código (JDS-1005 o solo 1005): dentro del filtro elegido. Se escapan % y _ para que cuenten como texto.
        $buscar = mb_substr(trim((string) $request->query('q', '')), 0, 30);
        // La lista muestra 200; la exportación CSV pide más (hasta 2000) con los mismos filtros.
        $limite = max(1, min(2000, (int) $request->query('limit', 200)));
        $pedidos = PedidoModelo::query()->with('items', 'cliente')
            ->when($buscar !== '', fn ($q) => $q->where('number', 'like', '%'.addcslashes($buscar, '%_\\').'%'))
            // Filtros de operación: canal, método de pago y ciclo (fecha del ciclo, YYYY-MM-DD). Valores desconocidos no filtran por error: no devuelven nada.
            ->when(in_array($request->query('channel'), ['web', 'whatsapp'], true), fn ($q) => $q->where('channel', $request->query('channel')))
            ->when(in_array($request->query('payment_method'), ['transfer', 'card'], true), fn ($q) => $q->where('payment_method', $request->query('payment_method')))
            ->when(is_string($request->query('cycle')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('cycle')), fn ($q) => $q->whereDate('cycle_date', $request->query('cycle')))
            ->when($filtro === 'pending', fn ($q) => $q->where('payment_status', 'pending_validation')->where('status', 'created'))
            ->when($filtro === 'paid', fn ($q) => $q->where('payment_status', 'paid'))
            ->when($filtro === 'in_production', fn ($q) => $q->where('status', 'in_production'))
            ->when($filtro === 'shipped', fn ($q) => $q->where('status', 'shipped'))
            ->when($filtro === 'expired', fn ($q) => $q->where('status', 'expired'))
            ->when($filtro === 'cancelled', fn ($q) => $q->where('status', 'cancelled'))
            ->when($filtro === 'in_production',
                // Producción: lo más cercano a vencer primero (default) o los de mayor monto; el desempate es siempre el vencimiento.
                fn ($q) => $request->query('sort') === 'amount'
                    ? $q->orderByDesc('total_cents')->orderBy('window_start')->orderBy('id')
                    : $q->orderBy('window_start')->orderByDesc('total_cents')->orderBy('id'),
                fn ($q) => $q->orderByDesc('id'))
            ->limit($limite)->get();

        return response()->json(['data' => $pedidos->map(fn ($p) => $this->fila($p))->all()]);
    }

    /** Historial del pedido, del más antiguo al más reciente: cada hecho con su texto, su fecha y quién lo hizo. */
    public function historial(string $numero): JsonResponse
    {
        $pedido = $this->buscar($numero);
        $eventos = EventoDePedidoModelo::query()->where('order_id', $pedido->id)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (EventoDePedidoModelo $e) => [
                'id' => $e->id, 'type' => $e->type, 'reason' => $e->reason,
                'text' => $this->textoDeEvento($e),
                'actor' => $e->actor, 'actor_name' => $e->actor_name,
                'at' => $e->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ])->all();

        return response()->json(['data' => $eventos]);
    }

    private function textoDeEvento(EventoDePedidoModelo $e): string
    {
        $d = $e->data ?? [];
        $dolares = fn ($c) => '$'.number_format(((int) $c) / 100, 2);
        $metodo = fn ($m) => $m === 'card' ? 'tarjeta' : 'transferencia';

        if ($e->type === 'email') {
            return 'Correo enviado al comprador: '.($d['subject'] ?? $e->reason);
        }

        return match ($e->reason) {
            'created' => 'Pedido creado ('.($d['channel'] ?? 'web').', '.$metodo($d['method'] ?? '').', '.$dolares($d['total_cents'] ?? 0).')',
            'receipt_uploaded' => 'El comprador subió el comprobante (intento '.($d['attempt'] ?? 1).')',
            'whatsapp_clicked' => 'El comprador tocó el botón de WhatsApp',
            'paid' => 'Pago confirmado por '.$metodo($d['method'] ?? '').(($d['late'] ?? false) ? ' (fuera de plazo)' : '').'; entrega '.($d['window'] ?? ''),
            'expired' => ($d['method'] ?? '') === 'card' ? 'Venció el cupo apartado para pagar con tarjeta' : 'Venció el plazo para transferir',
            'in_production' => 'Pasó a producción',
            'shipped' => 'Enviado, guía '.($d['tracking_number'] ?? ''),
            'tracking_corrected' => 'Guía corregida: '.($d['from'] ?? '—').' → '.($d['to'] ?? ''),
            'delayed' => 'Retraso: nueva entrega '.($d['to'] ?? '').' (antes '.($d['from'] ?? '').')'.(($d['reason'] ?? null) ? ' · '.$d['reason'] : ''),
            'cancelled' => 'Pedido cancelado'.(($d['reason'] ?? null) ? ': '.$d['reason'] : '').(($d['notify_buyer'] ?? false) ? ' · se avisó al comprador' : ''),
            'refunded' => 'Reembolso registrado: '.$dolares($d['cents'] ?? 0),
            'invoiced' => 'Marcado como facturado'.(($d['invoice_number'] ?? null) ? ', factura '.$d['invoice_number'] : ''),
            'invoice_corrected' => 'Número de factura corregido: '.($d['from'] ?? '—').' → '.($d['to'] ?? ''),
            'invoice_cleared' => 'Se quitó la marca de facturado'.(($d['invoice_number'] ?? null) ? ' (factura '.$d['invoice_number'].')' : ''),
            default => $e->reason,
        };
    }

    public function show(string $numero): JsonResponse
    {
        return response()->json($this->fila($this->buscar($numero)));
    }

    public function marcarPagado(string $numero, Request $request, GestionarPedido $gestionar): JsonResponse
    {
        $pedido = $gestionar->marcarPagado($this->buscar($numero), $request->boolean('late'), $request->boolean('confirm_over_capacity'));

        return response()->json($this->fila($pedido->load('items', 'cliente')));
    }

    public function enProduccion(string $numero, GestionarPedido $gestionar): JsonResponse
    {
        return response()->json($this->fila($gestionar->marcarEnProduccion($this->buscar($numero))->load('items', 'cliente')));
    }

    public function enviado(string $numero, Request $request, GestionarPedido $gestionar): JsonResponse
    {
        return response()->json($this->fila($gestionar->marcarEnviado($this->buscar($numero), (string) $request->input('tracking_number', ''))->load('items', 'cliente')));
    }

    public function cancelar(string $numero, Request $request, GestionarPedido $gestionar): JsonResponse
    {
        $validos = ValidacionFallida::validar($request->all(), [
            'notify_buyer' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:200'],
            'refund_amount' => ['nullable', 'string', 'regex:/^\d{1,6}([.,]\d{1,2})?$/'],
        ], ['reason.max' => 'El motivo puede tener hasta 200 caracteres.', 'refund_amount.regex' => 'Escribe el monto en dólares, por ejemplo 12.50.']);
        $motivo = trim(strip_tags((string) ($validos['reason'] ?? '')));

        return response()->json($this->fila($gestionar->cancelar($this->buscar($numero), (bool) ($validos['notify_buyer'] ?? false), $motivo, $this->centavos($validos['refund_amount'] ?? null))->load('items', 'cliente')));
    }

    /** Registra cuánto se reembolsó a un pedido ya cancelado; el dinero se devuelve a mano fuera del sistema. */
    public function reembolso(string $numero, Request $request, GestionarPedido $gestionar): JsonResponse
    {
        $validos = ValidacionFallida::validar($request->all(), [
            'refund_amount' => ['required', 'string', 'regex:/^\d{1,6}([.,]\d{1,2})?$/'],
        ], ['refund_amount.required' => 'Escribe el monto reembolsado.', 'refund_amount.regex' => 'Escribe el monto en dólares, por ejemplo 12.50.']);

        return response()->json($this->fila($gestionar->registrarReembolso($this->buscar($numero), (int) $this->centavos($validos['refund_amount']))->load('items', 'cliente')));
    }

    /** "12.50" o "12,5" → 1250. */
    private function centavos(?string $dolares): ?int
    {
        return $dolares === null || $dolares === '' ? null : (int) round(((float) str_replace(',', '.', $dolares)) * 100);
    }

    /** Retraso: nueva fecha de entrega (`window_start`–`window_end`) y motivo breve; el comprador recibe un correo con ella. */
    public function retraso(string $numero, Request $request, GestionarPedido $gestionar): JsonResponse
    {
        $validos = ValidacionFallida::validar($request->all(), [
            'window_start' => ['required', 'date_format:Y-m-d'],
            'window_end' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:200'],
        ], [
            'window_start.required' => 'Elige la fecha desde la que entregas.', 'window_start.date_format' => 'Elige la fecha desde la que entregas.',
            'window_end.required' => 'Elige la fecha hasta la que entregas.', 'window_end.date_format' => 'Elige la fecha hasta la que entregas.',
            'reason.max' => 'El motivo puede tener hasta 200 caracteres.',
        ]);
        $motivo = trim(strip_tags((string) ($validos['reason'] ?? '')));

        return response()->json($this->fila($gestionar->registrarRetraso($this->buscar($numero), $validos['window_start'], $validos['window_end'], $motivo)->load('items', 'cliente')));
    }

    /** El comprobante vive en el disco privado: solo el administrador lo descarga. */
    public function comprobante(string $numero): StreamedResponse
    {
        $pedido = $this->buscar($numero);
        if ($pedido->receipt_path === null || ! Storage::disk('private')->exists($pedido->receipt_path)) {
            throw new PedidoRechazado('not_found', 'Este pedido no tiene comprobante.', 404);
        }

        return Storage::disk('private')->response($pedido->receipt_path, $pedido->receipt_original_name);
    }

    /** Foto de la maceta ya personalizada (la que armó el comprador): solo el administrador. */
    public function vistaDelItem(string $numero, int $item): StreamedResponse
    {
        return $this->imagenDelItem($numero, $item, 'preview_path', 'maceta');
    }

    /** Foto que subió el comprador para el marco: solo el administrador. */
    public function fotoDelMarco(string $numero, int $item): StreamedResponse
    {
        return $this->imagenDelItem($numero, $item, 'frame_photo_path', 'marco');
    }

    private function imagenDelItem(string $numero, int $item, string $campo, string $nombre): StreamedResponse
    {
        $linea = $this->buscar($numero)->items->firstWhere('id', $item);
        $ruta = $linea?->{$campo};
        if ($ruta === null || ! Storage::disk('private')->exists($ruta)) {
            throw new PedidoRechazado('not_found', 'No encontramos esa imagen.', 404);
        }

        return Storage::disk('private')->response($ruta, "{$numero}-{$nombre}-{$item}.jpg", ['Cache-Control' => 'private, no-store']);
    }

    private function buscar(string $numero): PedidoModelo
    {
        return PedidoModelo::query()->with('items', 'cliente')->where('number', $numero)->first()
            ?? throw new PedidoRechazado('not_found', 'No encontramos ese pedido.', 404);
    }
}
