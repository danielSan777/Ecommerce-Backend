<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogo;

use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use Database\Seeders\ProductosDePrueba;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'https://jardindesele.com']);
        $this->seed(ProductosDePrueba::class);
    }

    public function test_lista_las_cuatro_categorias_iniciales_en_orden_con_sus_subcategorias(): void
    {
        $respuesta = $this->getJson('/api/v1/categories')->assertOk();

        $this->assertSame(['profesiones-y-hobbies', 'amor', 'amistad', 'familia'], array_column($respuesta->json('data'), 'slug'));
        $this->assertSame(
            ['salud', 'administrativos', 'fuerza-publica', 'cocina-y-hobbies'],
            array_column($respuesta->json('data.0.subcategories'), 'slug'),
        );
        $respuesta->assertJsonPath('data.0.subcategories.0.url', '/profesiones-y-hobbies/salud');
    }

    public function test_categoria_sin_subcategoria_trae_todos_sus_productos_con_la_forma_del_contrato(): void
    {
        $respuesta = $this->getJson('/api/v1/categories/profesiones-y-hobbies')->assertOk();

        $respuesta->assertJsonStructure([
            'slug', 'name', 'headline', 'intro_html', 'hero', 'price_from_cents', 'subcategories', 'active_subcategory',
            'products' => [['sku', 'slug', 'name', 'image', 'price' => ['currency', 'base_cents', 'final_cents', 'discount_cents', 'discount_percent', 'badge'], 'available']],
            'seo' => ['title', 'description', 'canonical', 'og_image'],
        ]);
        $this->assertCount(4, $respuesta->json('products'));
        $respuesta->assertJsonPath('price_from_cents', 2100)->assertJsonPath('active_subcategory', null)
            ->assertJsonPath('seo.canonical', 'https://jardindesele.com/profesiones-y-hobbies');
    }

    public function test_filtrar_por_subcategoria_trae_solo_esos_productos(): void
    {
        $respuesta = $this->getJson('/api/v1/categories/profesiones-y-hobbies?subcategory=salud')->assertOk();

        $this->assertEqualsCanonicalizing(['doctor', 'enfermera'], array_column($respuesta->json('products'), 'slug'));
        $respuesta->assertJsonPath('active_subcategory', 'salud')
            ->assertJsonPath('seo.canonical', 'https://jardindesele.com/profesiones-y-hobbies/salud');
    }

    public function test_lo_que_no_existe_responde_404_con_json_en_ingles(): void
    {
        $this->getJson('/api/v1/categories/no-existe')->assertNotFound()
            ->assertExactJson(['code' => 'not_found', 'message' => "La categoría 'no-existe' no existe."]);
        $this->getJson('/api/v1/categories/amor?subcategory=x')->assertNotFound()->assertJsonPath('message', "La subcategoría 'x' no existe.");
        $this->getJson('/api/v1/products/no-existe')->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_ficha_de_producto_con_la_forma_del_contrato_y_sin_costo_interno(): void
    {
        ProductoModelo::query()->where('slug', 'doctor')->update(['cost_cents' => 900]);

        $respuesta = $this->getJson('/api/v1/products/doctor')->assertOk();

        $respuesta->assertJsonPath('sku', 'PRO-DOCTOR')
            ->assertJsonPath('type', 'personalizable')
            ->assertJsonPath('category.slug', 'profesiones-y-hobbies')
            ->assertJsonPath('subcategory.slug', 'salud')
            ->assertJsonPath('price.base_cents', 2100)
            ->assertJsonPath('price.final_cents', 2100)
            ->assertJsonPath('price.badge', null)
            ->assertJsonPath('includes.0', 'Plantita natural')
            ->assertJsonPath('configurator_url', '/api/v1/products/doctor/configurator')
            ->assertJsonPath('related_skus', ['PRO-ENFERMERA'])
            ->assertJsonPath('seo.canonical', 'https://jardindesele.com/producto/doctor');
        $this->assertStringNotContainsString('cost', json_encode($respuesta->json()));
    }

    public function test_los_medios_salen_con_url_armada_desde_la_ruta_relativa(): void
    {
        $producto = ProductoModelo::query()->where('slug', 'doctor')->firstOrFail();
        $producto->medios()->create([
            'type' => 'image', 'role' => 'front',
            'variants' => ['600' => 'products/PRO-DOCTOR/a1b2c3-600.webp', '1080' => 'products/PRO-DOCTOR/a1b2c3-1080.webp'],
            'width' => 1080, 'height' => 1350, 'alt' => 'Doctor', 'sort_order' => 1,
        ]);
        $producto->medios()->create([
            'type' => 'video', 'role' => 'video',
            'variants' => ['url' => 'products/PRO-DOCTOR/v-9f8e.mp4', 'poster' => 'products/PRO-DOCTOR/p-9f8e.webp'], 'duration_s' => 7, 'sort_order' => 2,
        ]);

        $respuesta = $this->getJson('/api/v1/products/doctor')->assertOk();

        $respuesta->assertJsonPath('media.0.type', 'image')->assertJsonPath('media.0.role', 'front')
            ->assertJsonPath('media.0.w', 1080)
            ->assertJsonPath('media.1.type', 'video')->assertJsonPath('media.1.duration_s', 7);
        $this->assertStringEndsWith('/media/products/PRO-DOCTOR/a1b2c3-1080.webp', $respuesta->json('media.0.srcset.1080'));
        $this->assertStringEndsWith('/media/products/PRO-DOCTOR/v-9f8e.mp4', $respuesta->json('media.1.url'));
        $this->getJson('/api/v1/categories/profesiones-y-hobbies?subcategory=salud')
            ->assertJsonPath('products.0.image', $respuesta->json('media.0.srcset.1080'));
    }

    public function test_un_producto_inactivo_no_aparece_en_la_api(): void
    {
        ProductoModelo::query()->where('slug', 'doctor')->update(['is_active' => false]);

        $this->getJson('/api/v1/products/doctor')->assertNotFound();
        $this->assertNotContains('doctor', array_column($this->getJson('/api/v1/categories/profesiones-y-hobbies')->json('products'), 'slug'));
    }

    public function test_sin_titulo_para_google_se_usa_nombre_barra_jardin_de_sele(): void
    {
        $this->getJson('/api/v1/categories/amor')->assertJsonPath('seo.title', 'Amor | Jardín de Sele');
        $this->getJson('/api/v1/categories/profesiones-y-hobbies?subcategory=salud')->assertJsonPath('seo.title', 'Salud · Profesiones y Hobbies | Jardín de Sele');
        $this->getJson('/api/v1/products/doctor')->assertJsonPath('seo.title', 'Doctor | Jardín de Sele');

        ProductoModelo::query()->where('slug', 'doctor')->update(['seo_title' => 'Maceta para doctores']);
        $this->getJson('/api/v1/products/doctor')->assertJsonPath('seo.title', 'Maceta para doctores');
    }

    public function test_el_listado_de_productos_trae_los_mas_nuevos_primero_con_categoria_y_palabras_de_busqueda(): void
    {
        ProductoModelo::query()->where('slug', 'doctor')->update(['search_synonyms' => ['medico', 'salud']]);

        $respuesta = $this->getJson('/api/v1/products')->assertOk();
        $ids = ProductoModelo::query()->where('is_active', true)->orderByDesc('id')->pluck('slug')->all();

        $this->assertSame($ids, array_column($respuesta->json('data'), 'slug'));
        $respuesta->assertJsonStructure(['data' => [['sku', 'slug', 'name', 'image', 'price' => ['final_cents', 'base_cents'], 'available', 'category' => ['slug', 'name'], 'subcategory', 'search_terms']]]);
        $doctor = collect($respuesta->json('data'))->firstWhere('slug', 'doctor');
        $this->assertSame(['medico', 'salud'], $doctor['search_terms']);
        $this->assertSame('salud', $doctor['subcategory']['slug']);
        $this->assertStringNotContainsString('cost', json_encode($respuesta->json()));
    }

    public function test_el_listado_acepta_limite_oculta_inactivos_y_valida_en_espanol(): void
    {
        $this->assertCount(3, $this->getJson('/api/v1/products?limit=3')->assertOk()->json('data'));

        ProductoModelo::query()->where('slug', 'doctor')->update(['is_active' => false]);
        $this->assertNotContains('doctor', array_column($this->getJson('/api/v1/products')->json('data'), 'slug'));

        $this->getJson('/api/v1/products?limit=0')->assertStatus(422)->assertJsonPath('errors.limit.0', 'El límite mínimo es 1.');
        $this->getJson('/api/v1/products?limit=101')->assertStatus(422)->assertJsonPath('errors.limit.0', 'El límite máximo es 100.');
    }

    public function test_las_categorias_traen_imagen_de_portada_y_frase_corta_para_las_tarjetas(): void
    {
        CategoriaModelo::query()->where('slug', 'amor')->firstOrFail()->medios()->create([
            'type' => 'image', 'role' => 'hero',
            'variants' => ['600' => 'categories/amor/x-600.webp', '1080' => 'categories/amor/x-1080.webp'], 'width' => 1080, 'height' => 800, 'alt' => 'Amor', 'sort_order' => 1,
        ]);
        CategoriaModelo::query()->where('slug', 'amor')->update(['headline' => 'Dile lo que sientes']);

        $amor = collect($this->getJson('/api/v1/categories')->assertOk()->json('data'))->firstWhere('slug', 'amor');

        $this->assertStringEndsWith('/media/categories/amor/x-600.webp', $amor['image']);
        $this->assertSame('Dile lo que sientes', $amor['short_description']);
        $this->assertNull(collect($this->getJson('/api/v1/categories')->json('data'))->firstWhere('slug', 'familia')['image']);
    }

    public function test_un_producto_agotado_se_muestra_como_no_disponible(): void
    {
        ProductoModelo::query()->where('slug', 'doctor')->update(['is_available' => false]);

        $this->getJson('/api/v1/products/doctor')->assertOk()->assertJsonPath('available', false);
    }

    public function test_los_sku_son_unicos(): void
    {
        $this->expectException(QueryException::class);
        ProductoModelo::query()->where('slug', 'doctor')->firstOrFail()->replicate()->fill(['slug' => 'otro'])->save();
    }
}
