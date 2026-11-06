<?php

declare(strict_types=1);

namespace App\Core\Salud\Presentacion\Controladores;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Endpoint de salud para el monitor externo (Paso 4): informa la última ejecución del scheduler y de la cola.
 * Responde 503 si el scheduler lleva más de 5 minutos sin correr, para que el monitor alerte.
 */
final class ControladorSalud extends Controller
{
    public const CLAVE_SCHEDULER = 'salud.scheduler.ultima_ejecucion';

    public const CLAVE_COLA = 'salud.cola.ultima_ejecucion';

    public const MINUTOS_MAXIMOS = 5;

    public function __invoke(): JsonResponse
    {
        $scheduler = Cache::get(self::CLAVE_SCHEDULER);
        $cola = Cache::get(self::CLAVE_COLA);

        $baseDeDatos = true;
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $baseDeDatos = false;
        }

        $schedulerAlDia = is_string($scheduler)
            && CarbonImmutable::parse($scheduler)->greaterThan(CarbonImmutable::now('UTC')->subMinutes(self::MINUTOS_MAXIMOS));
        $ok = $baseDeDatos && $schedulerAlDia;

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'database' => $baseDeDatos,
            'scheduler_last_run_at' => $scheduler,
            'queue_last_run_at' => $cola,
        ], $ok ? 200 : 503);
    }
}
