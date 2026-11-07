<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogo;

use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use App\Core\Catalogo\Infraestructura\Modelos\SubcategoriaModelo;
use App\Models\User;
use Database\Seeders\ProductosDePrueba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdministrarSubcategoriasTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/categories/profesiones-y-hobbies/subcategories';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductosDePrueba::class);
    }

    private function admin(): static
    {
        return $this->actingAs(User::factory()->administrador()->create(), 'api');
    }

    public function test_solo_el_administrador_accede(): void
    {
        $this->getJson(self::BASE)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'api');
        $this->getJson(self::BASE)->assertForbidden();
        $this->postJson(self::BASE, ['name' => 'Nueva'])->assertForbidden();
        $this->deleteJson(self::BASE.'/salud')->assertForbidden();
    }

    public function test_lista_las_subcategorias_de_la_categoria_con_sus_productos(): void
    {
        $r = $this->admin()->getJson(self::BASE)->assertOk();

        $this->assertEqualsCanonicalizing(['salud', 'administrativos', 'fuerza-publica', 'cocina-y-hobbies'], array_column($r->json('data'), 'slug'));
        $r->assertJsonStructure(['data' => [['slug', 'name', 'category_slug', 'sort_order', 'is_active', 'products_count']]]);
        $this->assertSame('profesiones-y-hobbies', $r->json('data.0.category_slug'));
        $this->assertSame(2, collect($r->json('data'))->firstWhere('slug', 'salud')['products_count']); // Doctor y Enfermera
    }

    public function test_una_categoria_que_no_existe_responde_404(): void
    {
        $this->admin()->getJson('/api/v1/admin/categories/no-existe/subcategories')->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->postJson('/api/v1/admin/categories/no-existe/subcategories', ['name' => 'X'])->assertNotFound();
    }

    public function test_crear_genera_el_slug_con_guiones_y_lo_ignora_si_lo_mandan(): void
    {
        $this->admin()->postJson('/api/v1/admin/categories/amor/subcategories', ['name' => 'Aniversario Especial', 'slug' => 'otro', 'sort_order' => 2])
            ->assertCreated()->assertJsonPath('slug', 'aniversario-especial')->assertJsonPath('category_slug', 'amor')->assertJsonPath('is_active', true)->assertJsonPath('products_count', 0);

        $this->assertDatabaseHas('subcategories', ['slug' => 'aniversario-especial']);
        $this->assertDatabaseMissing('subcategories', ['slug' => 'otro']);
    }

    public function test_el_mismo_nombre_se_puede_usar_en_otra_categoria_pero_no_en_la_misma(): void
    {
        $this->admin()->postJson('/api/v1/admin/categories/amor/subcategories', ['name' => 'Salud'])->assertCreated();
        $this->postJson(self::BASE, ['name' => 'SALUD'])->assertStatus(422)
            ->assertJsonPath('errors.slug.0', 'Ya existe una subcategoría con un nombre igual o muy parecido en esta categoría.');
    }

    public function test_valida_en_espanol(): void
    {
        $this->admin()->postJson(self::BASE, [])->assertStatus(422)->assertJsonPath('errors.name.0', 'Escribe el nombre de la subcategoría.');
        $this->postJson(self::BASE, ['name' => '???'])->assertStatus(422)->assertJsonPath('errors.slug.0', 'El nombre debe tener al menos una letra o un número.');
    }

    public function test_editar_cambia_el_nombre_pero_no_la_direccion(): void
    {
        $this->admin()->putJson(self::BASE.'/salud', ['name' => 'Salud y bienestar', 'is_active' => false, 'slug' => 'otra'])
            ->assertOk()->assertJsonPath('slug', 'salud')->assertJsonPath('name', 'Salud y bienestar')->assertJsonPath('is_active', false);

        $this->assertSame('Salud y bienestar', SubcategoriaModelo::query()->where('slug', 'salud')->value('name'));
    }

    public function test_editar_una_subcategoria_de_otra_categoria_responde_404(): void
    {
        $this->admin()->putJson('/api/v1/admin/categories/amor/subcategories/salud', ['name' => 'X'])->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_eliminar_con_productos_responde_409_con_la_lista_y_no_borra_nada(): void
    {
        $r = $this->admin()->deleteJson(self::BASE.'/salud')->assertStatus(409);

        $r->assertJsonPath('code', 'subcategory_has_products')->assertJsonStructure(['message', 'products_count', 'products' => [['sku', 'slug', 'name']]]);
        $this->assertGreaterThan(0, $r->json('products_count'));
        $this->assertNotNull(SubcategoriaModelo::query()->where('slug', 'salud')->first());
        $this->assertSame($r->json('products_count'), ProductoModelo::query()->whereIn('slug', array_column($r->json('products'), 'slug'))->count());
    }

    public function test_eliminar_sin_productos_la_borra(): void
    {
        $this->admin()->postJson('/api/v1/admin/categories/amor/subcategories', ['name' => 'Vacía'])->assertCreated();

        $this->deleteJson('/api/v1/admin/categories/amor/subcategories/vacia')->assertNoContent();

        $this->assertDatabaseMissing('subcategories', ['slug' => 'vacia']);
    }

    public function test_los_productos_ya_eliminados_no_bloquean_el_borrado(): void
    {
        $this->admin()->postJson('/api/v1/admin/categories/amor/subcategories', ['name' => 'Con historial'])->assertCreated();
        $sub = SubcategoriaModelo::query()->where('slug', 'con-historial')->firstOrFail();
        $producto = ProductoModelo::query()->where('slug', 'homero')->firstOrFail();
        $producto->update(['category_id' => CategoriaModelo::query()->where('slug', 'amor')->value('id'), 'subcategory_id' => $sub->id]);
        $producto->delete(); // borrado lógico

        $this->deleteJson('/api/v1/admin/categories/amor/subcategories/con-historial')->assertNoContent();

        $this->assertNull(ProductoModelo::withTrashed()->where('slug', 'homero')->value('subcategory_id'));
    }
}
