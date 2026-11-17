<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Dominio;

use RuntimeException;

/** El pedido no se puede crear o cambiar: código estable (`price_changed`, `capacity_full`…), mensaje en español y detalle. */
final class PedidoRechazado extends RuntimeException
{
    /** @param array<string, mixed> $detalle */
    public function __construct(public readonly string $codigo, string $mensaje, public readonly int $estado = 422, public readonly array $detalle = [])
    {
        parent::__construct($mensaje);
    }
}
