<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Servicios;

/**
 * Decide si una campaña anual está en temporada y de qué año es la temporada que se muestra.
 * Fechas recurrentes en formato MM-DD; `$hoy` es una fecha de negocio (Y-m-d de Guayaquil).
 *
 * - En temporada (inicio ≤ hoy ≤ fin): `active`, año de la temporada en curso.
 * - Fuera de temporada: `upcoming` con el año de la PRÓXIMA temporada (en marzo de 2027, San Valentín muestra 2028).
 * - Despedida: si la campaña tiene mensaje de cierre, desde el día siguiente al fin hasta `cierraHastaMd` el estado es `closing`
 *   (se muestra el mensaje); pasada esa fecha vuelve a `upcoming` y no se muestra nada hasta la próxima temporada.
 * Soporta temporadas que cruzan el año (por ejemplo Navidad: 15-11 a 05-01).
 */
final class TemporadaDeCampana
{
    /** @return array{estado: string, anio: int, fechaEvento: string} */
    public function calcular(string $inicioMd, string $finMd, string $eventoMd, string $hoy, ?string $cierraHastaMd = null): array
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

        $estado = match (true) {
            $activa => 'active',
            $cierraHastaMd !== null && $this->enDespedida($finMd, $cierraHastaMd, $mdHoy) => 'closing',
            default => 'upcoming',
        };

        return ['estado' => $estado, 'anio' => $anio, 'fechaEvento' => sprintf('%d-%s', $anio, $eventoMd)];
    }

    /** ¿Es válida la fecha límite del mensaje? Debe caer después del fin de la temporada y antes de que empiece la siguiente. */
    public function despedidaValida(string $inicioMd, string $finMd, string $hastaMd): bool
    {
        $hasta = $this->diasDesde($finMd, $hastaMd);

        // Si la temporada dura un solo día (inicio = fin), la siguiente empieza dentro de un año completo.
        $siguiente = $this->diasDesde($finMd, $inicioMd) ?: 366;

        return $hasta >= 1 && $hasta < $siguiente;
    }

    /** Entre el día siguiente al fin y la fecha límite (inclusive); funciona también cuando el rango cruza el año. */
    private function enDespedida(string $finMd, string $hastaMd, string $mdHoy): bool
    {
        $hasta = $this->diasDesde($finMd, $hastaMd);
        $hoy = $this->diasDesde($finMd, $mdHoy);

        return $hasta >= 1 && $hoy >= 1 && $hoy <= $hasta;
    }

    /** Días que hay desde `$desdeMd` hasta `$hastaMd` (0 a 365), contando de forma circular a lo largo del año. */
    private function diasDesde(string $desdeMd, string $hastaMd): int
    {
        $dia = fn (string $md): int => (int) date('z', (int) strtotime("2028-{$md}")); // 2028 es bisiesto: existe el 29 de febrero

        return ($dia($hastaMd) - $dia($desdeMd) + 366) % 366;
    }
}
