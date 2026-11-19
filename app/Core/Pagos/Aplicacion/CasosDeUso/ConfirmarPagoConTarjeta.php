<?php

declare(strict_types=1);

namespace App\Core\Pagos\Aplicacion\CasosDeUso;

use App\Core\Pagos\Dominio\PasarelaDePago;
use App\Core\Pagos\Infraestructura\Modelos\IntentoDePagoModelo;
use App\Core\Pedidos\Aplicacion\CasosDeUso\GestionarPedido;
use App\Core\Pedidos\Dominio\PedidoRechazado;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Paso 13, pasos 4 a 6: confirma con PayPhone (Confirm) y solo entonces marca el pedido como pagado.
 * Valida estado aprobado (3), que el `clientTransactionId` sea el del intento y que el monto sea el esperado.
 * Todo con el intento bloqueado: recargas y dobles llamadas devuelven el resultado ya guardado sin reprocesar.
 */
final class ConfirmarPagoConTarjeta
{
    public const APROBADO = 'approved';

    public const RECHAZADO = 'declined';

    public const ERROR = 'error';          // no se pudo confirmar ahora; se puede volver a intentar dentro de los 5 minutos

    public const REVISION = 'review';      // algo no cuadra: Josué lo revisa, el comprador no debe pagar otra vez

    public function __construct(private readonly PasarelaDePago $pasarela, private readonly GestionarPedido $gestionar) {}

    /** @return array{result: string, message: string, order: PedidoModelo} */
    public function __invoke(int $id, string $clientTransactionId, ?CarbonImmutable $ahora = null): array
    {
        $ahora ??= CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($id, $clientTransactionId, $ahora) {
            $intento = IntentoDePagoModelo::query()->where('client_transaction_id', $clientTransactionId)->lockForUpdate()->first()
                ?? throw new PedidoRechazado('attempt_not_found', 'No encontramos ese pago.', 404);
            $p = PedidoModelo::query()->with('items', 'cliente')->lockForUpdate()->findOrFail($intento->order_id);

            if ($intento->status !== 'pending') {
                return $this->resultado($intento, $p);   // recarga de la página de respuesta: se devuelve lo ya guardado
            }
            if ($intento->simulated && app()->isProduction()) {
                throw new PedidoRechazado('payments_unavailable', 'El pago con tarjeta no está disponible en este momento.', 503);
            }

            $r = $this->pasarela->confirmar($id, $clientTransactionId);
            if (! $r['ok']) {
                $intento->update(['message' => mb_substr((string) $r['message'], 0, 255)]);

                return ['result' => self::ERROR, 'message' => 'No pudimos confirmar tu pago todavía. Inténtalo de nuevo en un momento.', 'order' => $p];
            }

            if ($r['status_code'] === 2) {
                $intento->update(['status' => 'declined', 'status_code' => 2, 'confirmed_at' => $ahora, 'message' => 'Cancelado o rechazado en PayPhone']);

                return $this->resultado($intento, $p);
            }
            if ($r['status_code'] !== 3) {
                $intento->update(['status' => 'declined', 'status_code' => $r['status_code'], 'confirmed_at' => $ahora, 'message' => 'Estado no aprobado']);

                return $this->resultado($intento, $p);
            }

            // Aprobado en PayPhone: ahora se valida contra lo que Laravel esperaba
            if ($r['client_transaction_id'] !== $clientTransactionId || $r['amount_cents'] !== $intento->expected_amount_cents) {
                Log::warning("PayPhone: pago aprobado que no coincide con el intento {$clientTransactionId} (monto {$r['amount_cents']} vs {$intento->expected_amount_cents})");
                $intento->update(['status' => 'amount_mismatch', 'status_code' => 3, 'confirmed_at' => $ahora, 'message' => 'El monto o la referencia no coinciden']);

                return $this->resultado($intento, $p);
            }
            if ($r['transaction_id'] !== null && IntentoDePagoModelo::query()->where('payphone_transaction_id', $r['transaction_id'])->exists()) {
                $intento->update(['status' => 'duplicate', 'status_code' => 3, 'confirmed_at' => $ahora, 'message' => 'Transacción ya registrada']);

                return $this->resultado($intento, $p);
            }

            $datos = ['payphone_transaction_id' => $r['transaction_id'], 'authorization_code' => $r['authorization_code'], 'status_code' => 3, 'confirmed_at' => $ahora];
            if ($p->payment_status === 'paid') {
                // Segundo pago aprobado del mismo pedido: Josué debe reembolsar uno (aviso en el Paso 18)
                Log::warning("PayPhone: pago duplicado en el pedido {$p->number} ({$clientTransactionId})");
                $intento->update($datos + ['status' => 'duplicate', 'message' => 'El pedido ya estaba pagado']);

                return $this->resultado($intento, $p);
            }
            if ($p->status === 'cancelled') {
                Log::warning("PayPhone: pago aprobado en el pedido cancelado {$p->number} ({$clientTransactionId})");
                $intento->update($datos + ['status' => 'order_closed', 'message' => 'El pedido estaba cancelado']);

                return $this->resultado($intento, $p);
            }

            // El dinero ya se cobró: el pedido se marca pagado aunque el cupo se hubiera vencido
            $intento->update($datos + ['status' => 'approved']);
            $p = $this->gestionar->marcarPagado($p, false, true, $ahora);

            return $this->resultado($intento->refresh(), $p);
        });
    }

    /** @return array{result: string, message: string, order: PedidoModelo} */
    private function resultado(IntentoDePagoModelo $i, PedidoModelo $p): array
    {
        return match ($i->status) {
            'approved' => ['result' => self::APROBADO, 'message' => 'Pago aprobado.', 'order' => $p],
            'declined' => ['result' => self::RECHAZADO, 'message' => 'El pago no se completó. No te cobramos nada; puedes intentarlo de nuevo.', 'order' => $p],
            'amount_mismatch', 'duplicate', 'order_closed' => ['result' => self::REVISION, 'message' => 'Recibimos tu pago, pero necesitamos revisarlo. No pagues otra vez: te escribiremos por WhatsApp.', 'order' => $p],
            default => ['result' => self::ERROR, 'message' => 'Este intento de pago ya venció. Inicia uno nuevo.', 'order' => $p],
        };
    }
}
