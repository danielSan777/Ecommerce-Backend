<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\ValueObjects;

use InvalidArgumentException;

/** Precio en centavos enteros, calculado por Laravel (Angular solo le da formato). */
final readonly class Precio
{
    public function __construct(public int $baseCents, public int $finalCents)
    {
        if ($baseCents < 0 || $finalCents < 0 || $finalCents > $baseCents) {
            throw new InvalidArgumentException('Precio inválido.');
        }
    }

    /** Hasta que exista el módulo Precios (Paso 7) no hay promociones: el precio final es el base. */
    public static function sinDescuento(int $baseCents): self
    {
        return new self($baseCents, $baseCents);
    }

    /**
     * Descuento en porcentaje (hasta dos decimales) sobre el precio base, redondeado al centavo (mitad hacia arriba).
     * Todo en enteros: nada de decimales flotantes con dinero.
     */
    public static function conDescuentoPorcentaje(int $baseCents, float|string $porcentaje): self
    {
        $centesimas = (int) round(((float) $porcentaje) * 100); // 12.5 % → 1250
        $descuento = intdiv($baseCents * $centesimas + 5000, 10000);

        return new self($baseCents, $baseCents - min($descuento, $baseCents));
    }

    public function descuentoCents(): int
    {
        return $this->baseCents - $this->finalCents;
    }

    public function descuentoPorcentaje(): int
    {
        return $this->baseCents === 0 ? 0 : (int) round($this->descuentoCents() * 100 / $this->baseCents);
    }
}
