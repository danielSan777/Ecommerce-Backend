<?php

declare(strict_types=1);

namespace Tests\Feature\Envios;

use App\Core\Envios\Infraestructura\Modelos\CantonModelo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EnviosTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): static
    {
        return $this->actingAs(User::factory()->administrador()->create(), 'api');
    }

    public function test_las_ubicaciones_son_publicas_y_traen_provincias_cantones_y_zonas(): void
    {
        $r = $this->getJson('/api/v1/locations')->assertOk();

        $this->assertCount(24, $r->json('provinces'));
        $this->assertSame(['A', 'B', 'C', 'no_disponible'], array_column($r->json('zones'), 'code'));
        $this->assertSame(350, $r->json('zones.0.price_cents'));
        $this->assertSame(1, $r->json('zones.1.extra_days'));
        $this->assertFalse($r->json('zones.3.is_available'));

        $guayas = collect($r->json('provinces'))->firstWhere('code', '09');
        $guayaquil = collect($guayas['cantons'])->firstWhere('code', '0901');
        $this->assertSame('A', $guayaquil['zone']);
        $aurora = collect($guayas['cantons'])->firstWhere('name', 'La Aurora - Daule');
        $this->assertTrue($aurora['is_locality']);
        $this->assertSame('A', $aurora['zone']);

        $galapagos = collect($r->json('provinces'))->firstWhere('code', '20');
        $this->assertSame('no_disponible', $galapagos['cantons'][0]['zone']);
        $this->assertSame('B', CantonModelo::query()->where('code', '0712')->first()->zona->code);
    }

    public function test_solo_el_administrador_gestiona_los_envios(): void
    {
        $this->getJson('/api/v1/admin/shipping/zones')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'api');
        $this->getJson('/api/v1/admin/shipping/zones')->assertForbidden();
        $this->putJson('/api/v1/admin/shipping/zones/A', ['price_cents' => 1])->assertForbidden();
        $this->putJson('/api/v1/admin/shipping/cantons/zone', ['zone' => 'A', 'cantons' => ['0101']])->assertForbidden();
    }

    public function test_el_administrador_cambia_precio_dias_y_disponibilidad_de_una_zona(): void
    {
        $this->admin();
        $this->getJson('/api/v1/admin/shipping/zones')->assertOk()->assertJsonPath('data.0.code', 'A');

        $this->putJson('/api/v1/admin/shipping/zones/C', ['price_cents' => 550, 'extra_days' => 2])
            ->assertOk()->assertJsonPath('price_cents', 550)->assertJsonPath('extra_days', 2);
        $this->getJson('/api/v1/locations')->assertJsonPath('zones.2.price_cents', 550);

        $this->putJson('/api/v1/admin/shipping/zones/C', ['price_cents' => -1])->assertStatus(422)->assertJsonPath('errors.price_cents.0', 'El precio no puede ser negativo.');
        $this->putJson('/api/v1/admin/shipping/zones/C', ['price_cents' => 5.5])->assertStatus(422);
        $this->putJson('/api/v1/admin/shipping/zones/X', ['price_cents' => 100])->assertNotFound();
    }

    public function test_el_administrador_mueve_cantones_de_zona_y_el_publico_lo_ve(): void
    {
        $this->admin();
        $this->putJson('/api/v1/admin/shipping/cantons/zone', ['zone' => 'A', 'cantons' => ['0101', '0102']])->assertOk()->assertJsonPath('updated', 2);

        $cuenca = collect($this->getJson('/api/v1/locations')->json('provinces.0.cantons'))->firstWhere('code', '0101');
        $this->assertSame('A', $cuenca['zone']);

        $this->putJson('/api/v1/admin/shipping/cantons/zone', ['zone' => 'Z', 'cantons' => ['0101']])->assertStatus(422);
        $this->putJson('/api/v1/admin/shipping/cantons/zone', ['zone' => 'A', 'cantons' => ['9999']])->assertStatus(422);
        $this->putJson('/api/v1/admin/shipping/cantons/zone', ['zone' => 'A', 'cantons' => []])->assertStatus(422);
    }

    public function test_el_listado_de_cantones_filtra_por_provincia_zona_y_texto(): void
    {
        $this->admin();
        $porProvincia = $this->getJson('/api/v1/admin/shipping/cantons?province=20')->assertOk()->json('data');
        $this->assertNotEmpty($porProvincia);
        $this->assertSame(['20'], array_values(array_unique(array_column($porProvincia, 'province_code'))));

        $porTexto = $this->getJson('/api/v1/admin/shipping/cantons?q=aurora')->json('data');
        $this->assertSame('La Aurora - Daule', $porTexto[0]['name']);

        $zonaA = $this->getJson('/api/v1/admin/shipping/cantons?zone=A&province=09')->json('data');
        $this->assertContains('0901', array_column($zonaA, 'code'));
        $this->assertSame(['A'], array_values(array_unique(array_column($zonaA, 'zone'))));
    }
}
