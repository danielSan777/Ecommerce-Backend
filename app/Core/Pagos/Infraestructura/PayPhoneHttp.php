<?php

declare(strict_types=1);

namespace App\Core\Pagos\Infraestructura;

use App\Core\Pagos\Dominio\PasarelaDePago;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** POST https://paymentbox.payphonetodoesposible.com/api/confirm con `Authorization: Bearer {token}`. */
final class PayPhoneHttp implements PasarelaDePago
{
    public function __construct(private readonly string $url, private readonly string $token) {}

    public function confirmar(int $id, string $clientTransactionId): array
    {
        $vacio = ['ok' => false, 'status_code' => null, 'transaction_id' => null, 'client_transaction_id' => null, 'authorization_code' => null, 'amount_cents' => null, 'message' => null];

        try {
            $r = Http::withToken($this->token)->acceptJson()->asJson()->timeout(15)
                ->post($this->url, ['id' => $id, 'clientTxId' => $clientTransactionId]);
        } catch (ConnectionException $e) {
            Log::warning("PayPhone Confirm sin conexión ({$clientTransactionId})");

            return ['message' => 'No pudimos comunicarnos con PayPhone.'] + $vacio;
        }

        $j = $r->json();
        if (! is_array($j) || isset($j['errorCode']) || ! isset($j['statusCode'])) {
            Log::warning("PayPhone Confirm rechazó {$clientTransactionId}: HTTP {$r->status()} ".json_encode($j['errorCode'] ?? null));

            return ['message' => is_array($j) ? (string) ($j['message'] ?? 'PayPhone no reconoció la transacción.') : 'Respuesta inválida de PayPhone.'] + $vacio;
        }

        return [
            'ok' => true,
            'status_code' => (int) $j['statusCode'],
            'transaction_id' => isset($j['transactionId']) ? (int) $j['transactionId'] : null,
            'client_transaction_id' => isset($j['clientTransactionId']) ? (string) $j['clientTransactionId'] : null,
            'authorization_code' => isset($j['authorizationCode']) ? (string) $j['authorizationCode'] : null,
            'amount_cents' => isset($j['amount']) ? (int) $j['amount'] : null,
            'message' => isset($j['message']) ? (string) $j['message'] : null,
        ];
    }

    public function esSimulada(): bool
    {
        return false;
    }
}
