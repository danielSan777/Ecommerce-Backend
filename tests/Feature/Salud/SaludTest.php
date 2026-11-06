<?php

declare(strict_types=1);

namespace Tests\Feature\Salud;

use App\Core\Salud\Presentacion\Controladores\ControladorSalud;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class SaludTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_ejecucion_del_scheduler_informa_degradado_con_503(): void
    {
        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('database', true)
            ->assertJsonPath('scheduler_last_run_at', null);
    }

    public function test_con_el_scheduler_al_dia_informa_ok(): void
    {
        Cache::forever(ControladorSalud::CLAVE_SCHEDULER, now('UTC')->subMinutes(2)->toIso8601String());
        Cache::forever(ControladorSalud::CLAVE_COLA, now('UTC')->subMinute()->toIso8601String());

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['status', 'database', 'scheduler_last_run_at', 'queue_last_run_at']);
    }

    public function test_si_el_scheduler_lleva_mas_de_5_minutos_sin_correr_alerta(): void
    {
        Cache::forever(ControladorSalud::CLAVE_SCHEDULER, now('UTC')->subMinutes(6)->toIso8601String());

        $this->getJson('/api/v1/health')->assertStatus(503)->assertJsonPath('status', 'degraded');
    }

    public function test_el_scheduler_tiene_programadas_las_tareas_del_paso_4(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('queue:work')->assertSuccessful();
    }
}
