<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogo;

use App\Core\Catalogo\Infraestructura\Modelos\BusquedaRegistradaModelo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RegistroDeBusquedasTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_la_tienda_registra_el_termino_normalizado_y_la_cantidad_de_resultados(): void
    {
        $this->postJson('/api/v1/search-log', ['term' => '  Médico   <b>FISIO</b> ', 'results_count' => 0])->assertCreated();

        $fila = BusquedaRegistradaModelo::query()->firstOrFail();
        $this->assertSame('medico fisio', $fila->term);
        $this->assertSame(0, $fila->results_count);
    }

    public function test_un_termino_de_una_letra_no_se_guarda_y_los_datos_invalidos_se_rechazan(): void
    {
        $this->postJson('/api/v1/search-log', ['term' => 'a', 'results_count' => 0])->assertCreated();
        $this->assertSame(0, BusquedaRegistradaModelo::query()->count());

        $this->postJson('/api/v1/search-log', ['results_count' => 0])->assertStatus(422)->assertJsonValidationErrors(['term']);
        $this->postJson('/api/v1/search-log', ['term' => 'maceta', 'results_count' => -1])->assertStatus(422)->assertJsonValidationErrors(['results_count']);
        $this->postJson('/api/v1/search-log', ['term' => str_repeat('x', 300), 'results_count' => 0])->assertStatus(422);
    }

    public function test_el_panel_lista_las_busquedas_sin_resultados_de_30_dias_de_mas_a_menos_veces(): void
    {
        CarbonImmutable::setTestNow(new CarbonImmutable('2026-11-26 15:00:00', 'UTC'));
        foreach ([['galapagos', 0, '2026-11-20'], ['galapagos', 0, '2026-11-25'], ['galapagos', 0, '2026-11-26'], ['unicornio', 0, '2026-11-10'], ['medico', 4, '2026-11-24'], ['antigua', 0, '2026-10-01']] as [$t, $n, $f]) {
            $m = new BusquedaRegistradaModelo();
            $m->forceFill(['term' => $t, 'results_count' => $n, 'created_at' => $f.' 12:00:00'])->save();
        }

        $this->actingAs(User::factory()->administrador()->create(), 'api');
        $r = $this->getJson('/api/v1/admin/searches/no-results')->assertOk()->assertJsonPath('meta.days', 30);

        $this->assertSame(['galapagos', 'unicornio'], array_column($r->json('data'), 'term'));   // «medico» sí dio resultados y «antigua» pasó de 30 días
        $this->assertSame(3, $r->json('data.0.times'));
        $this->assertSame('2026-11-26T12:00:00Z', $r->json('data.0.last_at'));
        $this->getJson('/api/v1/admin/searches/no-results?days=90')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_solo_el_administrador_ve_la_lista(): void
    {
        $this->getJson('/api/v1/admin/searches/no-results')->assertStatus(401);
        $this->actingAs(User::factory()->create(), 'api')->getJson('/api/v1/admin/searches/no-results')->assertStatus(403);
    }
}
