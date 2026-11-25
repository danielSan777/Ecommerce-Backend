<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Aplicacion\CasosDeUso;

use App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion;
use App\Core\Entrega\Dominio\MotorDeEntrega;
use App\Core\Pedidos\Dominio\PedidoRechazado;
use App\Core\Pedidos\Infraestructura\Modelos\CicloModelo;
use App\Core\Pedidos\Infraestructura\Modelos\EventoDeCicloModelo;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use App\Shared\Dominio\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Paso 8, panel: ver cada ciclo con sus pedidos y su capacidad, cambiar la capacidad de un ciclo puntual y
 * «Pasar las ventas al siguiente ciclo» / «Reabrir ciclo». Todo queda en `cycle_events` con el nombre de quien lo hizo.
 * Los pedidos que un ciclo ya tiene nunca cambian.
 */
final class GestionarCiclos
{
    public const CICLOS_A_MOSTRAR = 14;
    public const CAPACIDAD_MAXIMA = 30;

    public function __construct(
        private readonly MotorDeEntrega $motor,
        private readonly CapacidadDeCiclos $capacidad,
        private readonly RepositorioConfiguracion $ajustes,
        private readonly BusinessCalendar $calendario,
    ) {}

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function listar(?CarbonImmutable $ahora = null): array
    {
        $ahora ??= CarbonImmutable::now('UTC');
        $fecha = $this->motor->ciclo($ahora)['cycle_date'];
        $fechas = [];
        for ($i = 0; $i < self::CICLOS_A_MOSTRAR; $i++) {
            $fechas[] = $fecha;
            $fecha = $this->capacidad->siguienteDiaDeProduccion($fecha);
        }

        $ciclos = CicloModelo::query()->whereIn('date', $fechas)->get()->keyBy(fn ($c) => $c->date->format('Y-m-d'));
        $ultimos = EventoDeCicloModelo::query()->whereIn('cycle_date', $fechas)->orderBy('id')->get()->groupBy(fn ($e) => $e->cycle_date->format('Y-m-d'))
            ->map(fn ($grupo) => $grupo->last());

        $ofrecido = null;
        $filas = [];
        foreach ($fechas as $f) {
            $c = $ciclos->get($f);
            $pagados = $this->pagados($f);
            $transferencias = $this->transferenciasEnPlazo($f, $ahora);
            $tarjetas = $this->tarjetasEnCurso($f, $ahora);
            $comprometidos = $pagados + $transferencias + $tarjetas;
            $capacidad = $this->capacidad->limite($f);
            $cupo = $comprometidos < $capacidad;
            $ofrecido ??= $cupo ? $f : null;
            $despacho = $this->motor->despacho($f);
            $ultimo = $ultimos->get($f);

            $filas[] = [
                'date' => $f,
                'cutoff_at' => $this->motor->corteDe($f)->utc()->toIso8601String(),
                'dispatch_date' => $despacho,
                'window' => $this->motor->ventana($despacho),
                'paid' => $pagados,
                'pending_transfer' => $transferencias,
                'card_in_progress' => $tarjetas,
                'committed' => $comprometidos,
                'capacity' => $capacidad,
                'tolerance_limit' => $this->capacidad->limite($f, true),
                'remaining' => max(0, $capacidad - $comprometidos),
                'closed' => (bool) $c?->closed_manually,
                'capacity_override' => $c?->capacity_override,
                'is_offered' => false,
                'last_event' => $ultimo === null ? null : ['type' => $ultimo->type, 'actor_name' => $ultimo->actor_name, 'at' => $ultimo->created_at->utc()->toIso8601String(), 'data' => $ultimo->data],
            ];
        }
        // El ciclo en el que entra el siguiente pedido ahora mismo: el primero con cupo
        foreach ($filas as &$fila) {
            $fila['is_offered'] = $fila['date'] === $ofrecido;
        }
        unset($fila);

        return ['data' => $filas, 'meta' => [
            'nominal_capacity' => (int) $this->ajustes->obtener('cycle_capacity', 8),
            'tolerance' => (int) $this->ajustes->obtener('cycle_tolerance', 10),
            'max_capacity' => self::CAPACIDAD_MAXIMA,
        ]];
    }

    /** «Pasar las ventas al siguiente ciclo»: nadie nuevo entra a este ciclo; los pedidos que ya tiene no cambian. */
    public function cerrar(string $fecha, ?CarbonImmutable $ahora = null): void
    {
        $this->cambiar($fecha, $ahora, function (CicloModelo $c) use ($fecha) {
            if ($c->closed_manually) {
                throw new PedidoRechazado('cycle_already_closed', 'Ese ciclo ya está cerrado.', 409);
            }
            $c->update(['closed_manually' => true]);

            return ['closed', ['committed' => $this->capacidad->comprometidos($fecha)]];
        });
    }

    /** «Reabrir ciclo»: vuelve a la capacidad configurada (la nominal o la de ese ciclo puntual). */
    public function reabrir(string $fecha, ?CarbonImmutable $ahora = null): void
    {
        $this->cambiar($fecha, $ahora, function (CicloModelo $c) {
            if (! $c->closed_manually) {
                throw new PedidoRechazado('cycle_not_closed', 'Ese ciclo no está cerrado.', 409);
            }
            $c->update(['closed_manually' => false]);

            return ['reopened', []];
        });
    }

    /** Capacidad de un ciclo puntual. `null` devuelve la capacidad nominal de `settings`. */
    public function cambiarCapacidad(string $fecha, ?int $capacidad, ?CarbonImmutable $ahora = null): void
    {
        $this->cambiar($fecha, $ahora, function (CicloModelo $c) use ($capacidad) {
            $antes = $c->capacity_override;
            $c->update(['capacity_override' => $capacidad]);

            return ['capacity_changed', ['from' => $antes, 'to' => $capacidad]];
        });
    }

    /** @param callable(CicloModelo): array{0: string, 1: array<string, mixed>} $accion */
    private function cambiar(string $fecha, ?CarbonImmutable $ahora, callable $accion): void
    {
        $ahora ??= CarbonImmutable::now('UTC');
        if ($fecha < $this->calendario->fechaDeNegocio($ahora) || ! $this->motor->seProduce($fecha)) {
            throw new PedidoRechazado('cycle_not_available', 'Ese día no es un ciclo que se pueda cambiar.', 422);
        }

        DB::transaction(function () use ($fecha, $accion) {
            $ciclo = $this->capacidad->bloquear($fecha);
            [$tipo, $datos] = $accion($ciclo);
            $admin = Auth::guard('api')->user();
            EventoDeCicloModelo::query()->create([
                'cycle_date' => $fecha, 'type' => $tipo,
                'actor_name' => $admin !== null ? mb_substr((string) ($admin->name ?? $admin->email ?? ''), 0, 80) : null,
                'data' => $datos === [] ? null : $datos,
            ]);
        });
    }

    private function pagados(string $fecha): int
    {
        return $this->vivos($fecha)->where('payment_status', 'paid')->count();
    }

    private function transferenciasEnPlazo(string $fecha, CarbonImmutable $ahora): int
    {
        return $this->vivos($fecha)->where('payment_status', 'pending_validation')->where('transfer_deadline_at', '>', $ahora)->count();
    }

    private function tarjetasEnCurso(string $fecha, CarbonImmutable $ahora): int
    {
        return $this->vivos($fecha)->where('payment_status', 'unpaid')->where('card_reserved_until', '>', $ahora)->count();
    }

    private function vivos(string $fecha)
    {
        return PedidoModelo::query()->where('cycle_date', $fecha)->whereNotIn('status', ['cancelled', 'expired']);
    }
}
