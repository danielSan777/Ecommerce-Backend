<?php

declare(strict_types=1);

namespace Tests\Feature\Configurador;

use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use App\Core\Configurador\Infraestructura\Modelos\AccesorioModelo;
use App\Models\User;
use Database\Seeders\ProductosDePrueba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class ConfiguradorTest extends TestCase
{
    use RefreshDatabase;

    private const CATEGORIA = '/api/v1/admin/configurator/categories/profesiones-y-hobbies';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductosDePrueba::class);
        Storage::fake('media');
    }

    private function admin(): static
    {
        return $this->actingAs(User::factory()->administrador()->create(), 'api');
    }

    private function imagen(int $ancho = 200, int $alto = 120): UploadedFile
    {
        $gd = imagecreatetruecolor($ancho, $alto);
        imagealphablending($gd, false);
        imagesavealpha($gd, true);
        imagefill($gd, 0, 0, (int) imagecolorallocatealpha($gd, 200, 80, 40, 60));
        $ruta = tempnam(sys_get_temp_dir(), 'acc');
        imagepng($gd, $ruta);
        imagedestroy($gd);

        return new UploadedFile($ruta, 'accesorio.png', null, null, true);
    }

    /** @return array{0: int, 1: int, 2: int} ids de categoría de accesorios, ubicación y accesorio */
    private function base(): array
    {
        $categoria = $this->admin()->postJson('/api/v1/admin/accessory-categories', ['name' => 'Moda'])->assertCreated()->json('id');
        $ubicacion = $this->postJson('/api/v1/admin/locations', ['name' => 'Cabeza', 'allowed_category_ids' => [$categoria]])->assertCreated()->json('id');
        $accesorio = $this->postJson('/api/v1/admin/accessories', ['name' => 'Gafas', 'accessory_category_id' => $categoria])->assertCreated()->json('id');

        return [$categoria, $ubicacion, $accesorio];
    }

    /** @return array<string, mixed> */
    private function geometria(array $cambios = []): array
    {
        return array_merge(['x' => 40, 'y' => 25, 'width' => 20, 'height' => 10, 'rot_x' => 0, 'rot_y' => 0, 'rot_z' => 0, 'mirror' => false], $cambios);
    }

    /** Mensaje de error de un campo con puntos en su nombre (por ejemplo `locations.0.preset.x`). */
    private function error(TestResponse $r, string $campo): ?string
    {
        return $r->json('errors')[$campo][0] ?? null;
    }

    /** @return array<string, mixed> */
    private function configuracion(int $ubicacion, int $accesorio, array $ubicacionCambios = [], array $excepciones = []): array
    {
        return [
            'locations' => [array_merge(['location_id' => $ubicacion, 'state' => 'libre', 'priority' => 1, 'layer' => 1, 'fixed_accessory_id' => null, 'preset' => $this->geometria()], $ubicacionCambios)],
            'accessory_ids' => [$accesorio],
            'exceptions' => $excepciones,
        ];
    }

    public function test_solo_el_administrador_accede(): void
    {
        $rutas = [
            ['getJson', '/api/v1/admin/accessory-categories'], ['postJson', '/api/v1/admin/accessory-categories'],
            ['getJson', '/api/v1/admin/accessories'], ['postJson', '/api/v1/admin/accessories'], ['deleteJson', '/api/v1/admin/accessories/1'],
            ['getJson', '/api/v1/admin/locations'], ['putJson', '/api/v1/admin/locations/1'],
            ['getJson', self::CATEGORIA], ['putJson', self::CATEGORIA],
            ['getJson', '/api/v1/admin/configurator/products/PRO-DOCTOR'], ['putJson', '/api/v1/admin/configurator/products/PRO-DOCTOR/mode'],
        ];
        foreach ($rutas as [$metodo, $ruta]) {
            $this->{$metodo}($ruta)->assertUnauthorized();
        }
        $this->actingAs(User::factory()->create(), 'api');
        foreach ($rutas as [$metodo, $ruta]) {
            $this->{$metodo}($ruta)->assertForbidden();
        }
    }

    // ------------------------------------------------------------ categorías de accesorios

    public function test_categorias_de_accesorios_se_crean_editan_y_no_se_eliminan_con_accesorios(): void
    {
        [$categoria] = $this->base();

        $this->putJson("/api/v1/admin/accessory-categories/$categoria", ['name' => 'Moda y estilo'])->assertOk()->assertJsonPath('name', 'Moda y estilo')->assertJsonPath('accessories_count', 1);
        $this->postJson('/api/v1/admin/accessory-categories', ['name' => 'Moda y estilo'])->assertUnprocessable()->assertJsonPath('errors.name.0', 'Ya existe una categoría de accesorios con ese nombre.');
        $this->postJson('/api/v1/admin/accessory-categories', [])->assertUnprocessable()->assertJsonPath('errors.name.0', 'Escribe el nombre.');

        $this->deleteJson("/api/v1/admin/accessory-categories/$categoria")->assertStatus(409)->assertJsonPath('code', 'in_use');

        $vacia = $this->postJson('/api/v1/admin/accessory-categories', ['name' => 'Flores'])->json('id');
        $this->deleteJson("/api/v1/admin/accessory-categories/$vacia")->assertNoContent();
        $this->deleteJson("/api/v1/admin/accessory-categories/$vacia")->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    // ------------------------------------------------------------ accesorios

    public function test_un_accesorio_de_texto_necesita_su_texto_de_ejemplo(): void
    {
        [$categoria] = $this->base();

        $this->postJson('/api/v1/admin/accessories', ['name' => 'Nombre', 'accessory_category_id' => $categoria, 'type' => 'texto'])
            ->assertUnprocessable()->assertJsonPath('errors.default_text.0', 'Escribe el texto de ejemplo del accesorio de tipo texto.');
        $this->postJson('/api/v1/admin/accessories', ['name' => 'Nombre', 'accessory_category_id' => 999, 'type' => 'texto', 'default_text' => 'Ingrese su nombre.'])
            ->assertUnprocessable()->assertJsonPath('errors.accessory_category_id.0', 'La categoría de accesorios elegida no existe.');
        $this->postJson('/api/v1/admin/accessories', ['name' => 'Nombre', 'accessory_category_id' => $categoria, 'type' => 'texto', 'default_text' => 'Ingrese su nombre.'])
            ->assertCreated()->assertJsonPath('type', 'texto')->assertJsonPath('text', 'Ingrese su nombre.')->assertJsonPath('image', null)->assertJsonPath('category', 'Moda');
    }

    public function test_la_imagen_del_accesorio_se_guarda_como_archivo_con_ruta_relativa_y_se_reemplaza(): void
    {
        [, , $accesorio] = $this->base();

        $r = $this->postJson("/api/v1/admin/accessories/$accesorio/image", ['image' => $this->imagen(), 'alt' => 'Gafas negras'])->assertCreated();
        $variantes = AccesorioModelo::query()->findOrFail($accesorio)->image_variants;
        $this->assertSame(['1080', '160'], array_map('strval', array_keys($variantes)));
        foreach ($variantes as $ruta) {
            $this->assertStringStartsWith('accessories/', $ruta);
            Storage::disk('media')->assertExists($ruta);
        }
        $this->assertStringContainsString('accessories/', $r->json('image'));
        $this->assertStringEndsWith('-1080.webp', $r->json('image'));
        $this->assertStringEndsWith('-160.webp', $r->json('thumb'));
        $this->assertSame('Gafas negras', $r->json('alt'));

        // Al reemplazarla, los archivos anteriores se borran
        $this->postJson("/api/v1/admin/accessories/$accesorio/image", ['image' => $this->imagen(90, 90), 'alt' => 'Gafas nuevas'])->assertCreated();
        foreach ($variantes as $ruta) {
            Storage::disk('media')->assertMissing($ruta);
        }
    }

    public function test_la_imagen_pide_texto_alternativo_y_se_valida_por_su_contenido(): void
    {
        [, , $accesorio] = $this->base();
        $ruta = "/api/v1/admin/accessories/$accesorio/image";

        $this->postJson($ruta, ['image' => $this->imagen()])->assertUnprocessable()->assertJsonPath('errors.alt.0', 'Escribe el texto alternativo de la imagen (lo que se ve en ella).');
        $this->postJson($ruta, ['alt' => 'x'])->assertUnprocessable()->assertJsonPath('errors.image.0', 'Elige la imagen del accesorio.');
        $this->postJson($ruta, ['image' => UploadedFile::fake()->createWithContent('a.png', 'no soy una imagen'), 'alt' => 'x'])->assertUnprocessable();
        $this->postJson($ruta, ['image' => $this->imagen(40, 40), 'alt' => 'x'])->assertUnprocessable()->assertJsonPath('errors.image.0', 'La imagen es muy pequeña. Debe medir al menos 64 × 64 píxeles.');
        $this->postJson('/api/v1/admin/accessories/9999/image', ['image' => $this->imagen(), 'alt' => 'x'])->assertNotFound();
    }

    public function test_eliminar_un_accesorio_borra_sus_archivos_pero_no_si_esta_en_una_configuracion(): void
    {
        [, $ubicacion, $accesorio] = $this->base();
        $this->postJson("/api/v1/admin/accessories/$accesorio/image", ['image' => $this->imagen(), 'alt' => 'Gafas'])->assertCreated();
        $this->putJson(self::CATEGORIA, $this->configuracion($ubicacion, $accesorio))->assertOk();

        $this->deleteJson("/api/v1/admin/accessories/$accesorio")->assertStatus(409)->assertJsonPath('code', 'in_use');
        $this->assertNotEmpty(Storage::disk('media')->allFiles('accessories'));

        $otro = $this->postJson('/api/v1/admin/accessories', ['name' => 'Otro', 'accessory_category_id' => $this->getJson('/api/v1/admin/accessory-categories')->json('data.0.id')])->json('id');
        $this->postJson("/api/v1/admin/accessories/$otro/image", ['image' => $this->imagen(), 'alt' => 'Otro'])->assertCreated();
        $this->assertCount(4, Storage::disk('media')->allFiles('accessories'));
        $this->deleteJson("/api/v1/admin/accessories/$otro")->assertNoContent();
        $this->assertCount(2, Storage::disk('media')->allFiles('accessories'));
    }

    public function test_el_comportamiento_especial_lo_tiene_como_maximo_un_accesorio(): void
    {
        [$categoria, , $gafas] = $this->base();

        $this->putJson("/api/v1/admin/accessories/$gafas", ['behavior' => 'gafas'])->assertOk()->assertJsonPath('behavior', 'gafas');
        // Se puede volver a guardar el mismo accesorio con su comportamiento
        $this->putJson("/api/v1/admin/accessories/$gafas", ['behavior' => 'gafas', 'name' => 'Gafas negras'])->assertOk();
        $this->postJson('/api/v1/admin/accessories', ['name' => 'Otras gafas', 'accessory_category_id' => $categoria, 'behavior' => 'gafas'])
            ->assertUnprocessable()->assertJsonPath('errors.behavior.0', 'Otro accesorio ya tiene ese comportamiento. Solo uno puede tenerlo.');
        $this->postJson('/api/v1/admin/accessories', ['name' => 'Raro', 'accessory_category_id' => $categoria, 'behavior' => 'volar'])
            ->assertUnprocessable()->assertJsonPath('errors.behavior.0', 'El comportamiento debe ser lentes, gafas, marco con foto o marco con frase.');
        // Dos accesorios sin comportamiento conviven sin problema
        $this->postJson('/api/v1/admin/accessories', ['name' => 'Uno', 'accessory_category_id' => $categoria])->assertCreated()->assertJsonPath('behavior', null);
        $this->postJson('/api/v1/admin/accessories', ['name' => 'Dos', 'accessory_category_id' => $categoria])->assertCreated();
    }

    // ------------------------------------------------------------ ubicaciones

    public function test_las_ubicaciones_guardan_sus_reglas_de_categoria(): void
    {
        [$moda, $cabeza] = $this->base();
        $objetos = $this->postJson('/api/v1/admin/accessory-categories', ['name' => 'Objetos'])->json('id');

        $r = $this->postJson('/api/v1/admin/locations', ['name' => 'Pie izquierdo', 'excluded_category_ids' => [$moda, $objetos]])->assertCreated();
        $this->assertSame(['Moda', 'Objetos'], $r->json('excluded_categories'));
        $this->assertSame([], $r->json('allowed_categories'));

        $this->putJson('/api/v1/admin/locations/'.$cabeza, ['name' => 'Cabeza y cuello', 'allowed_category_ids' => [$moda, $objetos]])->assertOk()
            ->assertJsonPath('name', 'Cabeza y cuello')->assertJsonPath('allowed_categories', ['Moda', 'Objetos']);
        $this->getJson('/api/v1/admin/locations')->assertOk()->assertJsonCount(2, 'data');

        $this->postJson('/api/v1/admin/locations', ['name' => 'Cabeza y cuello'])->assertUnprocessable()->assertJsonPath('errors.name.0', 'Ya existe una ubicación con ese nombre.');
        $this->postJson('/api/v1/admin/locations', ['name' => 'Otra', 'allowed_category_ids' => [$moda], 'excluded_category_ids' => [$moda]])
            ->assertUnprocessable()->assertJsonPath('errors.allowed_category_ids.0', 'Una categoría no puede estar a la vez permitida y excluida.');
        $this->postJson('/api/v1/admin/locations', ['name' => 'Otra', 'allowed_category_ids' => [999]])->assertUnprocessable();
    }

    public function test_una_ubicacion_en_uso_no_se_elimina(): void
    {
        [, $ubicacion, $accesorio] = $this->base();
        $libre = $this->postJson('/api/v1/admin/locations', ['name' => 'Base'])->json('id');
        $this->putJson(self::CATEGORIA, $this->configuracion($ubicacion, $accesorio))->assertOk();

        $this->deleteJson("/api/v1/admin/locations/$ubicacion")->assertStatus(409)->assertJsonPath('code', 'in_use');
        $this->deleteJson("/api/v1/admin/locations/$libre")->assertNoContent();
    }

    // ------------------------------------------------------------ configuración por categoría

    public function test_una_categoria_sin_configurar_muestra_todas_las_ubicaciones_libres_y_sin_valores(): void
    {
        $this->base();
        // Los productos de la categoría salen del más reciente al más antiguo (por fecha y hora de creación)
        foreach (['PRO-ABOGADA' => '2026-01-01 08:00:00', 'PRO-ABOGADO' => '2026-01-02 08:00:00', 'PRO-DOCTOR' => '2026-01-03 08:00:00', 'PRO-ENFERMERA' => '2026-01-03 09:30:00'] as $sku => $fecha) {
            ProductoModelo::query()->where('sku', $sku)->update(['created_at' => $fecha]);
        }

        $r = $this->getJson(self::CATEGORIA)->assertOk()->assertJsonPath('scope', 'category')->assertJsonPath('has_config', false)->assertJsonPath('config.accessory_ids', []);
        $this->assertSame('libre', $r->json('config.locations.0.state'));
        $this->assertNull($r->json('config.locations.0.preset'));
        $this->assertSame(['Enfermera', 'Doctor', 'Abogado', 'Abogada'], array_column($r->json('products'), 'name'));
        $this->getJson('/api/v1/admin/configurator/categories/no-existe')->assertNotFound();
    }

    public function test_guardar_y_leer_la_configuracion_de_la_categoria_la_reemplaza_por_completo(): void
    {
        [, $ubicacion, $accesorio] = $this->base();
        $otro = $this->postJson('/api/v1/admin/accessories', ['name' => 'Perro', 'accessory_category_id' => $this->getJson('/api/v1/admin/accessory-categories')->json('data.0.id')])->json('id');

        $excepcion = ['accessory_id' => $accesorio, 'location_id' => $ubicacion] + $this->geometria(['x' => 10, 'width' => 33.5, 'mirror' => true, 'rot_y' => -20]);
        $r = $this->putJson(self::CATEGORIA, [
            'locations' => [['location_id' => $ubicacion, 'state' => 'libre', 'priority' => 2, 'layer' => 3, 'preset' => $this->geometria(['rot_z' => 15])]],
            'accessory_ids' => [$accesorio, $otro],
            'exceptions' => [$excepcion],
        ])->assertOk()->assertJsonPath('has_config', true);

        $this->assertSame(2, $r->json('config.locations.0.priority'));
        $this->assertSame(3, $r->json('config.locations.0.layer'));
        $this->assertEquals(15.0, $r->json('config.locations.0.preset.rot_z'));
        $this->assertSame([$accesorio, $otro], $r->json('config.accessory_ids'));
        $this->assertEquals(33.5, $r->json('config.exceptions.0.width'));
        $this->assertTrue($r->json('config.exceptions.0.mirror'));

        $this->putJson(self::CATEGORIA, $this->configuracion($ubicacion, $accesorio, ['state' => 'deshabilitada', 'preset' => null]))->assertOk();
        $r = $this->getJson(self::CATEGORIA)->assertOk();
        $this->assertSame('deshabilitada', $r->json('config.locations.0.state'));
        $this->assertNull($r->json('config.locations.0.preset'));
        $this->assertSame([$accesorio], $r->json('config.accessory_ids'));
        $this->assertSame([], $r->json('config.exceptions'));
    }

    public function test_una_ubicacion_fija_necesita_elemento_fijo_marcado_y_valores(): void
    {
        [, $ubicacion, $accesorio] = $this->base();

        $r = $this->putJson(self::CATEGORIA, $this->configuracion($ubicacion, $accesorio, ['state' => 'fija', 'preset' => null]))->assertUnprocessable();
        $this->assertSame('Elige el elemento fijo de la ubicación fija.', $this->error($r, 'locations.0.fixed_accessory_id'));
        $this->assertSame('Define los valores de la ubicación fija (posición y tamaño).', $this->error($r, 'locations.0.preset'));

        $noMarcado = $this->postJson('/api/v1/admin/accessories', ['name' => 'Regalo', 'accessory_category_id' => $this->getJson('/api/v1/admin/accessory-categories')->json('data.0.id')])->json('id');
        $r = $this->putJson(self::CATEGORIA, $this->configuracion($ubicacion, $accesorio, ['state' => 'fija', 'fixed_accessory_id' => $noMarcado]))->assertUnprocessable();
        $this->assertSame('El elemento fijo debe ser un accesorio marcado.', $this->error($r, 'locations.0.fixed_accessory_id'));

        $this->putJson(self::CATEGORIA, $this->configuracion($ubicacion, $accesorio, ['state' => 'fija', 'fixed_accessory_id' => $accesorio]))->assertOk()
            ->assertJsonPath('config.locations.0.fixed_accessory_id', $accesorio);
    }

    public function test_las_excepciones_siguen_las_reglas(): void
    {
        [, $ubicacion, $accesorio] = $this->base();
        $suelto = $this->postJson('/api/v1/admin/accessories', ['name' => 'Perro', 'accessory_category_id' => $this->getJson('/api/v1/admin/accessory-categories')->json('data.0.id')])->json('id');
        $e = fn (int $a) => ['accessory_id' => $a, 'location_id' => $ubicacion] + $this->geometria();

        $r = $this->putJson(self::CATEGORIA, $this->configuracion($ubicacion, $accesorio, [], [$e($suelto)]))->assertUnprocessable();
        $this->assertSame('La excepción es de un accesorio que no está marcado.', $this->error($r, 'exceptions.0.accessory_id'));
        $r = $this->putJson(self::CATEGORIA, $this->configuracion($ubicacion, $accesorio, [], [$e($accesorio), $e($accesorio)]))->assertUnprocessable();
        $this->assertSame('Ese accesorio ya tiene una excepción en esa ubicación.', $this->error($r, 'exceptions.1.accessory_id'));
        $r = $this->putJson(self::CATEGORIA, $this->configuracion($ubicacion, $accesorio, ['state' => 'deshabilitada'], [$e($accesorio)]))->assertUnprocessable();
        $this->assertSame('No se puede crear una excepción en una ubicación deshabilitada.', $this->error($r, 'exceptions.0.location_id'));
    }

    public function test_los_valores_fuera_de_rango_se_rechazan_con_mensajes_en_espanol(): void
    {
        [, $ubicacion, $accesorio] = $this->base();

        $r = $this->putJson(self::CATEGORIA, $this->configuracion($ubicacion, $accesorio, ['priority' => 0, 'preset' => $this->geometria(['x' => 140, 'width' => 0, 'rot_x' => 120, 'rot_y' => 200])]))->assertUnprocessable();
        $this->assertSame('La prioridad debe estar entre 1 y 99.', $this->error($r, 'locations.0.priority'));
        $this->assertSame('La posición horizontal debe estar entre 0 y 100.', $this->error($r, 'locations.0.preset.x'));
        $this->assertSame('El ancho debe estar entre 1 y 100.', $this->error($r, 'locations.0.preset.width'));
        $this->assertSame('La inclinación debe estar entre -90 y 90 grados.', $this->error($r, 'locations.0.preset.rot_x'));
        $this->assertSame('El giro izquierda/derecha debe estar entre -180 y 180 grados.', $this->error($r, 'locations.0.preset.rot_y'));

        $this->putJson(self::CATEGORIA, ['locations' => [['location_id' => 999, 'state' => 'rara', 'priority' => 1, 'layer' => 1]], 'accessory_ids' => [999], 'exceptions' => []])->assertUnprocessable();
        $this->putJson(self::CATEGORIA, [])->assertUnprocessable()->assertJsonPath('errors.locations.0', 'Faltan las ubicaciones.');
    }

    // ------------------------------------------------------------ configuración por producto

    public function test_el_producto_usa_la_configuracion_de_su_categoria_hasta_que_se_marca_propia(): void
    {
        [, $ubicacion, $accesorio] = $this->base();
        $this->putJson(self::CATEGORIA, $this->configuracion($ubicacion, $accesorio, ['priority' => 4]))->assertOk();
        $producto = '/api/v1/admin/configurator/products/PRO-DOCTOR';

        $this->getJson($producto)->assertOk()->assertJsonPath('uses_own_configurator', false)->assertJsonPath('source', 'category')
            ->assertJsonPath('category_slug', 'profesiones-y-hobbies')->assertJsonPath('config.locations.0.priority', 4);

        // Mientras use la de su categoría no se puede editar la del producto
        $this->putJson($producto, $this->configuracion($ubicacion, $accesorio))->assertStatus(409)->assertJsonPath('code', 'in_use');

        $this->putJson("$producto/mode", [])->assertUnprocessable();
        $this->putJson("$producto/mode", ['uses_own_configurator' => true, 'copy_from_category' => true])->assertOk()
            ->assertJsonPath('uses_own_configurator', true)->assertJsonPath('source', 'product')->assertJsonPath('has_config', true)->assertJsonPath('config.locations.0.priority', 4);

        $this->putJson($producto, $this->configuracion($ubicacion, $accesorio, ['priority' => 9]))->assertOk()->assertJsonPath('config.locations.0.priority', 9);
        // La de la categoría y la de otro producto de la misma categoría no cambian
        $lista = $this->getJson(self::CATEGORIA)->assertJsonPath('config.locations.0.priority', 4)->json('products');
        $this->assertTrue(collect($lista)->firstWhere('sku', 'PRO-DOCTOR')['uses_own_configurator']);
        $this->getJson('/api/v1/admin/configurator/products/PRO-ENFERMERA')->assertJsonPath('config.locations.0.priority', 4);

        // Volver a la categoría conserva la configuración propia guardada, pero deja de usarse
        $this->putJson("$producto/mode", ['uses_own_configurator' => false])->assertOk()->assertJsonPath('source', 'category')->assertJsonPath('config.locations.0.priority', 4);
        $this->putJson("$producto/mode", ['uses_own_configurator' => true])->assertOk()->assertJsonPath('config.locations.0.priority', 9);
        $this->getJson('/api/v1/admin/configurator/products/PRO-NO-EXISTE')->assertNotFound();
    }

    public function test_pasar_a_propia_sin_copiar_parte_de_cero(): void
    {
        [, $ubicacion, $accesorio] = $this->base();
        $this->putJson(self::CATEGORIA, $this->configuracion($ubicacion, $accesorio))->assertOk();

        $this->putJson('/api/v1/admin/configurator/products/PRO-DOCTOR/mode', ['uses_own_configurator' => true])->assertOk()
            ->assertJsonPath('has_config', false)->assertJsonPath('config.accessory_ids', []);
    }

    // ------------------------------------------------------------ API pública (personalizador)

    public function test_la_api_publica_entrega_lo_de_la_categoria_o_lo_propio_y_solo_accesorios_activos(): void
    {
        [$categoria, $ubicacion, $accesorio] = $this->base();
        $oculto = $this->postJson('/api/v1/admin/accessories', ['name' => 'Oculto', 'accessory_category_id' => $categoria])->json('id');
        $this->postJson("/api/v1/admin/accessories/$accesorio/image", ['image' => $this->imagen(), 'alt' => 'Gafas negras'])->assertCreated();
        $configuracion = $this->configuracion($ubicacion, $accesorio);
        $configuracion['accessory_ids'][] = $oculto;
        $this->putJson(self::CATEGORIA, $configuracion)->assertOk();
        $this->putJson("/api/v1/admin/accessories/$oculto", ['is_active' => false])->assertOk();

        // Sin sesión: es pública
        $this->app['auth']->forgetGuards();
        $r = $this->getJson('/api/v1/products/doctor/configurator')->assertOk()->assertJsonPath('source', 'category')->assertJsonPath('configured', true);
        $this->assertSame(['Gafas'], array_column($r->json('accessories'), 'name'));
        $this->assertSame('Moda', $r->json('accessories.0.category'));
        $this->assertSame('Gafas negras', $r->json('accessories.0.alt'));
        $this->assertSame(['Moda'], $r->json('locations.0.allowed_categories'));
        $this->assertEquals(40.0, $r->json('locations.0.preset.x'));
        $r->assertJsonMissingPath('accessories.0.is_active');

        // Un producto con configuración propia recibe la suya
        $this->admin()->putJson('/api/v1/admin/configurator/products/PRO-DOCTOR/mode', ['uses_own_configurator' => true])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/products/doctor/configurator')->assertOk()->assertJsonPath('source', 'product')->assertJsonPath('configured', false)->assertJsonCount(1, 'accessories');
        $this->getJson('/api/v1/products/enfermera/configurator')->assertOk()->assertJsonPath('source', 'category')->assertJsonCount(1, 'accessories');
    }

    public function test_la_api_publica_responde_404_si_el_producto_no_existe_o_esta_inactivo(): void
    {
        $this->getJson('/api/v1/products/no-existe/configurator')->assertNotFound()->assertJsonPath('code', 'not_found');

        ProductoModelo::query()->where('slug', 'doctor')->update(['is_active' => false]);
        $this->getJson('/api/v1/products/doctor/configurator')->assertNotFound();
    }

    public function test_un_producto_sin_configuracion_devuelve_las_ubicaciones_libres_y_todos_los_accesorios_activos(): void
    {
        $this->base();

        $this->getJson('/api/v1/products/homero/configurator')->assertOk()->assertJsonPath('configured', false)->assertJsonCount(1, 'accessories')
            ->assertJsonPath('locations.0.state', 'libre')->assertJsonPath('locations.0.preset', null);
    }

    public function test_el_cliente_ve_todos_los_accesorios_activos_aunque_no_esten_marcados(): void
    {
        [$categoria, , $gafas] = $this->base();
        $this->admin()->postJson('/api/v1/admin/accessories', ['name' => 'Regalo', 'accessory_category_id' => $categoria])->assertCreated();
        $oculto = $this->postJson('/api/v1/admin/accessories', ['name' => 'Oculto', 'accessory_category_id' => $categoria, 'is_active' => false])->assertCreated()->json('id');

        $r = $this->getJson('/api/v1/products/doctor/configurator')->assertOk();

        $this->assertSame(['Gafas', 'Regalo'], array_column($r->json('accessories'), 'name'));
        $this->assertNotContains($oculto, array_column($r->json('accessories'), 'id'));
        $this->assertContains($gafas, array_column($r->json('accessories'), 'id'));
    }

    public function test_la_imagen_para_personalizar_del_producto_llega_al_configurador_y_al_cliente(): void
    {
        $ruta = '/api/v1/admin/products/PRO-DOCTOR/personalization-image';

        $this->admin()->getJson('/api/v1/admin/configurator/products/PRO-DOCTOR')->assertOk()->assertJsonPath('base_image', null);
        $this->getJson('/api/v1/products/doctor/configurator')->assertOk()->assertJsonPath('base_image', null);

        $url = $this->postJson($ruta, ['image' => $this->imagen()])->assertCreated()->json('personalization_image');

        // La ven el listado de la categoría, el producto y el cliente; otro producto no la tiene
        $categoria = $this->getJson(self::CATEGORIA)->assertOk();
        $this->assertSame($url, collect($categoria->json('products'))->firstWhere('sku', 'PRO-DOCTOR')['base_image']);
        $this->assertNull(collect($categoria->json('products'))->firstWhere('sku', 'PRO-ENFERMERA')['base_image']);
        $this->getJson('/api/v1/admin/configurator/products/PRO-DOCTOR')->assertJsonPath('base_image', $url);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/products/doctor/configurator')->assertOk()->assertJsonPath('base_image', $url);
        $this->getJson('/api/v1/products/enfermera/configurator')->assertOk()->assertJsonPath('base_image', null);
    }

    public function test_el_configurador_ya_no_sube_imagenes(): void
    {
        $this->admin()->postJson('/api/v1/admin/configurator/products/PRO-DOCTOR/base-image', ['image' => $this->imagen()])->assertNotFound();
        $this->deleteJson('/api/v1/admin/configurator/products/PRO-DOCTOR/base-image')->assertStatus(404);
        $this->assertCount(0, Storage::disk('media')->allFiles('personalization'));
    }

    public function test_el_ajuste_de_la_imagen_se_guarda_por_producto_y_lo_ve_el_cliente(): void
    {
        $ruta = '/api/v1/admin/configurator/products/PRO-DOCTOR/base-adjust';
        $this->admin()->getJson('/api/v1/admin/configurator/products/PRO-DOCTOR')->assertJsonPath('base_adjust', ['scale' => 100, 'x' => 0, 'y' => 0]);

        // Se puede ajustar aunque el producto use la configuración de su categoría
        $this->putJson($ruta, ['scale' => 112.5, 'x' => -3.25, 'y' => 4])->assertOk()
            ->assertJsonPath('base_adjust.scale', 112.5)->assertJsonPath('base_adjust.x', -3.25)->assertJsonPath('uses_own_configurator', false);

        $categoria = $this->getJson(self::CATEGORIA)->assertOk();
        $this->assertSame(112.5, collect($categoria->json('products'))->firstWhere('sku', 'PRO-DOCTOR')['base_adjust']['scale']);
        $this->assertSame(100.0, (float) collect($categoria->json('products'))->firstWhere('sku', 'PRO-ENFERMERA')['base_adjust']['scale']);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/products/doctor/configurator')->assertOk()->assertJsonPath('base_adjust.scale', 112.5)->assertJsonPath('base_adjust.y', 4);
        $this->getJson('/api/v1/products/enfermera/configurator')->assertOk()->assertJsonPath('base_adjust.scale', 100);
    }

    public function test_el_ajuste_de_la_imagen_valida_rangos_en_espanol_y_solo_lo_hace_el_administrador(): void
    {
        $ruta = '/api/v1/admin/configurator/products/PRO-DOCTOR/base-adjust';
        $this->putJson($ruta, ['scale' => 100, 'x' => 0, 'y' => 0])->assertUnauthorized();

        $this->admin()->putJson($ruta, [])->assertUnprocessable()->assertJsonPath('errors.scale.0', 'Indica la escala de la imagen.');
        $this->putJson($ruta, ['scale' => 10, 'x' => 0, 'y' => 0])->assertUnprocessable()->assertJsonPath('errors.scale.0', 'La escala debe estar entre 50 % y 200 %.');
        $this->putJson($ruta, ['scale' => 100, 'x' => 80, 'y' => 0])->assertUnprocessable()->assertJsonPath('errors.x.0', 'El desplazamiento horizontal debe estar entre -50 y 50.');
        $this->putJson($ruta, ['scale' => 100, 'x' => 0, 'y' => 'mucho'])->assertUnprocessable()->assertJsonPath('errors.y.0', 'El desplazamiento vertical debe ser un número.');
        $this->putJson('/api/v1/admin/configurator/products/PRO-NO-EXISTE/base-adjust', ['scale' => 100, 'x' => 0, 'y' => 0])->assertNotFound();
    }
    public function test_la_ultima_edicion_se_guarda_en_la_base_por_administrador(): void
    {
        $ruta = '/api/v1/admin/configurator/last-edit';
        $this->getJson($ruta)->assertUnauthorized();

        $this->admin()->getJson($ruta)->assertOk()->assertExactJson(['category_slug' => null, 'product_sku' => null]);

        $this->putJson($ruta, ['category_slug' => 'profesiones-y-hobbies', 'product_sku' => 'PRO-DOCTOR'])->assertOk()
            ->assertExactJson(['category_slug' => 'profesiones-y-hobbies', 'product_sku' => 'PRO-DOCTOR']);
        $this->getJson($ruta)->assertOk()->assertJsonPath('product_sku', 'PRO-DOCTOR');

        // Solo la categoría: «toda la categoría»
        $this->putJson($ruta, ['category_slug' => 'profesiones-y-hobbies', 'product_sku' => null])->assertOk()->assertJsonPath('product_sku', null);
        $this->assertDatabaseCount('configurator_last_edits', 1);

        // Otro administrador no ve lo de este
        $this->putJson($ruta, ['category_slug' => 'profesiones-y-hobbies', 'product_sku' => 'PRO-DOCTOR'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->admin()->getJson($ruta)->assertOk()->assertJsonPath('category_slug', null);
    }

    public function test_la_ultima_edicion_rechaza_categorias_o_productos_que_no_existen(): void
    {
        $ruta = '/api/v1/admin/configurator/last-edit';
        $this->admin()->putJson($ruta, ['category_slug' => 'no-existe'])->assertNotFound();
        $this->putJson($ruta, ['category_slug' => 'profesiones-y-hobbies', 'product_sku' => 'PRO-NO-EXISTE'])->assertNotFound();
        // Un producto sin su categoría no se puede recordar
        $this->putJson($ruta, ['product_sku' => 'PRO-DOCTOR'])->assertNotFound();
    }

    public function test_cada_producto_puede_tener_su_propio_accesorio_fijo_en_una_ubicacion_de_la_categoria(): void
    {
        [$categoria, $ubicacion, $gafas] = $this->base();
        $corazon = $this->postJson('/api/v1/admin/accessories', ['name' => 'Corazón', 'accessory_category_id' => $categoria])->json('id');
        $balanza = $this->postJson('/api/v1/admin/accessories', ['name' => 'Balanza', 'accessory_category_id' => $categoria])->json('id');
        $this->putJson(self::CATEGORIA, $this->configuracion($ubicacion, $gafas))->assertOk();
        $doctor = '/api/v1/admin/configurator/products/PRO-DOCTOR';
        $ruta = fn (string $sku): string => "/api/v1/admin/configurator/products/$sku/fixed-accessories/$ubicacion";

        $this->putJson($ruta('PRO-DOCTOR'), ['accessory_id' => $corazon])->assertOk()
            ->assertJsonPath('config.locations.0.state', 'fija')->assertJsonPath('config.locations.0.fixed_accessory_id', $corazon)
            ->assertJsonPath('fixed_accessories', [['location_id' => $ubicacion, 'accessory_id' => $corazon]]);
        $this->putJson($ruta('PRO-ENFERMERA'), ['accessory_id' => $balanza])->assertOk();

        // Cambiarlo reemplaza; la posición y el tamaño siguen siendo los de la categoría
        $this->putJson($ruta('PRO-DOCTOR'), ['accessory_id' => $balanza])->assertOk()->assertJsonPath('config.locations.0.fixed_accessory_id', $balanza);
        $this->assertDatabaseCount('product_fixed_accessories', 2);
        $this->putJson($ruta('PRO-DOCTOR'), ['accessory_id' => $corazon])->assertOk();
        $this->getJson($doctor)->assertJsonPath('config.locations.0.preset.x', 40);

        // La categoría y los demás productos no cambian
        $this->getJson(self::CATEGORIA)->assertJsonPath('config.locations.0.state', 'libre');
        $this->getJson('/api/v1/admin/configurator/products/PRO-ENFERMERA')->assertJsonPath('config.locations.0.fixed_accessory_id', $balanza);

        // El cliente recibe la ubicación fija con el accesorio de su producto
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/products/doctor/configurator')->assertOk()
            ->assertJsonPath('locations.0.state', 'fija')->assertJsonPath('locations.0.fixed_accessory_id', $corazon);
        $this->getJson('/api/v1/products/enfermera/configurator')->assertJsonPath('locations.0.fixed_accessory_id', $balanza);

        // Quitarlo vuelve a lo que diga la categoría; un accesorio en uso por un producto no se elimina
        $this->admin()->deleteJson("/api/v1/admin/accessories/$balanza")->assertStatus(409)->assertJsonPath('code', 'in_use');
        $this->putJson($ruta('PRO-DOCTOR'), ['accessory_id' => null])->assertOk()->assertJsonPath('config.locations.0.state', 'libre')->assertJsonPath('fixed_accessories', []);
    }

    public function test_el_accesorio_fijo_del_producto_valida_ubicacion_accesorio_y_modo(): void
    {
        [$categoria, $ubicacion, $gafas] = $this->base();
        $otra = $this->admin()->postJson('/api/v1/admin/accessory-categories', ['name' => 'Objetos'])->json('id');
        $regalo = $this->postJson('/api/v1/admin/accessories', ['name' => 'Regalo', 'accessory_category_id' => $otra])->json('id');
        $ruta = "/api/v1/admin/configurator/products/PRO-DOCTOR/fixed-accessories/$ubicacion";

        // La categoría aún no define la ubicación
        $this->putJson($ruta, ['accessory_id' => $gafas])->assertUnprocessable()->assertJsonPath('errors.location_id.0', fn ($m) => str_contains($m, 'aún no define'));

        $this->putJson(self::CATEGORIA, $this->configuracion($ubicacion, $gafas))->assertOk();
        // «Cabeza» solo admite Moda
        $this->putJson($ruta, ['accessory_id' => $regalo])->assertUnprocessable()->assertJsonPath('errors.accessory_id.0', fn ($m) => str_contains($m, 'no admite'));
        $this->putJson($ruta, [])->assertUnprocessable();
        $this->putJson($ruta, ['accessory_id' => 99999])->assertNotFound();
        $this->putJson("/api/v1/admin/configurator/products/PRO-NO-EXISTE/fixed-accessories/$ubicacion", ['accessory_id' => $gafas])->assertNotFound();
        $this->putJson("/api/v1/admin/configurator/products/PRO-DOCTOR/fixed-accessories/99999", ['accessory_id' => $gafas])->assertNotFound();

        // Ubicación deshabilitada en la categoría
        $this->putJson(self::CATEGORIA, $this->configuracion($ubicacion, $gafas, ['state' => 'deshabilitada']))->assertOk();
        $this->putJson($ruta, ['accessory_id' => $gafas])->assertUnprocessable();

        // Con configuración propia se define dentro de ella
        $this->putJson(self::CATEGORIA, $this->configuracion($ubicacion, $gafas))->assertOk();
        $this->putJson('/api/v1/admin/configurator/products/PRO-DOCTOR/mode', ['uses_own_configurator' => true])->assertOk();
        $this->putJson($ruta, ['accessory_id' => $gafas])->assertUnprocessable();

        // Solo el administrador
        $this->app['auth']->forgetGuards();
        $this->putJson($ruta, ['accessory_id' => $gafas])->assertUnauthorized();
    }
}
