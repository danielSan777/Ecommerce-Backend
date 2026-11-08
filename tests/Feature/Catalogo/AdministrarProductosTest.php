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
            'name' => 'Abuela jardinera', 'category' => 'profesiones-y-hobbies', 'subcategory' => 'salud', 'base_price_cents' => 2350, 'cost_cents' => 900,
            'size_text' => '12 cm', 'material_text' => 'Cerámica', 'includes' => ['Plantita natural', 'Funda kraft'],
            'description_html' => '<p>Una maceta para abuela.</p>', 'is_available' => true, 'is_active' => true,
        ], ...$cambios];
    }

    public function test_los_campos_de_google_se_guardan_sin_etiquetas_se_devuelven_y_vacios_quedan_nulos(): void
    {
        $r = $this->admin()->postJson(self::BASE, $this->datos(['seo_title' => 'Maceta <b>abuela</b> | Jardín de Sele', 'seo_description' => '  Una maceta con su nombre.<script>x</script> ']))
            ->assertCreated()
            ->assertJsonPath('seo_title', 'Maceta abuela | Jardín de Sele')
            ->assertJsonPath('seo_description', 'Una maceta con su nombre.x');
        $sku = $r->json('sku');

        $this->admin()->putJson(self::BASE.'/'.$sku, ['seo_title' => '   ', 'seo_description' => null])
            ->assertOk()->assertJsonPath('seo_title', null)->assertJsonPath('seo_description', null);
        $this->admin()->getJson(self::BASE.'/'.$sku)->assertJsonPath('seo_title', null);
    }

    public function test_los_campos_de_google_validan_su_largo_en_espanol(): void
    {
        $this->admin()->postJson(self::BASE, $this->datos(['seo_title' => str_repeat('a', 256), 'seo_description' => str_repeat('a', 321)]))
            ->assertStatus(422)
            ->assertJsonPath('errors.seo_title.0', 'El título para Google no puede pasar de 255 caracteres.')
            ->assertJsonPath('errors.seo_description.0', 'La descripción para Google no puede pasar de 320 caracteres.');
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

    public function test_crear_genera_slug_con_guiones_y_sku_automatico_y_se_ve_en_la_tienda(): void
    {
        $r = $this->admin()->postJson(self::BASE, $this->datos())->assertCreated();

        $r->assertJsonPath('slug', 'abuela-jardinera')->assertJsonPath('base_price_cents', 2350)->assertJsonPath('cost_cents', 900)
            ->assertJsonPath('includes', ['Plantita natural', 'Funda kraft'])->assertJsonPath('category_slug', 'profesiones-y-hobbies')
            ->assertJsonPath('subcategory_slug', 'salud')->assertJsonPath('is_active', true)->assertJsonPath('sku', 'JDS-001');
        $this->getJson('/api/v1/products/abuela-jardinera')->assertOk()->assertJsonMissingPath('cost_cents');
    }

    public function test_ni_el_slug_ni_el_sku_se_escriben_a_mano(): void
    {
        $this->admin()->postJson(self::BASE, $this->datos(['name' => 'Tía Chiquita', 'slug' => 'otro', 'sku' => 'JDS-TIA-01']))
            ->assertCreated()->assertJsonPath('slug', 'tia-chiquita')->assertJsonPath('sku', 'JDS-001');
    }

    public function test_el_sku_es_consecutivo_con_ceros_y_nunca_se_reutiliza_ni_con_productos_eliminados(): void
    {
        $this->admin();
        $this->postJson(self::BASE, $this->datos(['name' => 'Uno']))->assertCreated()->assertJsonPath('sku', 'JDS-001');
        $this->postJson(self::BASE, $this->datos(['name' => 'Dos']))->assertCreated()->assertJsonPath('sku', 'JDS-002');

        ProductoModelo::query()->where('sku', 'JDS-002')->first()->delete(); // eliminado: su número no vuelve
        $this->postJson(self::BASE, $this->datos(['name' => 'Tres']))->assertCreated()->assertJsonPath('sku', 'JDS-003');

        // Los códigos que no siguen el formato (PRO-HOMERO) no afectan la numeración
        $this->assertSame('JDS-004', $this->getJson(self::BASE.'/next-sku')->assertOk()->json('sku'));
    }

    public function test_la_cantidad_de_digitos_se_puede_cambiar_sin_tocar_los_codigos_anteriores(): void
    {
        $this->admin();
        $this->postJson(self::BASE, $this->datos(['name' => 'Uno']))->assertJsonPath('sku', 'JDS-001');

        config(['catalogo.sku_digitos' => 4]);
        $this->postJson(self::BASE, $this->datos(['name' => 'Dos']))->assertJsonPath('sku', 'JDS-0002');
        $this->assertNotNull(ProductoModelo::query()->where('sku', 'JDS-001')->first());

        config(['catalogo.sku_prefijo' => 'ZZ', 'catalogo.sku_digitos' => 3]);
        $this->postJson(self::BASE, $this->datos(['name' => 'Tres']))->assertJsonPath('sku', 'ZZ-001');
    }

    public function test_el_numero_crece_aunque_pase_de_los_digitos(): void
    {
        ProductoModelo::query()->where('sku', 'PRO-HOMERO')->update(['sku' => 'JDS-999']);

        $this->admin()->postJson(self::BASE, $this->datos())->assertCreated()->assertJsonPath('sku', 'JDS-1000');
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
            ->assertJsonPath('errors.subcategory.0', 'La subcategoría elegida no existe en esa categoría.');
    }

    public function test_editar_cambia_los_datos_pero_no_el_sku_ni_el_slug(): void
    {
        $this->admin()->putJson(self::BASE.'/PRO-HOMERO', ['name' => 'Homero Simpson', 'slug' => 'otro', 'sku' => 'NUEVO-1', 'base_price_cents' => 2500, 'is_active' => false])
            ->assertOk()->assertJsonPath('sku', 'PRO-HOMERO')->assertJsonPath('slug', 'homero')->assertJsonPath('name', 'Homero Simpson')
            ->assertJsonPath('base_price_cents', 2500)->assertJsonPath('is_active', false);

        $this->getJson('/api/v1/products/homero')->assertNotFound(); // inactivo: la tienda ya no lo muestra
    }

    public function test_cambiar_de_categoria_exige_elegir_una_subcategoria_de_la_nueva(): void
    {
        $this->admin()->putJson(self::BASE.'/PRO-DOCTOR', ['category' => 'amor'])->assertStatus(422)->assertJsonPath('errors.subcategory.0', 'Elige la subcategoría.');
        $this->putJson(self::BASE.'/PRO-DOCTOR', ['category' => 'profesiones-y-hobbies', 'subcategory' => 'cocina-y-hobbies'])->assertOk()->assertJsonPath('subcategory_slug', 'cocina-y-hobbies');
        $this->putJson(self::BASE.'/PRO-DOCTOR', ['category' => 'amor', 'subcategory' => 'salud'])->assertStatus(422)->assertJsonPath('errors.subcategory.0', 'La subcategoría elegida no existe en esa categoría.');
    }

    public function test_categoria_y_subcategoria_son_obligatorias_al_crear(): void
    {
        $this->admin();
        $datos = $this->datos();
        unset($datos['subcategory']);
        $this->postJson(self::BASE, $datos)->assertStatus(422)->assertJsonPath('errors.subcategory.0', 'Elige la subcategoría.');

        $datos = $this->datos();
        unset($datos['category']);
        $this->postJson(self::BASE, $datos)->assertStatus(422)->assertJsonPath('errors.category.0', 'Elige la categoría.');

        // Una categoría sin subcategorías no admite productos hasta que se le cree una
        $this->postJson(self::BASE, $this->datos(['category' => 'amor']))->assertStatus(422)->assertJsonPath('errors.subcategory.0', 'La subcategoría elegida no existe en esa categoría.');
        $this->assertSame(0, ProductoModelo::query()->where('name', 'Abuela jardinera')->count());
    }

    public function test_descuento_en_porcentaje_calcula_el_monto_y_el_precio_final_en_laravel(): void
    {
        $r = $this->admin()->postJson(self::BASE, $this->datos(['base_price_cents' => 2150, 'discount_percent' => 12.5]))->assertCreated();

        // 2150 × 12.5 % = 268.75 → 269 centavos (mitad hacia arriba)
        $r->assertJsonPath('discount_percent', 12.5)->assertJsonPath('discount_cents', 269)->assertJsonPath('final_price_cents', 1881);

        // La tienda también muestra el precio con descuento
        $precio = $this->getJson('/api/v1/products/abuela-jardinera')->assertOk()->json('price');
        $this->assertSame([2150, 1881, 269, 13, '13% OFF'], [$precio['base_cents'], $precio['final_cents'], $precio['discount_cents'], $precio['discount_percent'], $precio['badge']]);

        $this->putJson(self::BASE.'/'.$r->json('sku'), ['discount_percent' => null])->assertOk()->assertJsonPath('discount_percent', 0)->assertJsonPath('discount_cents', 0)->assertJsonPath('final_price_cents', 2150);
    }

    public function test_valida_el_descuento_en_espanol(): void
    {
        $this->admin();
        $this->postJson(self::BASE, $this->datos(['discount_percent' => 95]))->assertStatus(422)->assertJsonPath('errors.discount_percent.0', 'El descuento no puede ser mayor a 90%.');
        $this->postJson(self::BASE, $this->datos(['discount_percent' => -5]))->assertStatus(422)->assertJsonPath('errors.discount_percent.0', 'El descuento no puede ser negativo.');
        $this->postJson(self::BASE, $this->datos(['discount_percent' => 'mucho']))->assertStatus(422)->assertJsonPath('errors.discount_percent.0', 'El descuento debe ser un número, por ejemplo 10 o 12.5.');
        $this->postJson(self::BASE, $this->datos(['discount_percent' => 10.123]))->assertStatus(422)->assertJsonPath('errors.discount_percent.0', 'El descuento admite hasta dos decimales, por ejemplo 12.5.');
    }

    public function test_previsualizar_el_precio_con_descuento(): void
    {
        $this->admin()->getJson(self::BASE.'/price-preview?base_price_cents=2150&discount_percent=10')->assertOk()
            ->assertExactJson(['base_price_cents' => 2150, 'discount_percent' => 10, 'discount_cents' => 215, 'final_price_cents' => 1935]);
        $this->getJson(self::BASE.'/price-preview?base_price_cents=2150')->assertOk()->assertJsonPath('discount_cents', 0)->assertJsonPath('final_price_cents', 2150);
        $this->getJson(self::BASE.'/price-preview?base_price_cents=abc')->assertStatus(422)->assertJsonPath('errors.base_price_cents.0', 'El precio no es válido.');
        $this->getJson(self::BASE.'/price-preview?base_price_cents=2150&discount_percent=99')->assertStatus(422);
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
