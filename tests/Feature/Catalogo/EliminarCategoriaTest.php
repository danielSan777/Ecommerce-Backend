<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogo;

use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use App\Models\User;
use Database\Seeders\ProductosDePrueba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EliminarCategoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductosDePrueba::class);
    }

    private function admin(): static
    {
        return $this->actingAs(User::factory()->create(), 'admin');
    }

    public function test_sin_sesion_de_administrador_responde_401_y_no_borra_nada(): void
    {
        $this->deleteJson('/api/v1/admin/categories/familia')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');

        $this->assertNotNull(CategoriaModelo::query()->where('slug', 'familia')->first());
    }

    public function test_una_categoria_con_productos_responde_409_con_mensaje_y_la_lista_de_productos(): void
    {
        $respuesta = $this->admin()->deleteJson('/api/v1/admin/categories/profesiones-y-hobbies')->assertStatus(409);

        $respuesta->assertJsonPath('code', 'category_has_products')->assertJsonPath('products_count', 4)
            ->assertJsonPath('message', "No se puede eliminar la categoría 'profesiones-y-hobbies' porque tiene 4 producto(s). Muévelos a otra categoría o elimínalos primero.");
        $this->assertEqualsCanonicalizing(['abogada', 'abogado', 'doctor', 'enfermera'], array_column($respuesta->json('products'), 'slug'));
        $respuesta->assertJsonStructure(['products' => [['sku', 'slug', 'name']]]);
    }

    public function test_si_se_rechaza_la_categoria_y_sus_productos_siguen_intactos(): void
    {
        $this->admin()->deleteJson('/api/v1/admin/categories/familia')->assertStatus(409);

        $this->assertNotNull(CategoriaModelo::query()->where('slug', 'familia')->first());
        $this->assertNotNull(ProductoModelo::query()->where('slug', 'homero')->first());
        $this->getJson('/api/v1/products/homero')->assertOk();
    }

    public function test_los_productos_inactivos_tambien_cuentan_para_bloquear_el_borrado(): void
    {
        ProductoModelo::query()->where('slug', 'homero')->update(['is_active' => false]);

        $this->admin()->deleteJson('/api/v1/admin/categories/familia')->assertStatus(409)->assertJsonPath('products_count', 1);
    }

    public function test_una_categoria_sin_productos_se_elimina_y_desaparece_de_la_api_publica(): void
    {
        ProductoModelo::query()->where('slug', 'homero')->delete();

        $this->admin()->deleteJson('/api/v1/admin/categories/familia')->assertNoContent();

        $this->assertNotContains('familia', array_column($this->getJson('/api/v1/categories')->json('data'), 'slug'));
        $this->getJson('/api/v1/categories/familia')->assertNotFound();
    }

    public function test_una_categoria_que_no_existe_responde_404(): void
    {
        $this->admin()->deleteJson('/api/v1/admin/categories/no-existe')->assertNotFound()->assertJsonPath('code', 'not_found');
    }
}
