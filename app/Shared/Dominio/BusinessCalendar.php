<?php

declare(strict_types=1);

namespace App\Shared\Dominio;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Único servicio para todo cálculo de fechas de negocio (documento maestro, Paso 4).
 *
 * Los instantes viajan y se guardan en UTC; las fechas de negocio ("el día del pedido", "el día de entrega")
 * son DATE de America/Guayaquil. Nada en el sistema usa la hora del navegador.
 */
final class BusinessCalendar
{
    public const ZONA = 'America/Guayaquil';

    /** Fecha de negocio (Y-m-d, Guayaquil) a la que pertenece un instante. */
    public function fechaDeNegocio(DateTimeInterface $instante): string
    {
        return CarbonImmutable::instance($instante)->setTimezone(self::ZONA)->format('Y-m-d');
    }

    /** Fecha de negocio de hoy. */
    public function hoy(): string
    {
        return $this->fechaDeNegocio(CarbonImmutable::now('UTC'));
    }

    /** Instante UTC de una hora de pared de Guayaquil ("2026-11-10 20:00" → 2026-11-11 01:00 UTC). */
    public function instanteUtc(string $fecha, string $hora = '00:00'): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', "{$fecha} {$hora}", self::ZONA)->setTimezone('UTC');
    }

    /** Día de la semana ISO (1 = lunes … 7 = domingo) de una fecha de negocio. */
    public function diaDeLaSemana(string $fecha): int
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $fecha, self::ZONA)->dayOfWeekIso;
    }

    public function esDomingo(string $fecha): bool
    {
        return $this->diaDeLaSemana($fecha) === 7;
    }

    /** Suma días de calendario a una fecha de negocio. */
    public function sumarDias(string $fecha, int $dias): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $fecha, self::ZONA)->addDays($dias)->format('Y-m-d');
    }
}
