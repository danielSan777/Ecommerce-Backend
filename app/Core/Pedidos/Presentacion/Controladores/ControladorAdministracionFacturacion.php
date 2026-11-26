<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Presentacion\Controladores;

use App\Core\Envios\Infraestructura\Modelos\CantonModelo;
use App\Core\Pedidos\Aplicacion\CasosDeUso\GestionarPedido;
use App\Core\Pedidos\Dominio\PedidoRechazado;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Paso 19, facturación manual: «Por facturar» (pagados sin facturar), datos para la factura y marca «Facturado» individual o por lote. */
final class ControladorAdministracionFacturacion extends Controller
{
    private const LIMITE = 200;

    /** Pedidos que se facturan: pagados y vivos (ni cancelados ni vencidos). */
    private function facturables()
    {
        return PedidoModelo::query()->where('payment_status', 'paid')->whereNotIn('status', ['cancelled', 'expired']);
    }

    /** `state=pending` (por defecto) = por facturar; `state=done` = ya facturados. */
    public function index(Request $request): JsonResponse
    {
        $hechos = $request->query('state') === 'done';
        $pedidos = $this->facturables()->with('items')
            ->when($hechos, fn ($q) => $q->whereNotNull('invoiced_at')->orderByDesc('invoiced_at'), fn ($q) => $q->whereNull('invoiced_at')->orderBy('paid_at'))
            ->orderBy('id')->limit(self::LIMITE)->get();

        return response()->json([
            'data' => $pedidos->map(fn (PedidoModelo $p) => $this->fila($p))->all(),
            'meta' => ['pending_count' => $this->facturables()->whereNull('invoiced_at')->count()],
        ]);
    }

    /**
     * Marca varios pedidos como facturados, cada uno con su número de factura (opcional). Cada pedido se procesa por separado:
     * los que fallan no frenan a los demás y la respuesta dice cuál salió bien y cuál no, con el motivo.
     */
    public function marcarLote(Request $request, GestionarPedido $gestionar): JsonResponse
    {
        $datos = ValidacionFallida::validar($request->all(), [
            'orders' => ['required', 'array', 'min:1', 'max:100'],
            'orders.*.number' => ['required', 'string', 'max:20', 'distinct'],
            'orders.*.invoice_number' => ['nullable', 'string', 'max:30'],
        ], [
            'orders.required' => 'Elige al menos un pedido.', 'orders.min' => 'Elige al menos un pedido.', 'orders.max' => 'Máximo 100 pedidos por lote.',
            'orders.*.number.distinct' => 'Hay un pedido repetido en el lote.', 'orders.*.invoice_number.max' => 'El número de factura no puede pasar de 30 caracteres.',
        ]);

        $resultados = [];
        foreach ($datos['orders'] as $linea) {
            $pedido = PedidoModelo::query()->where('number', $linea['number'])->first();
            if ($pedido === null) {
                $resultados[] = ['number' => $linea['number'], 'ok' => false, 'message' => 'No existe ese pedido.'];

                continue;
            }
            try {
                $gestionar->marcarFacturado($pedido, $linea['invoice_number'] ?? null);
                $resultados[] = ['number' => $linea['number'], 'ok' => true, 'message' => null];
            } catch (PedidoRechazado $e) {
                $resultados[] = ['number' => $linea['number'], 'ok' => false, 'message' => $e->getMessage()];
            } catch (ValidacionFallida $e) {
                $resultados[] = ['number' => $linea['number'], 'ok' => false, 'message' => collect($e->errores)->flatten()->first()];
            }
        }

        return response()->json(['results' => $resultados, 'invoiced' => collect($resultados)->where('ok', true)->count()]);
    }

    /** Quita la marca «Facturado» de un pedido (por si se marcó por error). */
    public function quitar(string $numero, GestionarPedido $gestionar): JsonResponse
    {
        $pedido = PedidoModelo::query()->where('number', $numero)->first() ?? throw new PedidoRechazado('order_not_found', 'No existe ese pedido.', 404);
        $gestionar->quitarFacturado($pedido);

        return response()->json($this->fila($pedido->refresh()->load('items')));
    }

    /** @return array<string, mixed> */
    private function fila(PedidoModelo $p): array
    {
        return [
            'number' => $p->number, 'channel' => $p->channel, 'payment_method' => $p->payment_method,
            'paid_at' => $p->paid_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'subtotal_cents' => $p->subtotal_cents, 'discount_cents' => $p->discount_cents, 'shipping_cents' => $p->shipping_cents,
            'card_fee_cents' => $p->card_fee_cents, 'total_cents' => $p->total_cents,
            'items' => $p->items->map(fn ($i) => ['name' => $i->product_name, 'quantity' => (int) $i->quantity, 'line_total_cents' => $i->line_total_cents])->all(),
            'invoice_to' => $this->datosDeFactura($p),
            'invoiced_at' => $p->invoiced_at?->utc()->format('Y-m-d\TH:i:s\Z'), 'invoice_number' => $p->invoice_number,
        ];
    }

    /**
     * A quién se factura: los datos de envío (si el comprador no pidió otros) o los de facturación. Laravel los deja ya resueltos
     * para que el panel solo los copie.
     *
     * @return array<string, string>
     */
    private function datosDeFactura(PedidoModelo $p): array
    {
        $envio = $p->shipping ?? [];
        $fact = $p->billing ?? [];
        if (($fact['same_as_shipping'] ?? true) === true) {
            return [
                'name' => (string) ($envio['name'] ?? ''), 'id_type' => (string) ($envio['id_type'] ?? ''), 'id_number' => (string) ($envio['id_number'] ?? ''),
                'email' => (string) ($envio['email'] ?? ''), 'phone' => (string) ($envio['phone'] ?? ''), 'address' => (string) ($envio['address'] ?? ''),
                'province' => (string) ($envio['province_name'] ?? ''), 'canton' => (string) ($envio['canton_name'] ?? ''), 'same_as_shipping' => 'yes',
            ];
        }
        $canton = ($fact['canton_code'] ?? '') === '' ? null : CantonModelo::query()->with('provincia')->where('code', $fact['canton_code'])->first();

        return [
            'name' => (string) ($fact['name'] ?? ''), 'id_type' => (string) ($fact['id_type'] ?? ''), 'id_number' => (string) ($fact['id_number'] ?? ''),
            'email' => (string) ($fact['email'] ?? ''), 'phone' => (string) ($fact['phone'] ?? ''), 'address' => (string) ($fact['address'] ?? ''),
            'province' => (string) ($canton?->provincia?->name ?? ''), 'canton' => (string) ($canton?->name ?? ''), 'same_as_shipping' => 'no',
        ];
    }
}
