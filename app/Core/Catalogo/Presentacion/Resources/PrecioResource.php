<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Resources;

use App\Core\Catalogo\Dominio\ValueObjects\Precio;

/** Forma del contrato "Precio" (Paso 0). */
final class PrecioResource
{
    /** @return array<string, mixed> */
    public static function formato(Precio $precio): array
    {
        return [
            'currency' => 'USD',
            'base_cents' => $precio->baseCents,
            'final_cents' => $precio->finalCents,
            'discount_cents' => $precio->descuentoCents(),
            'discount_percent' => $precio->descuentoPorcentaje(),
            'badge' => $precio->descuentoCents() > 0 ? $precio->descuentoPorcentaje().'% OFF' : null,
            'valid_until' => null,
            'applied_rule_id' => null,
        ];
    }
}
