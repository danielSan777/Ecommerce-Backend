<?php

declare(strict_types=1);

namespace App\Core\Pagos\Dominio;

/** Confirm de PayPhone. Siempre se llama desde Laravel; nunca desde el navegador. */
interface PasarelaDePago
{
    /**
     * @return array{ok: bool, status_code: ?int, transaction_id: ?int, client_transaction_id: ?string, authorization_code: ?string, amount_cents: ?int, message: ?string}
     *                                                                                                                                                                       `ok` = PayPhone respondió con una transacción; false = error o sin conexión (el intento sigue pendiente)
     */
    public function confirmar(int $id, string $clientTransactionId): array;

    /** Solo la simulación (desarrollo) devuelve true. */
    public function esSimulada(): bool;
}
