<?php

declare(strict_types=1);

namespace App\Core\Pagos\Aplicacion\CasosDeUso;

use App\Core\Pagos\Dominio\PasarelaDePago;
use App\Core\Pagos\Infraestructura\Modelos\IntentoDePagoModelo;
use App\Core\Pedidos\Dominio\PedidoRechazado;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Paso 13, pasos 1 y 2: crea (o devuelve) el intento de pago de un pedido con tarjeta y los datos de la Cajita.
 * Doble clic devuelve el mismo intento mientras no venza (10 minutos); con `$nuevo` (abrir o recargar la pantalla) se crea otro,
 * porque PayPhone no acepta mostrar dos veces el mismo clientTransactionId.
 */
final class IniciarPagoConTarjeta
{
    public function __construct(private readonly PasarelaDePago $pasarela) {}

    /** @return array<string, mixed> */
    public function __invoke(string $token, ?CarbonImmutable $ahora = null, bool $nuevo = false): array
    {
        $ahora ??= CarbonImmutable::now('UTC');
        $simulada = $this->pasarela->esSimulada();
        if ($simulada && app()->isProduction()) {
            throw new PedidoRechazado('payments_unavailable', 'El pago con tarjeta no está disponible en este momento. Elige transferencia bancaria.', 503);
        }

        return DB::transaction(function () use ($token, $ahora, $simulada, $nuevo) {
            $p = PedidoModelo::query()->with('cliente')->where('token', $token)->lockForUpdate()->first()
                ?? throw new PedidoRechazado('not_found', 'No encontramos ese pedido.', 404);

            if ($p->payment_method !== 'card') {
                throw new PedidoRechazado('not_card_order', 'Este pedido no es de pago con tarjeta.', 409);
            }
            if ($p->payment_status === 'paid') {
                throw new PedidoRechazado('already_paid', 'Este pedido ya está pagado.', 409);
            }
            if ($p->status !== 'created') {
                throw new PedidoRechazado('order_closed', 'Este pedido venció o fue cancelado. Haz uno nuevo desde la tienda.', 409);
            }

            $intento = $nuevo ? null : IntentoDePagoModelo::query()->where('order_id', $p->id)->where('status', 'pending')->where('expires_at', '>', $ahora)->latest('id')->first();
            if ($intento === null) {
                // Los intentos vencidos se cierran; los vigentes se dejan pendientes: si el cliente aprobó en su app PayPhone antes de recargar, su respuesta aún se confirma
                IntentoDePagoModelo::query()->where('order_id', $p->id)->where('status', 'pending')->where('expires_at', '<=', $ahora)->update(['status' => 'expired']);
                $numero = IntentoDePagoModelo::query()->where('order_id', $p->id)->count() + 1;
                $intento = IntentoDePagoModelo::query()->create([
                    'order_id' => $p->id, 'client_transaction_id' => "{$p->number}-{$numero}",
                    'expected_amount_cents' => $p->total_cents, 'simulated' => $simulada,
                    'expires_at' => $ahora->addMinutes((int) config('payphone.form_minutes', 10)),
                ]);
            }
            // El cupo sigue apartado mientras haya un intento en marcha
            $p->update(['card_reserved_until' => $ahora->addMinutes((int) config('payphone.reserve_minutes', 15))]);

            return [
                'client_transaction_id' => $intento->client_transaction_id,
                'expires_at' => $intento->expires_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'simulated' => $simulada,
                'box' => $simulada ? null : $this->cajita($p, $intento),
            ];
        });
    }

    /** Parámetros de la Cajita (todos los montos en centavos). Los datos del titular son los reales del comprador. @return array<string, mixed> */
    private function cajita(PedidoModelo $p, IntentoDePagoModelo $i): array
    {
        $telefono = preg_replace('/\D/', '', (string) $p->cliente?->phone) ?? '';
        $telefono = match (true) {
            str_starts_with($telefono, '593') && strlen($telefono) === 12 => '+'.$telefono,
            str_starts_with($telefono, '0') && strlen($telefono) === 10 => '+593'.substr($telefono, 1),
            default => null,
        };

        return array_filter([
            'token' => (string) config('payphone.token'),
            'store_id' => (string) config('payphone.store_id'),
            'css_url' => config('payphone.box_css'),
            'js_url' => config('payphone.box_js'),
            'client_transaction_id' => $i->client_transaction_id,
            'amount' => $i->expected_amount_cents,
            // El monto total es el calculado por Laravel (productos + envío + recargo). El desglose de IVA no está definido todavía.
            'amount_without_tax' => $i->expected_amount_cents,
            'currency' => 'USD',
            'reference' => mb_substr("Pedido {$p->number} · Jardín de Sele", 0, 100),
            'email' => $p->cliente?->email,
            'phone_number' => $telefono,
            'document_id' => $p->cliente?->id_number,
            'identification_type' => $p->cliente?->id_type === 'ruc' ? 2 : 1,
            'lang' => 'es',
            'time_zone' => -5,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
