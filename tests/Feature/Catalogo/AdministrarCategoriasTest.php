<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogo;

use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Models\User;
use Database\Seeders\ProductosDePrueba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdministrarCategoriasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductosDePrueba::class);
    }

    private function admin(): static
    {
        return $this->actingAs(User::factory()->administrador()->create(), 'api');
    }

    public function test_solo_el_administrador_puede_listar_crear_y_editar(): void
    {
        $this->getJson('/api/v1/admin/categories')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'api');
        $this->getJson('/api/v1/admin/categories')->assertForbidden();
        $this->postJson('/api/v1/admin/categories', ['name' => 'Nueva'])->assertForbidden();
        $this->putJson('/api/v1/admin/categories/amor', ['name' => 'X'])->assertForbidden();
        $this->assertSame('Amor', CategoriaModelo::query()->where('slug', 'amor')->value('name'));
    }

    public function test_el_listado_trae_todas_con_conteos_incluso_las_inactivas(): void
    {
        CategoriaModelo::query()->where('slug', 'amistad')->update(['is_active' => false]);

        $r = $this->admin()->getJson('/api/v1/admin/categories')->assertOk();

        $porSlug = collect($r->json('data'))->keyBy('slug');
        $this->assertTrue($porSlug->has('amistad'));
        $this->assertFalse($porSlug['amistad']['is_active']);
        $this->assertSame(4, $porSlug['profesiones-y-hobbies']['products_count']);
        $this->assertSame(4, $porSlug['profesiones-y-hobbies']['subcategories_count']);
        $r->assertJsonStructure(['data' => [['slug', 'name', 'headline', 'intro_html', 'sort_order', 'is_active', 'seo_title', 'seo_description', 'products_count', 'subcategories_count', 'updated_at']]]);
    }

    public function test_crear_genera_el_identificador_del_nombre_con_guion_y_sin_tildes(): void
    {
        $this->admin()->postJson('/api/v1/admin/categories', ['name' => 'Día de la Madre', 'headline' => 'Para mamá', 'sort_order' => 9])
            ->assertCreated()->assertJsonPath('slug', 'dia-de-la-madre')->assertJsonPath('is_active', true)->assertJsonPath('products_count', 0);

        $this->assertDatabaseHas('categories', ['slug' => 'dia-de-la-madre', 'name' => 'Día de la Madre']);
        $this->assertContains('dia-de-la-madre', array_column($this->getJson('/api/v1/categories')->json('data') ?? [], 'slug'));
    }

    public function test_el_identificador_no_se_puede_elegir_siempre_sale_del_nombre(): void
    {
        $this->admin()->postJson('/api/v1/admin/categories', ['name' => 'Nueva Colección', 'slug' => 'otro-cualquiera'])
            ->assertCreated()->assertJsonPath('slug', 'nueva-coleccion');

        $this->assertDatabaseMissing('categories', ['slug' => 'otro-cualquiera']);
    }

    public function test_crear_valida_en_espanol_nombre_vacio_nombre_sin_letras_y_nombre_repetido(): void
    {
        $this->admin()->postJson('/api/v1/admin/categories', [])->assertStatus(422)->assertJsonPath('code', 'validation_error')
            ->assertJsonPath('errors.name.0', 'Escribe el nombre de la categoría.')->assertJsonMissingPath('errors.slug');

        $this->postJson('/api/v1/admin/categories', ['name' => '!!!'])->assertStatus(422)
            ->assertJsonPath('errors.slug.0', 'El nombre debe tener al menos una letra o un número.');

        $this->postJson('/api/v1/admin/categories', ['name' => 'AMOR'])->assertStatus(422)
            ->assertJsonPath('errors.slug.0', 'Ya existe una categoría con un nombre igual o muy parecido (puede ser una eliminada).');
    }

    public function test_editar_cambia_los_datos_pero_nunca_el_identificador(): void
    {
        $this->admin()->putJson('/api/v1/admin/categories/amor', ['name' => 'Amor y pareja', 'slug' => 'otro', 'is_active' => false, 'headline' => null])
            ->assertOk()->assertJsonPath('slug', 'amor')->assertJsonPath('name', 'Amor y pareja')->assertJsonPath('is_active', false);

        $this->assertDatabaseMissing('categories', ['slug' => 'otro']);
        $this->getJson('/api/v1/categories/amor')->assertNotFound(); // inactiva: la tienda pública ya no la muestra
    }

    public function test_los_campos_de_google_se_guardan_sin_etiquetas_html_y_vacios_quedan_nulos(): void
    {
        $this->admin()->putJson('/api/v1/admin/categories/amor', [
            'seo_title' => 'Regalos de amor | <b>Jardín</b> de Sele',
            'seo_description' => '<script>alert(1)</script>Macetas con su nombre.',
            'intro_html' => '  Un regalo <i>que crece</i>.  ',
        ])->assertOk()
            ->assertJsonPath('seo_title', 'Regalos de amor | Jardín de Sele')
            ->assertJsonPath('seo_description', 'alert(1)Macetas con su nombre.')
            ->assertJsonPath('intro_html', 'Un regalo que crece.');

        $this->admin()->putJson('/api/v1/admin/categories/amor', ['seo_title' => '   ', 'seo_description' => null, 'intro_html' => ''])
            ->assertOk()->assertJsonPath('seo_title', null)->assertJsonPath('seo_description', null)->assertJsonPath('intro_html', null);
    }

    public function test_los_campos_de_google_validan_su_largo_en_espanol(): void
    {
        $this->admin()->putJson('/api/v1/admin/categories/amor', ['seo_title' => str_repeat('a', 256), 'seo_description' => str_repeat('a', 321)])
            ->assertStatus(422)
            ->assertJsonPath('errors.seo_title.0', 'El título para Google no puede pasar de 255 caracteres.')
            ->assertJsonPath('errors.seo_description.0', 'La descripción para Google no puede pasar de 320 caracteres.');
    }

    public function test_editar_una_categoria_que_no_existe_responde_404(): void
    {
        $this->admin()->putJson('/api/v1/admin/categories/no-existe', ['name' => 'X'])->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_los_datos_invalidos_al_editar_responden_422_y_no_cambian_nada(): void
    {
        $this->admin()->putJson('/api/v1/admin/categories/amor', ['name' => '', 'sort_order' => 'abc'])->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Escribe el nombre de la categoría.')->assertJsonPath('errors.sort_order.0', 'El orden debe ser un número.');

        $this->assertSame('Amor', CategoriaModelo::query()->where('slug', 'amor')->value('name'));
    }
}
