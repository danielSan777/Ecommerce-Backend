<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Presentacion\Controladores;

use App\Core\Pedidos\Aplicacion\CasosDeUso\GestionarPedido;
use App\Core\Pedidos\Dominio\PedidoRechazado;
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
            // Se envía un día antes del inicio de la entrega; el plazo corre hasta el final de ese día en Guayaquil.
            'send_by' => $p->window_start->subDay()->format('Y-m-d'),
            'send_by_at' => CarbonImmutable::parse($p->window_start->subDay()->format('Y-m-d'), 'America/Guayaquil')->endOfDay()->utc()->format('Y-m-d\TH:i:s\Z'),
            'delay' => $p->delayed_at === null ? null : [
                'reason' => $p->delay_reason, 'at' => $p->delayed_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'original_window' => ['start' => $p->original_window_start?->format('Y-m-d'), 'end' => $p->original_window_end?->format('Y-m-d')],
            ],
            'deadline_at' => $p->transfer_deadline_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'receipt_uploaded_at' => $p->receipt_uploaded_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'whatsapp_clicked_at' => $p->whatsapp_clicked_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'paid_at' => $p->paid_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'production_started_at' => $p->production_started_at?->utc()->format('Y-m-d\TH:i:s\Z'),
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
        $pedidos = PedidoModelo::query()->with('items', 'cliente')
            ->when($buscar !== '', fn ($q) => $q->where('number', 'like', '%'.addcslashes($buscar, '%_\\').'%'))
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
            ->limit(200)->get();

        return response()->json(['data' => $pedidos->map(fn ($p) => $this->fila($p))->all()]);
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
        ], ['reason.max' => 'El motivo puede tener hasta 200 caracteres.']);
        $motivo = trim(strip_tags((string) ($validos['reason'] ?? '')));

        return response()->json($this->fila($gestionar->cancelar($this->buscar($numero), (bool) ($validos['notify_buyer'] ?? false), $motivo)->load('items', 'cliente')));
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
