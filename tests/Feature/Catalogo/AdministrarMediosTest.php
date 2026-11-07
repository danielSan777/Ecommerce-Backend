<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogo;

use App\Core\Catalogo\Infraestructura\Modelos\MedioModelo;
use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use App\Models\User;
use Database\Seeders\ProductosDePrueba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AdministrarMediosTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCTO = '/api/v1/admin/products/PRO-HOMERO/media';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductosDePrueba::class);
        Storage::fake('media');
        Storage::fake('private');
    }

    private function admin(): static
    {
        return $this->actingAs(User::factory()->administrador()->create(), 'api');
    }

    private function imagen(string $tipo = 'png', int $ancho = 1500, int $alto = 1000, string $nombre = 'foto.png'): UploadedFile
    {
        $gd = imagecreatetruecolor($ancho, $alto);
        imagefill($gd, 0, 0, (int) imagecolorallocate($gd, 200, 80, 40));
        $ruta = tempnam(sys_get_temp_dir(), 'img');
        match ($tipo) {
            'jpeg' => imagejpeg($gd, $ruta),
            'webp' => imagewebp($gd, $ruta),
            default => imagepng($gd, $ruta),
        };
        imagedestroy($gd);

        return new UploadedFile($ruta, $nombre, null, null, true);
    }

    public function test_solo_el_administrador_sube_imagenes(): void
    {
        $this->postJson(self::PRODUCTO, ['alt' => 'Descripción de prueba', 'image' => $this->imagen()])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'api');
        $this->postJson(self::PRODUCTO, ['alt' => 'Descripción de prueba', 'image' => $this->imagen()])->assertForbidden();
        $this->deleteJson('/api/v1/admin/media/1')->assertForbidden();
        $this->assertSame(0, MedioModelo::query()->count());
    }

    public function test_sube_genera_versiones_webp_y_guarda_solo_rutas_relativas(): void
    {
        $r = $this->admin()->post(self::PRODUCTO, ['image' => $this->imagen(), 'role' => 'front', 'alt' => 'Homero en su maceta'], ['Accept' => 'application/json'])
            ->assertCreated()->json();

        $medio = MedioModelo::query()->findOrFail($r['id']);
        $this->assertSame('image', $medio->type);
        $this->assertSame('Homero en su maceta', $medio->alt);
        $this->assertSame([1500, 1000], [$medio->width, $medio->height]);
        $this->assertSame([600, 1080], array_keys($medio->variants));

        foreach ($medio->variants as $ancho => $ruta) {
            $this->assertStringStartsWith('products/PRO-HOMERO/', $ruta);
            $this->assertStringEndsWith("-{$ancho}.webp", $ruta);
            $this->assertStringNotContainsString('http', $ruta);
            Storage::disk('media')->assertExists($ruta);
            $this->assertSame((int) $ancho, getimagesizefromstring(Storage::disk('media')->get($ruta))[0]);
            $this->assertSame('image/webp', getimagesizefromstring(Storage::disk('media')->get($ruta))['mime']);
        }
        // El original va al disco privado, no al público
        $this->assertStringStartsWith('originals/products/PRO-HOMERO/', $medio->original_path);
        Storage::disk('private')->assertExists($medio->original_path);
        $this->assertSame([600, 1080], array_keys($r['urls']));
    }

    public function test_no_agranda_imagenes_pequenas(): void
    {
        $r = $this->admin()->post(self::PRODUCTO, ['alt' => 'Descripción de prueba', 'image' => $this->imagen('jpeg', 800, 600, 'a.jpg')], ['Accept' => 'application/json'])->assertCreated()->json();
        $medio = MedioModelo::query()->findOrFail($r['id']);

        $this->assertSame(600, getimagesizefromstring(Storage::disk('media')->get($medio->variants['600']))[0]);
        $this->assertSame(800, getimagesizefromstring(Storage::disk('media')->get($medio->variants['1080']))[0]);
    }

    public function test_acepta_jpeg_y_webp(): void
    {
        $this->admin();
        $this->post(self::PRODUCTO, ['alt' => 'Descripción de prueba', 'image' => $this->imagen('jpeg', 700, 700, 'a.jpg'), 'role' => 'detail'], ['Accept' => 'application/json'])->assertCreated();
        $this->post(self::PRODUCTO, ['alt' => 'Descripción de prueba', 'image' => $this->imagen('webp', 700, 700, 'a.webp'), 'role' => 'detail'], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(2, MedioModelo::query()->count());
    }

    public function test_rechaza_archivos_que_no_son_imagenes_aunque_tengan_extension_de_imagen(): void
    {
        $falso = UploadedFile::fake()->createWithContent('truco.png', '<?php echo "hola"; ?>');

        $this->admin()->post(self::PRODUCTO, ['alt' => 'Descripción de prueba', 'image' => $falso], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'validation_error')
            ->assertJsonPath('errors.image.0', 'El archivo no es una imagen válida. Usa JPG, PNG o WebP.');
        $this->assertSame(0, MedioModelo::query()->count());
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_rechaza_gif_y_svg(): void
    {
        $this->admin();
        $svg = UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="500" height="500"><script>alert(1)</script></svg>');
        $this->post(self::PRODUCTO, ['alt' => 'Descripción de prueba', 'image' => $svg], ['Accept' => 'application/json'])->assertStatus(422);

        $gif = UploadedFile::fake()->createWithContent('a.gif', base64_decode('R0lGODlhAQABAAAAACw=', true));
        $this->post(self::PRODUCTO, ['alt' => 'Descripción de prueba', 'image' => $gif], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_rechaza_imagenes_muy_pequenas(): void
    {
        $this->admin()->post(self::PRODUCTO, ['alt' => 'Descripción de prueba', 'image' => $this->imagen('png', 100, 100)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.image.0', 'La imagen es muy pequeña. Debe medir al menos 300 × 300 píxeles.');
    }

    public function test_la_descripcion_es_obligatoria_al_subir_y_al_editar(): void
    {
        $this->admin()->post(self::PRODUCTO, ['image' => $this->imagen()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.alt.0', 'Escribe la descripción de la imagen.');
        $this->post(self::PRODUCTO, ['image' => $this->imagen(), 'alt' => '   '], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrorFor('alt', 'errors');
        $this->assertSame(0, MedioModelo::query()->count());
        $this->assertSame([], Storage::disk('media')->allFiles());

        $id = $this->post(self::PRODUCTO, ['image' => $this->imagen(), 'alt' => 'Homero'], ['Accept' => 'application/json'])->json('id');
        $this->putJson("/api/v1/admin/media/{$id}", ['alt' => ''])->assertStatus(422)->assertJsonPath('errors.alt.0', 'Escribe la descripción de la imagen.');
        $this->assertSame('Homero', MedioModelo::query()->findOrFail($id)->alt);
    }

    public function test_pide_la_imagen_y_valida_el_tipo_de_foto(): void
    {
        $this->admin()->postJson(self::PRODUCTO, [])->assertStatus(422)->assertJsonPath('errors.image.0', 'Elige una imagen.');
        $this->post(self::PRODUCTO, ['alt' => 'Descripción de prueba', 'image' => $this->imagen(), 'role' => 'otra'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.role.0', 'El tipo de imagen no es válido.');
    }

    public function test_producto_inexistente_responde_404(): void
    {
        $this->admin()->post('/api/v1/admin/products/NO-EXISTE/media', ['alt' => 'Descripción de prueba', 'image' => $this->imagen()], ['Accept' => 'application/json'])
            ->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_limite_de_imagenes_por_producto(): void
    {
        $this->admin();
        for ($i = 0; $i < 12; $i++) {
            $this->post(self::PRODUCTO, ['alt' => 'Descripción de prueba', 'image' => $this->imagen('png', 320, 320), 'role' => 'detail'], ['Accept' => 'application/json'])->assertCreated();
        }
        $this->post(self::PRODUCTO, ['alt' => 'Descripción de prueba', 'image' => $this->imagen('png', 320, 320), 'role' => 'detail'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.image.0', 'Ya hay 12 imágenes. Elimina alguna antes de subir otra.');
    }

    public function test_lista_edita_y_elimina_borrando_los_archivos(): void
    {
        $this->admin();
        $id = $this->post(self::PRODUCTO, ['alt' => 'Descripción de prueba', 'image' => $this->imagen()], ['Accept' => 'application/json'])->json('id');
        $medio = MedioModelo::query()->findOrFail($id);
        $rutas = [...array_values($medio->variants), $medio->original_path];

        $this->getJson(self::PRODUCTO)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->putJson("/api/v1/admin/media/{$id}", ['alt' => 'Nueva descripción', 'role' => 'detail'])
            ->assertOk()->assertJsonPath('alt', 'Nueva descripción')->assertJsonPath('role', 'detail');

        $this->deleteJson("/api/v1/admin/media/{$id}")->assertNoContent();
        $this->assertSame(0, MedioModelo::query()->count());
        Storage::disk('media')->assertMissing($rutas[0]);
        Storage::disk('media')->assertMissing($rutas[1]);
        Storage::disk('private')->assertMissing($rutas[2]);
        $this->deleteJson("/api/v1/admin/media/{$id}")->assertNotFound();
    }

    public function test_sube_imagen_de_categoria_y_aparece_en_la_api_publica(): void
    {
        $this->admin()->post('/api/v1/admin/categories/familia/media', ['alt' => 'Descripción de prueba', 'image' => $this->imagen(), 'role' => 'hero'], ['Accept' => 'application/json'])->assertCreated();

        $ruta = MedioModelo::query()->firstOrFail()->variants['600'];
        $this->assertStringStartsWith('categories/familia/', $ruta);
        $publico = $this->getJson('/api/v1/categories/familia')->assertOk()->json();
        $this->assertStringContainsString($ruta, json_encode($publico, JSON_UNESCAPED_SLASHES));
    }

    public function test_solo_una_imagen_puede_ser_la_principal_y_hay_que_liberar_la_actual(): void
    {
        $this->admin();
        $subir = fn (string $rol) => $this->post(self::PRODUCTO, ['image' => $this->imagen(), 'alt' => 'Homero', 'role' => $rol], ['Accept' => 'application/json']);
        $mensaje = 'Ya hay una imagen principal. Cambia primero su tipo (por ejemplo a "Otro ángulo") para marcar otra.';

        $a = $subir('front')->assertCreated()->json('id');
        $subir('front')->assertStatus(422)->assertJsonPath('errors.role.0', $mensaje);
        $this->assertSame(1, MedioModelo::query()->count());
        $this->assertCount(2, Storage::disk('media')->allFiles(), 'el rechazo no deja archivos sueltos');

        $c = $subir('detail')->assertCreated()->json('id');
        $this->putJson("/api/v1/admin/media/{$c}", ['role' => 'front'])->assertStatus(422)->assertJsonPath('errors.role.0', $mensaje);
        $this->assertSame('detail', MedioModelo::query()->findOrFail($c)->role);

        // Editar la propia principal (por ejemplo su descripción) no se bloquea a sí misma
        $this->putJson("/api/v1/admin/media/{$a}", ['role' => 'front', 'alt' => 'Nueva'])->assertOk();

        // Liberando la actual, otra puede ser la principal
        $this->putJson("/api/v1/admin/media/{$a}", ['role' => 'angle'])->assertOk();
        $this->putJson("/api/v1/admin/media/{$c}", ['role' => 'front'])->assertOk()->assertJsonPath('role', 'front');
        $this->assertSame($c, ProductoModelo::query()->where('sku', 'PRO-HOMERO')->firstOrFail()->medios->first()->id);

        // Al eliminar la principal, el tipo queda libre
        $this->deleteJson("/api/v1/admin/media/{$c}")->assertNoContent();
        $subir('front')->assertCreated();
    }
}
