<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogo;

use App\Core\Catalogo\Infraestructura\Modelos\CampanaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CampanasIniciales;
use Database\Seeders\ProductosDePrueba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdministrarCampanasTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/campaigns';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductosDePrueba::class);
        $this->seed(CampanasIniciales::class);
        CarbonImmutable::setTestNow(new CarbonImmutable('2026-11-08 15:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function admin(): static
    {
        return $this->actingAs(User::factory()->administrador()->create(), 'api');
    }

    /** @return array<string, mixed> */
    private function datos(array $cambios = []): array
    {
        return [...[
            'name' => 'Día de la Madre', 'related_category' => 'familia', 'start_md' => '04-20', 'end_md' => '05-10', 'event_md' => '05-10',
            'tagline' => 'Para mamá, con cariño', 'slogan' => 'Mamá merece flores que duran', 'is_active' => true,
            'seo_title' => null, 'seo_description' => null, 'products' => ['PRO-HOMERO', 'PRO-DOCTOR'],
        ], ...$cambios];
    }

    public function test_solo_el_administrador_accede(): void
    {
        $this->getJson(self::BASE)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'api');
        $this->getJson(self::BASE)->assertForbidden();
        $this->postJson(self::BASE, $this->datos())->assertForbidden();
        $this->putJson(self::BASE.'/san-valentin', ['name' => 'X'])->assertForbidden();
        $this->deleteJson(self::BASE.'/san-valentin')->assertForbidden();
        $this->assertSame(1, CampanaModelo::query()->count());
    }

    public function test_el_listado_trae_todas_con_su_estado_de_hoy_incluso_las_inactivas(): void
    {
        CampanaModelo::query()->where('slug', 'san-valentin')->update(['is_active' => false]);

        $r = $this->admin()->getJson(self::BASE)->assertOk();

        $r->assertJsonPath('data.0.slug', 'san-valentin')->assertJsonPath('data.0.is_active', false)
            ->assertJsonPath('data.0.state', 'upcoming')->assertJsonPath('data.0.season_year', 2027)
            ->assertJsonPath('data.0.related_category.slug', 'amor');
    }

    public function test_crear_genera_la_direccion_del_nombre_y_guarda_los_productos_en_su_orden(): void
    {
        $r = $this->admin()->postJson(self::BASE, $this->datos())->assertCreated();

        $r->assertJsonPath('slug', 'dia-de-la-madre')->assertJsonPath('related_category.name', 'Familia')
            ->assertJsonPath('state', 'upcoming')->assertJsonPath('season_year', 2027);
        $this->assertSame(['PRO-HOMERO', 'PRO-DOCTOR'], array_column($r->json('products'), 'sku'));
        $this->getJson('/api/v1/campaigns/dia-de-la-madre')->assertOk()->assertJsonPath('name', 'Día de la Madre');
    }

    public function test_la_direccion_no_se_puede_elegir_ni_cambiar(): void
    {
        $this->admin()->postJson(self::BASE, $this->datos(['slug' => 'otra']))->assertCreated()->assertJsonPath('slug', 'dia-de-la-madre');
        $this->admin()->putJson(self::BASE.'/dia-de-la-madre', ['name' => 'Mamá', 'slug' => 'nueva'])->assertOk()->assertJsonPath('slug', 'dia-de-la-madre');
        $this->assertDatabaseMissing('campaigns', ['slug' => 'nueva']);
    }

    public function test_crear_valida_en_espanol(): void
    {
        $this->admin()->postJson(self::BASE, $this->datos(['name' => '', 'related_category' => 'nada', 'start_md' => '13-40', 'event_md' => '02-30']))
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Escribe el nombre de la campaña.')
            ->assertJsonPath('errors.related_category.0', 'La categoría elegida no existe.')
            ->assertJsonPath('errors.start_md.0', 'La fecha no es válida. Elige un día y un mes que existan.')
            ->assertJsonPath('errors.event_md.0', 'La fecha no es válida. Elige un día y un mes que existan.');

        $this->admin()->postJson(self::BASE, ['name' => 'Solo nombre'])->assertStatus(422)
            ->assertJsonPath('errors.related_category.0', 'Elige la categoría relacionada.')
            ->assertJsonPath('errors.start_md.0', 'Elige cuándo empieza la temporada.');

        $this->admin()->postJson(self::BASE, $this->datos(['name' => 'San Valentín']))->assertStatus(422)
            ->assertJsonPath('errors.slug.0', 'Ya existe una campaña con un nombre igual o muy parecido.');

        $errores = $this->admin()->postJson(self::BASE, $this->datos(['products' => ['NO-EXISTE']]))->assertStatus(422)->json('errors');
        $this->assertSame('Uno de los productos elegidos no existe.', $errores['products.0'][0]);
        $this->assertSame(1, CampanaModelo::query()->count());
    }

    public function test_se_acepta_el_29_de_febrero_como_fecha_anual(): void
    {
        $this->admin()->postJson(self::BASE, $this->datos(['name' => 'Bisiesto', 'event_md' => '02-29']))->assertCreated()->assertJsonPath('event_md', '02-29');
    }

    public function test_editar_cambia_datos_y_reordena_los_productos(): void
    {
        $this->admin()->postJson(self::BASE, $this->datos())->assertCreated();

        $r = $this->admin()->putJson(self::BASE.'/dia-de-la-madre', [
            'tagline' => 'Nuevo lema', 'is_active' => false, 'related_category' => 'amor', 'products' => ['PRO-DOCTOR', 'PRO-BOB-CORAZON-HOMBRE', 'PRO-HOMERO'],
        ])->assertOk();

        $r->assertJsonPath('tagline', 'Nuevo lema')->assertJsonPath('is_active', false)->assertJsonPath('related_category.slug', 'amor')
            ->assertJsonPath('name', 'Día de la Madre');
        $this->assertSame(['PRO-DOCTOR', 'PRO-BOB-CORAZON-HOMBRE', 'PRO-HOMERO'], array_column($r->json('products'), 'sku'));
        $this->getJson('/api/v1/campaigns/dia-de-la-madre')->assertNotFound(); // inactiva: la tienda ya no la muestra

        $this->admin()->putJson(self::BASE.'/dia-de-la-madre', ['tagline' => 'Sin tocar productos'])->assertOk();
        $this->assertCount(3, $this->admin()->getJson(self::BASE.'/dia-de-la-madre')->json('products'));
        $this->admin()->putJson(self::BASE.'/dia-de-la-madre', ['products' => []])->assertOk();
        $this->assertCount(0, $this->admin()->getJson(self::BASE.'/dia-de-la-madre')->json('products'));
    }

    public function test_los_textos_se_guardan_sin_etiquetas_y_vacios_quedan_nulos(): void
    {
        $r = $this->admin()->putJson(self::BASE.'/san-valentin', ['tagline' => 'Un <b>lema</b>', 'slogan' => '  ', 'seo_title' => '<i>Título</i>'])->assertOk();

        $r->assertJsonPath('tagline', 'Un lema')->assertJsonPath('slogan', null)->assertJsonPath('seo_title', 'Título');
    }

    public function test_la_campana_en_temporada_muestra_los_productos_elegidos_en_la_tienda(): void
    {
        CarbonImmutable::setTestNow(new CarbonImmutable('2027-01-20 15:00:00', 'UTC'));
        $this->admin()->putJson(self::BASE.'/san-valentin', ['products' => ['PRO-HOMERO', 'PRO-DOCTOR']])->assertOk()->assertJsonPath('state', 'active');

        $r = $this->getJson('/api/v1/campaigns/san-valentin')->assertOk();

        $this->assertSame(['homero', 'doctor'], array_column($r->json('products'), 'slug'));
    }

    public function test_eliminar_borra_la_campana_pero_no_los_productos(): void
    {
        $this->admin()->postJson(self::BASE, $this->datos())->assertCreated();
        $productos = ProductoModelo::query()->count();

        $this->admin()->deleteJson(self::BASE.'/dia-de-la-madre')->assertNoContent();

        $this->getJson('/api/v1/campaigns/dia-de-la-madre')->assertNotFound();
        $this->assertSame($productos, ProductoModelo::query()->count());
        $this->admin()->deleteJson(self::BASE.'/dia-de-la-madre')->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_no_se_puede_eliminar_la_categoria_relacionada_de_una_campana(): void
    {
        $this->admin()->postJson(self::BASE, $this->datos(['related_category' => 'familia']))->assertCreated();
        ProductoModelo::query()->where('category_id', \App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo::query()->where('slug', 'familia')->value('id'))->delete();

        $this->admin()->deleteJson('/api/v1/admin/categories/familia')->assertStatus(409)
            ->assertJsonPath('code', 'category_in_campaign')->assertJsonPath('campaigns.0.slug', 'dia-de-la-madre');
        $this->assertDatabaseHas('categories', ['slug' => 'familia', 'deleted_at' => null]);

        $this->admin()->deleteJson(self::BASE.'/dia-de-la-madre')->assertNoContent();
        $this->admin()->deleteJson('/api/v1/admin/categories/familia')->assertNoContent();
    }

    public function test_guarda_el_mensaje_de_cierre_y_su_fecha_limite(): void
    {
        $this->admin()->putJson(self::BASE.'/san-valentin', ['closing_message' => '  San Valentín regresará pronto ', 'closing_until_md' => '03-10'])
            ->assertOk()->assertJsonPath('closing_message', 'San Valentín regresará pronto')->assertJsonPath('closing_until_md', '03-10');
        $this->getJson(self::BASE.'/san-valentin')->assertJsonPath('closing_until_md', '03-10');
    }

    public function test_el_mensaje_de_cierre_exige_fecha_y_la_fecha_debe_caer_despues_del_fin_de_la_temporada(): void
    {
        $this->admin()->putJson(self::BASE.'/san-valentin', ['closing_message' => 'Hasta pronto'])->assertUnprocessable()->assertJsonValidationErrors('closing_until_md');
        $this->putJson(self::BASE.'/san-valentin', ['closing_message' => 'Hasta pronto', 'closing_until_md' => '01-20'])->assertUnprocessable()->assertJsonValidationErrors('closing_until_md'); // dentro de la temporada
        $this->putJson(self::BASE.'/san-valentin', ['closing_message' => str_repeat('a', 161), 'closing_until_md' => '03-10'])->assertUnprocessable()->assertJsonValidationErrors('closing_message');
        $this->putJson(self::BASE.'/san-valentin', ['closing_message' => 'Hasta pronto', 'closing_until_md' => '02-30'])->assertUnprocessable()->assertJsonValidationErrors('closing_until_md');
    }
}
