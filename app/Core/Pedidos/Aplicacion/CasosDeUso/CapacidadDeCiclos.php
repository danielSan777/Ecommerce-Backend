<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Aplicacion\CasosDeUso;

use App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion;
use App\Core\Entrega\Dominio\MotorDeEntrega;
use App\Core\Pedidos\Infraestructura\Modelos\CicloModelo;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use App\Shared\Dominio\BusinessCalendar;
use Carbon\CarbonImmutable;

/**
 * Paso 8: capacidad por ciclo. Se cuentan PEDIDOS (no macetas). Comprometidos = pagados + transferencias dentro de su
 * plazo. Se ofrece un ciclo con menos de 8; hasta 10 solo entran los pedidos con gracia o los pagos validados a mano.
 * Debe llamarse dentro de una transacción: `bloquear` toma la fila del ciclo para que dos pedidos simultáneos no ocupen
 * el mismo último cupo.
 */
final class CapacidadDeCiclos
{
    public function __construct(private readonly RepositorioConfiguracion $ajustes, private readonly MotorDeEntrega $motor, private readonly BusinessCalendar $calendario) {}

    public function bloquear(string $fecha): CicloModelo
    {
        CicloModelo::query()->firstOrCreate(['date' => $fecha]);

        return CicloModelo::query()->where('date', $fecha)->lockForUpdate()->firstOrFail();
    }

    public function comprometidos(string $fecha, ?CarbonImmutable $ahora = null): int
    {
        $ahora ??= CarbonImmutable::now('UTC');

        return PedidoModelo::query()->where('cycle_date', $fecha)
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->where(fn ($q) => $q->where('payment_status', 'paid')
                ->orWhere(fn ($t) => $t->where('payment_status', 'pending_validation')->where('transfer_deadline_at', '>', $ahora)))
            ->count();
    }

    public function limite(string $fecha, bool $tolerancia = false): int
    {
        $ciclo = CicloModelo::query()->where('date', $fecha)->first();
        $nominal = (int) $this->ajustes->obtener($tolerancia ? 'cycle_tolerance' : 'cycle_capacity', $tolerancia ? 10 : 8);

        if ($ciclo?->closed_manually) {
            return min($this->comprometidos($fecha), $nominal);   // «Pasar las ventas al siguiente ciclo»: ya no entra nadie
        }

        return $ciclo?->capacity_override !== null ? min($ciclo->capacity_override + ($tolerancia ? 2 : 0), $nominal + 100) : $nominal;
    }

    public function hayCupo(string $fecha, bool $tolerancia = false): bool
    {
        return $this->comprometidos($fecha) < $this->limite($fecha, $tolerancia);
    }

    public function siguienteDiaDeProduccion(string $fecha): string
    {
        do {
            $fecha = $this->calendario->sumarDias($fecha, 1);
        } while (! $this->motor->seProduce($fecha));

        return $fecha;
    }
}
