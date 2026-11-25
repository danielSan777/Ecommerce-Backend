<?php

declare(strict_types=1);

namespace App\Core\Entrega\Dominio;

use App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion;
use App\Core\Entrega\Infraestructura\Modelos\DiaNoHabilModelo;
use App\Shared\Dominio\BusinessCalendar;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Paso 8: dado un instante, devuelve el ciclo de producción, el día de despacho y la ventana de entrega.
 *
 * 1. Ciclo: el primer día habilitado para producir cuyo corte aún no pasó (corte inclusive).
 * 2. Despacho: el segundo día de producción después del ciclo; si ese día no admite despacho, el siguiente que sí.
 * 3. Ventana: los dos primeros días de entrega válidos después del despacho (más los días adicionales de la zona).
 * Domingo: no se produce, no se despacha ni se entrega. Los feriados y cierres vienen de `non_working_days`.
 */
final class MotorDeEntrega
{
    private const LIMITE_DE_DIAS = 90;

    /** @var array<string, array{production: bool, dispatch: bool, delivery: bool}>|null */
    private ?array $bloqueos = null;

    public function __construct(private readonly BusinessCalendar $calendario, private readonly RepositorioConfiguracion $ajustes) {}

    /** @return array{cycle_date: string, cutoff_at: CarbonImmutable} */
    public function ciclo(DateTimeInterface $instante): array
    {
        $fecha = $this->calendario->fechaDeNegocio($instante);
        $ahora = CarbonImmutable::instance($instante);

        for ($i = 0; $i < self::LIMITE_DE_DIAS; $i++, $fecha = $this->calendario->sumarDias($fecha, 1)) {
            if (! $this->seProduce($fecha)) {
                continue;
            }
            $corte = $this->corteDe($fecha);
            if ($corte >= $ahora) {
                return ['cycle_date' => $fecha, 'cutoff_at' => $corte];
            }
        }

        throw new \RuntimeException('No hay un día de producción disponible en los próximos '.self::LIMITE_DE_DIAS.' días.');
    }

    public function despacho(string $ciclo): string
    {
        $fecha = $ciclo;
        for ($produccion = 0; $produccion < 2;) {
            $fecha = $this->calendario->sumarDias($fecha, 1);
            if ($this->seProduce($fecha)) {
                $produccion++;
            }
        }
        while (! $this->seDespacha($fecha)) {
            $fecha = $this->calendario->sumarDias($fecha, 1);
        }

        return $fecha;
    }

    /**
     * @param  int  $diasAdicionales  días que suma la zona (Amazonía: 1) al final de la ventana
     * @return array{start: string, end: string}
     */
    public function ventana(string $despacho, int $diasAdicionales = 0): array
    {
        $inicio = $this->siguienteDeEntrega($despacho);
        $fin = $inicio;
        for ($i = 0; $i < 1 + $diasAdicionales; $i++) {
            $fin = $this->siguienteDeEntrega($fin);
        }

        return ['start' => $inicio, 'end' => $fin];
    }

    /**
     * Una ventana por cada día de entrega desde la primera fecha posible (se solapan: tocar el sábado 5 da sábado 5 + lunes 7,
     * y tocar el lunes 7 da lunes 7 + martes 8). Nunca hay una ventana en el pasado: la primera es la que ya promete el motor.
     *
     * @return list<array{start: string, end: string}>
     */
    public function ventanasDesde(string $primeraVentanaInicio, int $diasAdicionales = 0, int $semanas = 8): array
    {
        $limite = $this->calendario->sumarDias($primeraVentanaInicio, $semanas * 7);
        $ventanas = [];
        $dia = $primeraVentanaInicio;

        while ($dia < $limite) {
            if (! $this->seEntrega($dia)) {
                $dia = $this->calendario->sumarDias($dia, 1);

                continue;
            }
            $fin = $dia;
            for ($i = 0; $i < 1 + $diasAdicionales; $i++) {
                $fin = $this->siguienteDeEntrega($fin);
            }
            $ventanas[] = ['start' => $dia, 'end' => $fin];
            $dia = $this->calendario->sumarDias($dia, 1);
        }

        return $ventanas;
    }

    public function seProduce(string $fecha): bool
    {
        return ! $this->calendario->esDomingo($fecha) && ! ($this->bloqueos()[$fecha]['production'] ?? false);
    }

    public function seDespacha(string $fecha): bool
    {
        return ! $this->calendario->esDomingo($fecha) && ! ($this->bloqueos()[$fecha]['dispatch'] ?? false);
    }

    public function seEntrega(string $fecha): bool
    {
        return ! $this->calendario->esDomingo($fecha) && ! ($this->bloqueos()[$fecha]['delivery'] ?? false);
    }

    private function siguienteDeEntrega(string $fecha): string
    {
        do {
            $fecha = $this->calendario->sumarDias($fecha, 1);
        } while (! $this->seEntrega($fecha));

        return $fecha;
    }

    public function corteDe(string $fecha): CarbonImmutable
    {
        $sabado = $this->calendario->diaDeLaSemana($fecha) === 6;
        $hora = (string) $this->ajustes->obtener($sabado ? 'cutoff_saturday' : 'cutoff_weekday', $sabado ? '16:00' : '20:00');

        return $this->calendario->instanteUtc($fecha, $hora);
    }

    /** @return array<string, array{production: bool, dispatch: bool, delivery: bool}> */
    private function bloqueos(): array
    {
        return $this->bloqueos ??= DiaNoHabilModelo::query()->get()->mapWithKeys(fn ($d) => [
            $d->date->format('Y-m-d') => ['production' => $d->affects_production, 'dispatch' => $d->affects_dispatch, 'delivery' => $d->affects_delivery],
        ])->all();
    }
}
