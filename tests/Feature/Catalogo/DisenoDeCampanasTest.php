<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogo;

use App\Core\Catalogo\Infraestructura\Modelos\CampanaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\MedioModelo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CampanasIniciales;
use Database\Seeders\ProductosDePrueba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Campaña vigente (banner de la página principal) y diseño propio de cada campaña: color, emoji y portada. */
final class DisenoDeCampanasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductosDePrueba::class);
        $this->seed(CampanasIniciales::class);
        Storage::fake('media');
        Storage::fake('private');
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

    private function admin(): static
    {
        return $this->actingAs(User::factory()->administrador()->create(), 'api');
    }

    private function imagen(): UploadedFile
    {
        $gd = imagecreatetruecolor(1500, 1000);
        imagefill($gd, 0, 0, (int) imagecolorallocate($gd, 200, 30, 60));
        $ruta = tempnam(sys_get_temp_dir(), 'img');
        imagepng($gd, $ruta);
        imagedestroy($gd);

        return new UploadedFile($ruta, 'portada.png', null, null, true);
    }

    /** @return array<string, mixed> */
    private function navidad(array $cambios = []): array
    {
        return [...[
            'name' => 'Navidad', 'start_md' => '11-15', 'end_md' => '12-24', 'event_md' => '12-25',
            'tagline' => 'Un regalo que crece', 'slogan' => null, 'theme_color' => '#1B5E20', 'emoji' => '🎄', 'is_active' => true, 'products' => [],
        ], ...$cambios];
    }

    public function test_sin_campana_en_temporada_la_vigente_es_null_y_responde_200(): void
    {
        $this->hoyEs('2027-05-20 15:00:00');

        $this->getJson('/api/v1/campaigns/current')->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_la_vigente_es_la_que_esta_en_temporada_hoy_con_su_diseno(): void
    {
        $this->admin()->postJson('/api/v1/admin/campaigns', $this->navidad())->assertCreated();
        $this->hoyEs('2026-11-20 15:00:00');

        $r = $this->getJson('/api/v1/campaigns/current')->assertOk();

        $r->assertJsonPath('data.slug', 'navidad')->assertJsonPath('data.url', '/navidad')->assertJsonPath('data.season_year', 2026)
            ->assertJsonPath('data.event_date', '2026-12-25')->assertJsonPath('data.theme.color', '#1B5E20')->assertJsonPath('data.theme.emoji', '🎄')
            ->assertJsonPath('data.theme.cover', null);
    }

    public function test_si_hay_dos_en_temporada_gana_la_del_evento_mas_proximo(): void
    {
        $this->admin()->postJson('/api/v1/admin/campaigns', $this->navidad(['name' => 'Fiestas largas', 'start_md' => '01-02', 'end_md' => '12-30', 'event_md' => '12-31']))->assertCreated();
        $this->admin()->postJson('/api/v1/admin/campaigns', $this->navidad())->assertCreated();
        $this->hoyEs('2026-11-20 15:00:00');

        $this->getJson('/api/v1/campaigns/current')->assertJsonPath('data.slug', 'navidad');
    }

    public function test_una_campana_inactiva_no_es_la_vigente(): void
    {
        $this->admin()->postJson('/api/v1/admin/campaigns', $this->navidad(['is_active' => false]))->assertCreated();
        $this->hoyEs('2026-11-20 15:00:00');

        $this->getJson('/api/v1/campaigns/current')->assertJsonPath('data', null);
    }

    public function test_la_pagina_de_la_campana_incluye_su_diseno(): void
    {
        $this->admin()->postJson('/api/v1/admin/campaigns', $this->navidad())->assertCreated();

        $this->getJson('/api/v1/campaigns/navidad')->assertOk()->assertJsonPath('theme.color', '#1B5E20')->assertJsonPath('theme.emoji', '🎄');
    }

    public function test_el_color_debe_ser_hexadecimal_y_el_emoji_no_lleva_etiquetas(): void
    {
        $this->admin()->postJson('/api/v1/admin/campaigns', $this->navidad(['theme_color' => 'rojo']))
            ->assertStatus(422)->assertJsonPath('errors.theme_color.0', 'El color debe ser como #C2185B (almohadilla y 6 letras o números).');

        $r = $this->admin()->postJson('/api/v1/admin/campaigns', $this->navidad(['emoji' => '<b>🎄</b>']))->assertCreated();
        $this->assertSame('🎄', $r->json('emoji'));
    }

    public function test_el_diseno_se_puede_quitar_y_vuelve_al_de_siempre(): void
    {
        $this->admin()->postJson('/api/v1/admin/campaigns', $this->navidad())->assertCreated();
        $this->admin()->putJson('/api/v1/admin/campaigns/navidad', ['theme_color' => null, 'emoji' => '  '])->assertOk()
            ->assertJsonPath('theme_color', null)->assertJsonPath('emoji', null);
    }

    public function test_la_portada_es_la_primera_imagen_de_la_campana_y_se_borra_con_ella(): void
    {
        $this->admin()->postJson('/api/v1/admin/campaigns', $this->navidad())->assertCreated();

        $subida = $this->admin()->post('/api/v1/admin/campaigns/navidad/media', ['image' => $this->imagen(), 'role' => 'hero', 'alt' => 'Pino navideño'], ['Accept' => 'application/json'])
            ->assertCreated()->json();
        $medio = MedioModelo::query()->findOrFail($subida['id']);
        $this->assertSame(CampanaModelo::query()->where('slug', 'navidad')->value('id'), $medio->campaign_id);
        $this->assertStringStartsWith('campaigns/navidad/', $medio->variants['600']);

        $this->admin()->getJson('/api/v1/admin/campaigns/navidad/media')->assertOk()->assertJsonCount(1, 'data');
        $this->admin()->getJson('/api/v1/admin/campaigns/navidad')->assertJsonPath('cover_url', $subida['urls']['600']);
        $this->getJson('/api/v1/campaigns/navidad')->assertJsonPath('theme.cover.alt', 'Pino navideño')->assertJsonPath('theme.cover.srcset.600', $subida['urls']['600']);

        $this->admin()->deleteJson('/api/v1/admin/campaigns/navidad')->assertNoContent();
        $this->assertSame(0, MedioModelo::query()->count());
    }

    public function test_solo_el_administrador_sube_la_portada(): void
    {
        $this->admin()->postJson('/api/v1/admin/campaigns', $this->navidad())->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/admin/campaigns/navidad/media', ['alt' => 'x', 'image' => $this->imagen()])->assertUnauthorized();
    }

    public function test_subir_portada_a_una_campana_que_no_existe_responde_404(): void
    {
        $this->admin()->post('/api/v1/admin/campaigns/no-existe/media', ['image' => $this->imagen(), 'alt' => 'x'], ['Accept' => 'application/json'])->assertNotFound();
    }
}
