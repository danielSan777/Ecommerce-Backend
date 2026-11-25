<?php

declare(strict_types=1);

namespace Tests\Feature\Entrega;

use App\Core\Entrega\Dominio\MotorDeEntrega;
use App\Core\Entrega\Dominio\TokenDePromesa;
use App\Core\Entrega\Infraestructura\Modelos\DiaNoHabilModelo;
use App\Models\User;
use App\Shared\Dominio\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EntregaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DiaNoHabilModelo::query()->delete(); // los feriados se prueban caso por caso
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{cycle: string, dispatch: string, start: string, end: string} */
    private function plan(string $guayaquil, int $extra = 0): array
    {
        $motor = $this->app->make(MotorDeEntrega::class);
        $instante = $this->app->make(BusinessCalendar::class)->instanteUtc(substr($guayaquil, 0, 10), substr($guayaquil, 11, 5))
            ->addSeconds((int) substr($guayaquil, 17, 2));
        $ciclo = $motor->ciclo($instante)['cycle_date'];
        $despacho = $motor->despacho($ciclo);

        return ['cycle' => $ciclo, 'dispatch' => $despacho] + ['start' => $motor->ventana($despacho, $extra)['start'], 'end' => $motor->ventana($despacho, $extra)['end']];
    }

    // 2026-11-09 es lunes.
    public function test_cada_fila_de_la_tabla_del_documento(): void
    {
        $filas = [
            ['2026-11-09 10:00:00', '2026-11-09', '2026-11-11', '2026-11-12', '2026-11-13'], // lunes → jueves–viernes
            ['2026-11-10 10:00:00', '2026-11-10', '2026-11-12', '2026-11-13', '2026-11-14'], // martes → viernes–sábado
            ['2026-11-11 10:00:00', '2026-11-11', '2026-11-13', '2026-11-14', '2026-11-16'], // miércoles → sábado–lunes
            ['2026-11-12 10:00:00', '2026-11-12', '2026-11-14', '2026-11-16', '2026-11-17'], // jueves → lunes–martes
            ['2026-11-13 10:00:00', '2026-11-13', '2026-11-16', '2026-11-17', '2026-11-18'], // viernes → martes–miércoles
            ['2026-11-14 10:00:00', '2026-11-14', '2026-11-17', '2026-11-18', '2026-11-19'], // sábado → miércoles–jueves
            ['2026-11-14 16:00:01', '2026-11-16', '2026-11-18', '2026-11-19', '2026-11-20'], // sábado tarde → ciclo lunes
            ['2026-11-15 12:00:00', '2026-11-16', '2026-11-18', '2026-11-19', '2026-11-20'], // domingo → ciclo lunes
        ];
        foreach ($filas as [$cuando, $ciclo, $despacho, $inicio, $fin]) {
            $this->assertSame(['cycle' => $ciclo, 'dispatch' => $despacho, 'start' => $inicio, 'end' => $fin], $this->plan($cuando), $cuando);
        }
    }

    public function test_el_corte_es_inclusivo_en_el_segundo_exacto(): void
    {
        $this->assertSame('2026-11-09', $this->plan('2026-11-09 19:59:59')['cycle']);
        $this->assertSame('2026-11-09', $this->plan('2026-11-09 20:00:00')['cycle']);
        $this->assertSame('2026-11-10', $this->plan('2026-11-09 20:00:01')['cycle']);
        $this->assertSame('2026-11-14', $this->plan('2026-11-14 16:00:00')['cycle']);
        $this->assertSame('2026-11-16', $this->plan('2026-11-14 16:00:01')['cycle']);
    }

    public function test_un_feriado_mueve_ciclo_despacho_y_ventana(): void
    {
        DiaNoHabilModelo::query()->create(['date' => '2026-11-10', 'type' => 'national_holiday', 'affects_production' => true, 'affects_dispatch' => true, 'affects_delivery' => true]);

        // Lunes tras el corte: el martes es feriado → ciclo miércoles.
        $this->assertSame('2026-11-11', $this->plan('2026-11-09 20:00:01')['cycle']);
        // Lunes: despacho = segundo día de producción (martes no cuenta) → jueves.
        $this->assertSame('2026-11-12', $this->plan('2026-11-09 10:00:00')['dispatch']);
    }

    public function test_un_cierre_de_tres_dias_sigue_vendiendo_con_la_promesa_movida(): void
    {
        foreach (['2026-11-10', '2026-11-11', '2026-11-12'] as $f) {
            DiaNoHabilModelo::query()->create(['date' => $f, 'type' => 'closure', 'affects_production' => true, 'affects_dispatch' => true, 'affects_delivery' => false]);
        }
        $plan = $this->plan('2026-11-09 20:00:01');

        $this->assertSame('2026-11-13', $plan['cycle']);
        $this->assertSame('2026-11-16', $plan['dispatch']); // sábado producción, lunes producción → lunes
    }

    public function test_la_entrega_salta_domingos_y_feriados_y_la_amazonia_suma_un_dia(): void
    {
        DiaNoHabilModelo::query()->create(['date' => '2026-11-16', 'type' => 'national_holiday', 'affects_production' => false, 'affects_dispatch' => false, 'affects_delivery' => true]);
        // Despacho sábado 14: entrega válida = martes 17 y miércoles 18 (lunes feriado, domingo no).
        $motor = $this->app->make(MotorDeEntrega::class);
        $this->assertSame(['start' => '2026-11-17', 'end' => '2026-11-18'], $motor->ventana('2026-11-14'));
        $this->assertSame(['start' => '2026-11-17', 'end' => '2026-11-19'], $motor->ventana('2026-11-14', 1));
    }

    public function test_el_endpoint_de_promesa_devuelve_el_contrato(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-09 21:18:00', 'UTC')); // 16:18 Guayaquil
        $r = $this->getJson('/api/v1/delivery/promise')->assertOk()->assertHeader('Cache-Control');

        $r->assertJsonPath('server_now', '2026-11-09T21:18:00Z')
            ->assertJsonPath('cycle_date', '2026-11-09')
            ->assertJsonPath('cutoff_at', '2026-11-10T01:00:00Z')
            ->assertJsonPath('dispatch_date', '2026-11-11')
            ->assertJsonPath('window', ['start' => '2026-11-12', 'end' => '2026-11-13'])
            ->assertJsonPath('label', 'Recíbelo entre jueves 12 y viernes 13 de noviembre')
            ->assertJsonPath('countdown_label', 'Pídelo en las próximas 3 h 42 min');
        $this->assertNotEmpty($r->json('promise_token'));
    }

    public function test_el_contador_cambia_de_formato_con_poco_tiempo_y_un_fin_de_semana(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 00:45:00', 'UTC')); // lunes 19:45
        $this->getJson('/api/v1/delivery/promise')->assertJsonPath('countdown_label', 'Pídelo en las próximas 15 min');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-15 17:00:00', 'UTC')); // domingo 12:00
        $this->getJson('/api/v1/delivery/promise')->assertJsonPath('countdown_label', 'Pídelo antes del lunes a las 20:00');
    }

    public function test_el_cantón_amazonico_da_una_ventana_de_tres_dias_y_galapagos_no_tiene_promesa(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-09 21:18:00', 'UTC'));

        $this->getJson('/api/v1/delivery/promise?canton=1501')->assertOk()
            ->assertJsonPath('window', ['start' => '2026-11-12', 'end' => '2026-11-14'])
            ->assertJsonPath('label', 'Recíbelo entre jueves 12 y sábado 14 de noviembre');
        $this->getJson('/api/v1/delivery/promise?canton=0901')->assertOk()->assertJsonPath('window.end', '2026-11-13');
        $this->getJson('/api/v1/delivery/promise?canton=2001')->assertStatus(422);
    }

    public function test_las_ventanas_empiezan_en_la_promesa_nunca_en_el_pasado_y_saltan_domingos_y_feriados(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-09 21:18:00', 'UTC'));
        DiaNoHabilModelo::query()->create(['date' => '2026-12-25', 'type' => 'national_holiday', 'affects_production' => true, 'affects_dispatch' => true, 'affects_delivery' => true]);

        $ventanas = $this->getJson('/api/v1/delivery/windows')->assertOk()->assertHeader('Cache-Control')->json('windows');

        $this->assertStringContainsString('no-store', $this->getJson('/api/v1/delivery/windows')->headers->get('Cache-Control'));
        $this->assertSame(['start' => '2026-11-12', 'end' => '2026-11-13', 'available' => true], $ventanas[0]);
        $porInicio = collect($ventanas)->keyBy('start');
        // Cada día de entrega tiene su propia ventana (se solapan): día + siguiente día válido
        $this->assertSame('2026-11-14', $porInicio['2026-11-13']['end']);   // viernes + sábado
        $this->assertSame('2026-11-16', $porInicio['2026-11-14']['end']);   // sábado + lunes (salta el domingo)
        $this->assertSame('2026-11-17', $porInicio['2026-11-16']['end']);   // lunes + martes
        $this->assertFalse($porInicio->has('2026-11-15'));                   // domingo: sin ventana
        $this->assertSame('2026-12-26', $porInicio['2026-12-24']['end']);   // salta el feriado del 25
        $this->assertFalse($porInicio->has('2026-12-25'));
        foreach ($ventanas as $v) {
            $this->assertGreaterThanOrEqual('2026-11-12', $v['start']);
            foreach ([$v['start'], $v['end']] as $dia) {
                $this->assertNotSame('0', date('w', strtotime($dia)), "$dia es domingo");
                $this->assertNotSame('2026-12-25', $dia);
            }
        }
        $this->assertGreaterThan(20, count($ventanas));
    }

    public function test_el_token_de_promesa_detecta_la_alteracion(): void
    {
        $tokens = new TokenDePromesa;
        $emitido = CarbonImmutable::parse('2026-11-09 19:50:00', 'UTC');
        $token = $tokens->emitir('2026-11-09', $emitido);

        $this->assertSame('2026-11-09', $tokens->verificar($token)['cycle_date']);
        $this->assertSame($emitido->getTimestamp(), $tokens->verificar($token)['issued_at']->getTimestamp());

        [$carga, $firma] = explode('.', $token);
        $alterada = rtrim(strtr(base64_encode(json_encode(['c' => '2026-11-12', 'i' => $emitido->getTimestamp()])), '+/', '-_'), '=');
        $this->assertNull($tokens->verificar($alterada.'.'.$firma));
        $this->assertNull($tokens->verificar('basura'));
    }

    public function test_solo_el_administrador_gestiona_los_dias_no_habiles(): void
    {
        $this->getJson('/api/v1/admin/non-working-days')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'api');
        $this->postJson('/api/v1/admin/non-working-days', ['date' => '2026-12-31', 'type' => 'closure'])->assertForbidden();

        $this->actingAs(User::factory()->administrador()->create(), 'api');
        $this->postJson('/api/v1/admin/non-working-days', ['date' => '2026-12-31', 'type' => 'closure', 'affects_delivery' => false, 'note' => 'Cierre de año'])
            ->assertCreated()->assertJsonPath('affects_delivery', false);
        $this->getJson('/api/v1/admin/non-working-days')->assertOk()->assertJsonPath('data.0.date', '2026-12-31');
        $this->postJson('/api/v1/admin/non-working-days', ['date' => '31/12/2026', 'type' => 'closure'])->assertStatus(422);
        $this->postJson('/api/v1/admin/non-working-days', ['date' => '2026-12-31', 'type' => 'otro'])->assertStatus(422);
        $this->deleteJson('/api/v1/admin/non-working-days/2026-12-31')->assertNoContent();
        $this->deleteJson('/api/v1/admin/non-working-days/2026-12-31')->assertNotFound();
    }
}
