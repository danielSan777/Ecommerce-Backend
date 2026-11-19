<?php

declare(strict_types=1);

namespace App\Core\Pagos\Infraestructura;

use App\Core\Pagos\Dominio\PasarelaDePago;
use App\Core\Pagos\Infraestructura\Modelos\IntentoDePagoModelo;

/**
 * Solo desarrollo: reemplaza a PayPhone mientras no hay credenciales. `id` 1 = cancelado; cualquier otro = aprobado
 * (con ese número como transacción). Nunca se usa en producción.
 */
final class PasarelaSimulada implements PasarelaDePago
{
    public function confirmar(int $id, string $clientTransactionId): array
    {
        $intento = IntentoDePagoModelo::query()->where('client_transaction_id', $clientTransactionId)->first();
        if ($intento === null) {
            return ['ok' => false, 'status_code' => null, 'transaction_id' => null, 'client_transaction_id' => null, 'authorization_code' => null, 'amount_cents' => null, 'message' => 'La transacción no existe.'];
        }
        $aprobado = $id !== 1;

        return [
            'ok' => true, 'status_code' => $aprobado ? 3 : 2, 'transaction_id' => $id, 'client_transaction_id' => $clientTransactionId,
            'authorization_code' => $aprobado ? 'SIM'.$id : null, 'amount_cents' => $intento->expected_amount_cents, 'message' => null,
        ];
    }

    public function esSimulada(): bool
    {
        return true;
    }
}
