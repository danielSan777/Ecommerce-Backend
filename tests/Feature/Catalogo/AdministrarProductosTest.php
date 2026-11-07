<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogo;

use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use App\Models\User;
use Database\Seeders\ProductosDePrueba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdministrarProductosTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/products';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductosDePrueba::class);
    }

    private function admin(): static
    {
        return $this->actingAs(User::factory()->administrador()->create(), 'api');
    }

    /** @return array<string, mixed> */
    private function datos(array $cambios = []): array
    {
        return [...[
            'name' => 'Abuela jardinera', 'category' => 'familia', 'base_price_cents' => 2350, 'cost_cents' => 900,
            'size_text' => '12 cm', 'material_text' => 'Cerámica', 'includes' => ['Plantita natural', 'Funda kraft'],
            'description_html' => '<p>Una maceta para abuela.</p>', 'is_available' => true, 'is_active' => true,
        ], ...$cambios];
    }

    public function test_solo_el_administrador_accede(): void
    {
        $this->getJson(self::BASE)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'api');
        $this->getJson(self::BASE)->assertForbidden();
        $this->postJson(self::BASE, $this->datos())->assertForbidden();
        $this->putJson(self::BASE.'/PRO-HOMERO', ['name' => 'X'])->assertForbidden();
        $this->deleteJson(self::BASE.'/PRO-HOMERO')->assertForbidden();
        $this->assertNotNull(ProductoModelo::query()->where('sku', 'PRO-HOMERO')->first());
    }

    public function test_lista_todos_incluidos_los_inactivos_y_filtra_por_categoria_y_texto(): void
    {
        ProductoModelo::query()->where('slug', 'homero')->update(['is_active' => false]);

        $todos = $this->admin()->getJson(self::BASE)->assertOk()->json('data');
        $this->assertCount(10, $todos);
        $this->assertContains('homero', array_column($todos, 'slug'));
        $this->assertArrayHasKey('cost_cents', $todos[0]);

        $this->assertSame(['homero'], array_column($this->getJson(self::BASE.'?category=familia')->json('data'), 'slug'));
        $this->assertEqualsCanonicalizing(['doctor'], array_column($this->getJson(self::BASE.'?q=doct')->json('data'), 'slug'));
        $this->assertSame(['homero'], array_column($this->getJson(self::BASE.'?q=PRO-HOMERO')->json('data'), 'slug'));
    }

    public function test_ver_un_producto_trae_todos_sus_campos_y_uno_inexistente_responde_404(): void
    {
        $this->admin()->getJson(self::BASE.'/PRO-DOCTOR')->assertOk()->assertJsonPath('slug', 'doctor')->assertJsonPath('category_slug', 'profesiones-y-hobbies')
            ->assertJsonPath('subcategory_slug', 'salud')->assertJsonStructure(['sku', 'name', 'type', 'base_price_cents', 'cost_cents', 'includes', 'is_available', 'is_active', 'updated_at']);

        $this->getJson(self::BASE.'/NO-EXISTE')->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_crear_genera_slug_con_guiones_y_sku_unico_y_se_ve_en_la_tienda(): void
    {
        $r = $this->admin()->postJson(self::BASE, $this->datos())->assertCreated();

        $r->assertJsonPath('slug', 'abuela-jardinera')->assertJsonPath('base_price_cents', 2350)->assertJsonPath('cost_cents', 900)
            ->assertJsonPath('includes', ['Plantita natural', 'Funda kraft'])->assertJsonPath('category_slug', 'familia')->assertJsonPath('is_active', true);
        $this->assertMatchesRegularExpression('/^JDS-[A-Z0-9]{6}$/', $r->json('sku'));
        $this->getJson('/api/v1/products/abuela-jardinera')->assertOk()->assertJsonMissingPath('cost_cents');
    }

    public function test_el_slug_nunca_se_elige_y_el_sku_si_se_puede_escribir(): void
    {
        $this->admin()->postJson(self::BASE, $this->datos(['name' => 'Tía Chiquita', 'slug' => 'otro', 'sku' => 'JDS-TIA-01']))
            ->assertCreated()->assertJsonPath('slug', 'tia-chiquita')->assertJsonPath('sku', 'JDS-TIA-01');
    }

    public function test_el_sku_no_se_repite_ni_con_productos_eliminados_y_debe_tener_formato(): void
    {
        $this->admin()->postJson(self::BASE, $this->datos(['sku' => 'pro-homero']))->assertStatus(422)
            ->assertJsonPath('errors.sku.0', 'El código (SKU) solo admite mayúsculas, números y guiones (ejemplo: JDS-DOCTOR-01).');

        ProductoModelo::query()->where('sku', 'PRO-HOMERO')->first()->delete();
        $this->postJson(self::BASE, $this->datos(['name' => 'Otro Distinto', 'sku' => 'PRO-HOMERO']))->assertStatus(422)
            ->assertJsonPath('errors.sku.0', 'Ya existe un producto con ese código (SKU). Los códigos no se reutilizan, ni siquiera de productos eliminados.');
    }

    public function test_valida_en_espanol(): void
    {
        $r = $this->admin()->postJson(self::BASE, ['base_price_cents' => 'mucho', 'type' => 'raro', 'includes' => 'texto'])->assertStatus(422);

        $r->assertJsonPath('code', 'validation_error')->assertJsonPath('errors.name.0', 'Escribe el nombre del producto.')
            ->assertJsonPath('errors.category.0', 'Elige la categoría.')->assertJsonPath('errors.base_price_cents.0', 'El precio no es válido.')
            ->assertJsonPath('errors.type.0', 'El tipo debe ser "personalizable" o "complemento".');

        $this->postJson(self::BASE, $this->datos(['base_price_cents' => -1]))->assertStatus(422)->assertJsonPath('errors.base_price_cents.0', 'El precio no puede ser negativo.');
        $this->postJson(self::BASE, $this->datos(['category' => 'no-existe']))->assertStatus(422)->assertJsonPath('errors.category.0', 'La categoría elegida no existe.');
        $this->postJson(self::BASE, $this->datos(['name' => 'Homero']))->assertStatus(422)
            ->assertJsonPath('errors.slug.0', 'Ya existe un producto con un nombre igual o muy parecido (puede ser uno eliminado).');
    }

    public function test_la_subcategoria_debe_pertenecer_a_la_categoria_elegida(): void
    {
        $this->admin()->postJson(self::BASE, $this->datos(['name' => 'Enfermero', 'category' => 'profesiones-y-hobbies', 'subcategory' => 'salud']))
            ->assertCreated()->assertJsonPath('subcategory_slug', 'salud');

        $this->postJson(self::BASE, $this->datos(['name' => 'Otro Producto', 'category' => 'amor', 'subcategory' => 'salud']))->assertStatus(422)
            ->assertJsonPath('errors.subcategory.0', 'La subcategoría elegida no existe.');
    }

    public function test_editar_cambia_los_datos_pero_no_el_sku_ni_el_slug(): void
    {
        $this->admin()->putJson(self::BASE.'/PRO-HOMERO', ['name' => 'Homero Simpson', 'slug' => 'otro', 'sku' => 'NUEVO-1', 'base_price_cents' => 2500, 'is_active' => false])
            ->assertOk()->assertJsonPath('sku', 'PRO-HOMERO')->assertJsonPath('slug', 'homero')->assertJsonPath('name', 'Homero Simpson')
            ->assertJsonPath('base_price_cents', 2500)->assertJsonPath('is_active', false);

        $this->getJson('/api/v1/products/homero')->assertNotFound(); // inactivo: la tienda ya no lo muestra
    }

    public function test_cambiar_de_categoria_deja_la_subcategoria_vacia_si_no_se_indica(): void
    {
        $this->admin()->putJson(self::BASE.'/PRO-DOCTOR', ['category' => 'amor'])->assertOk()->assertJsonPath('category_slug', 'amor')->assertJsonPath('subcategory_slug', null);
        $this->putJson(self::BASE.'/PRO-DOCTOR', ['category' => 'profesiones-y-hobbies', 'subcategory' => 'cocina-y-hobbies'])->assertOk()->assertJsonPath('subcategory_slug', 'cocina-y-hobbies');
    }

    public function test_los_datos_invalidos_al_editar_no_cambian_nada_y_uno_inexistente_responde_404(): void
    {
        $this->admin()->putJson(self::BASE.'/PRO-HOMERO', ['name' => '', 'base_price_cents' => -5])->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Escribe el nombre del producto.');
        $this->assertSame('Homero', ProductoModelo::query()->where('sku', 'PRO-HOMERO')->value('name'));

        $this->putJson(self::BASE.'/NO-EXISTE', ['name' => 'X'])->assertNotFound();
    }

    public function test_eliminar_es_borrado_logico_y_desaparece_de_la_tienda_y_del_listado(): void
    {
        $this->admin()->deleteJson(self::BASE.'/PRO-HOMERO')->assertNoContent();

        $this->assertNotNull(ProductoModelo::withTrashed()->where('sku', 'PRO-HOMERO')->first());
        $this->assertNotContains('homero', array_column($this->getJson(self::BASE)->json('data'), 'slug'));
        $this->getJson('/api/v1/products/homero')->assertNotFound();
        $this->deleteJson(self::BASE.'/PRO-HOMERO')->assertNotFound();
    }

    public function test_un_producto_eliminado_deja_a_su_categoria_borrable_si_no_quedan_otros(): void
    {
        $this->admin()->deleteJson(self::BASE.'/PRO-HOMERO')->assertNoContent();

        $this->deleteJson('/api/v1/admin/categories/familia')->assertNoContent();
    }
}
