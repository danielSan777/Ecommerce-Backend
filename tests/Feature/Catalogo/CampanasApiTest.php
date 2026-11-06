<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogo;

use App\Core\Catalogo\Infraestructura\Modelos\CampanaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use Carbon\CarbonImmutable;
use Database\Seeders\CampanasIniciales;
use Database\Seeders\ProductosDePrueba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CampanasApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductosDePrueba::class);
        $this->seed(CampanasIniciales::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function hoyEs(string $fechaUtc): void
    {
        CarbonImmutable::setTestNow(new CarbonImmutable($fechaUtc, 'UTC'));
    }

    private function elegir(string ...$slugs): void
    {
        $campana = CampanaModelo::query()->where('slug', 'san-valentin')->firstOrFail();
        foreach ($slugs as $orden => $slug) {
            $campana->productos()->attach(ProductoModelo::query()->where('slug', $slug)->value('id'), ['sort_order' => $orden]);
        }
    }

    public function test_en_temporada_muestra_los_productos_elegidos_en_su_orden(): void
    {
        $this->hoyEs('2027-01-20 15:00:00');
        $this->elegir('homero', 'bob-corazon-hombre', 'doctor');

        $r = $this->getJson('/api/v1/campaigns/san-valentin')->assertOk();

        $r->assertJsonPath('state', 'active')->assertJsonPath('season_year', 2027)->assertJsonPath('event_date', '2027-02-14')
            ->assertJsonPath('related_category.url', '/amor')->assertJsonPath('tagline', 'Un regalo con su nombre que sigue creciendo');
        $this->assertSame(['homero', 'bob-corazon-hombre', 'doctor'], array_column($r->json('products'), 'slug'));
    }

    public function test_fuera_de_temporada_responde_200_con_el_anio_siguiente_y_los_productos_de_amor(): void
    {
        $this->hoyEs('2027-03-10 15:00:00');
        $this->elegir('homero');

        $r = $this->getJson('/api/v1/campaigns/san-valentin')->assertOk();

        $r->assertJsonPath('state', 'upcoming')->assertJsonPath('season_year', 2028)->assertJsonPath('event_date', '2028-02-14');
        $this->assertSame(['bob-corazon-hombre'], array_column($r->json('products'), 'slug'));
    }

    public function test_la_fecha_de_negocio_es_la_de_guayaquil_no_la_de_utc(): void
    {
        // 2027-02-15 03:00 UTC = 14 de febrero 22:00 en Guayaquil: todavía es el último día de la temporada
        $this->hoyEs('2027-02-15 03:00:00');

        $this->getJson('/api/v1/campaigns/san-valentin')->assertJsonPath('state', 'active');
    }

    public function test_la_respuesta_trae_la_forma_del_contrato(): void
    {
        $this->hoyEs('2027-01-20 15:00:00');

        $this->getJson('/api/v1/campaigns/san-valentin')->assertJsonStructure([
            'slug', 'name', 'state', 'season_year', 'event_date', 'order_deadline', 'tagline', 'slogan',
            'related_category' => ['slug', 'url'], 'products', 'seo' => ['title', 'description', 'canonical', 'og_image'],
        ]);
    }

    public function test_una_campana_que_no_existe_responde_404_en_ingles(): void
    {
        $this->getJson('/api/v1/campaigns/navidad')->assertNotFound()->assertExactJson(['code' => 'not_found', 'message' => "La campaña 'navidad' no existe."]);
    }

    public function test_una_campana_inactiva_no_responde(): void
    {
        CampanaModelo::query()->where('slug', 'san-valentin')->update(['is_active' => false]);

        $this->getJson('/api/v1/campaigns/san-valentin')->assertNotFound();
    }
}
