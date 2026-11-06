<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Servicios;

/**
 * Decide si una campaña anual está en temporada y de qué año es la temporada que se muestra.
 * Fechas recurrentes en formato MM-DD; `$hoy` es una fecha de negocio (Y-m-d de Guayaquil).
 *
 * - En temporada (inicio ≤ hoy ≤ fin): `active`, año de la temporada en curso.
 * - Fuera de temporada: `upcoming` con el año de la PRÓXIMA temporada (en marzo de 2027, San Valentín muestra 2028).
 * Soporta temporadas que cruzan el año (por ejemplo Navidad: 15-11 a 05-01).
 */
final class TemporadaDeCampana
{
    /** @return array{estado: string, anio: int, fechaEvento: string} */
    public function calcular(string $inicioMd, string $finMd, string $eventoMd, string $hoy): array
    {
        $anioHoy = (int) substr($hoy, 0, 4);
        $mdHoy = substr($hoy, 5);
        $cruzaAnio = $inicioMd > $finMd;

        if (! $cruzaAnio) {
            $activa = $mdHoy >= $inicioMd && $mdHoy <= $finMd;
            $anio = $activa || $mdHoy < $inicioMd ? $anioHoy : $anioHoy + 1;
        } else {
            $enDiciembre = $mdHoy >= $inicioMd;
            $enEnero = $mdHoy <= $finMd;
            $activa = $enDiciembre || $enEnero;
            // La temporada se identifica por el año en que cae el evento
            $eventoEnAnioSiguiente = $eventoMd <= $finMd;
            $anio = match (true) {
                $enDiciembre => $eventoEnAnioSiguiente ? $anioHoy + 1 : $anioHoy,
                $enEnero => $eventoEnAnioSiguiente ? $anioHoy : $anioHoy - 1,
                default => $eventoEnAnioSiguiente ? $anioHoy + 1 : $anioHoy,
            };
        }

        return ['estado' => $activa ? 'active' : 'upcoming', 'anio' => $anio, 'fechaEvento' => sprintf('%d-%s', $anio, $eventoMd)];
    }
}
