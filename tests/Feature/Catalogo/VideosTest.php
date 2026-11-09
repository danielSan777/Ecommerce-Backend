<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogo;

use App\Core\Catalogo\Infraestructura\Modelos\MedioModelo;
use App\Models\User;
use Database\Seeders\CategoriasIniciales;
use Database\Seeders\ProductosDePrueba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Mp4DePrueba;
use Tests\TestCase;

/** Videos de producto (máx. 6 s), de banner de categoría (máx. 1 minuto) y portada de la página principal (imagen o video, máx. 1 minuto). */
final class VideosTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCTO = '/api/v1/admin/products/PRO-HOMERO/video';

    private string $categoria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductosDePrueba::class);
        Storage::fake('media');
        Storage::fake('private');
        $this->categoria = \App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo::query()->orderBy('id')->value('slug');
    }

    private function admin(): static
    {
        return $this->actingAs(User::factory()->administrador()->create(), 'api');
    }

    private function imagen(): UploadedFile
    {
        $gd = imagecreatetruecolor(800, 800);
        $ruta = tempnam(sys_get_temp_dir(), 'img');
        imagepng($gd, $ruta);
        imagedestroy($gd);

        return new UploadedFile($ruta, 'portada.png', null, null, true);
    }

    public function test_solo_el_administrador_sube_videos(): void
    {
        $this->postJson(self::PRODUCTO, ['video' => Mp4DePrueba::archivo()])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'api');
        $this->postJson(self::PRODUCTO, ['video' => Mp4DePrueba::archivo()])->assertForbidden();
        $this->assertSame(0, MedioModelo::query()->count());
    }

    public function test_producto_acepta_un_video_de_6_segundos_y_lo_guarda_sin_transcodificar(): void
    {
        $archivo = Mp4DePrueba::archivo(6.0, 720, 1280, relleno: 1000);
        $original = file_get_contents($archivo->getRealPath());

        $r = $this->admin()->postJson(self::PRODUCTO, ['video' => $archivo, 'alt' => 'Giro de la maceta'])->assertCreated();

        $r->assertJsonPath('type', 'video')->assertJsonPath('role', 'video')->assertJsonPath('duration_s', 6)
            ->assertJsonPath('width', 720)->assertJsonPath('height', 1280)->assertJsonMissingPath('urls.poster');
        $medio = MedioModelo::query()->firstOrFail();
        $ruta = $medio->variants['url'];
        $this->assertStringStartsWith('products/PRO-HOMERO/', $ruta);
        $this->assertStringEndsWith('.mp4', $ruta);
        $this->assertSame($original, Storage::disk('media')->get($ruta)); // se guarda tal cual
        $this->assertNull($medio->original_path);
    }

    public function test_producto_rechaza_video_de_mas_de_6_segundos(): void
    {
        $this->admin()->postJson(self::PRODUCTO, ['video' => Mp4DePrueba::archivo(8.0)])
            ->assertUnprocessable()->assertJsonValidationErrors('video');
        $this->admin()->postJson(self::PRODUCTO, ['video' => Mp4DePrueba::archivo(15.0)])
            ->assertJsonPath('errors.video.0', fn ($m) => str_contains($m, 'máximo 6 segundos') && str_contains($m, '15 s'));
        $this->assertSame(0, MedioModelo::query()->count());
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_tolera_un_cuarto_de_segundo_de_mas(): void
    {
        $this->admin()->postJson(self::PRODUCTO, ['video' => Mp4DePrueba::archivo(6.2)])->assertCreated();
    }

    public function test_rechaza_pesos_formatos_y_codecs_no_validos(): void
    {
        $this->admin();
        $this->postJson(self::PRODUCTO, ['video' => Mp4DePrueba::archivo(5, relleno: 2 * 1024 * 1024)])->assertJsonValidationErrors('video'); // pasa de 2 MB
        $this->postJson(self::PRODUCTO, ['video' => Mp4DePrueba::archivo(5, codec: 'hvc1')])
            ->assertJsonPath('errors.video.0', fn ($m) => str_contains($m, 'H.264'));
        $this->postJson(self::PRODUCTO, ['video' => Mp4DePrueba::archivo(5, marca: 'qt  ')])
            ->assertJsonPath('errors.video.0', fn ($m) => str_contains($m, 'MP4'));
        $this->postJson(self::PRODUCTO, ['video' => Mp4DePrueba::archivo(5, conMoov: false)])->assertJsonValidationErrors('video');

        $txt = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($txt, str_repeat('no soy un video', 10));
        $this->postJson(self::PRODUCTO, ['video' => new UploadedFile($txt, 'video.mp4', null, null, true)])->assertJsonValidationErrors('video');
        $this->postJson(self::PRODUCTO, [])->assertJsonValidationErrors('video');
        $this->assertSame(0, MedioModelo::query()->count());
    }

    public function test_el_video_con_audio_y_el_moov_al_final_se_acepta(): void
    {
        // El MP4 de prueba trae una pista de audio (0 × 0) antes de la de imagen: debe leerse el tamaño de la pista de imagen
        $this->admin()->postJson(self::PRODUCTO, ['video' => Mp4DePrueba::archivo(4, 1080, 1080)])->assertCreated()->assertJsonPath('width', 1080);
    }

    public function test_solo_un_video_por_producto_y_se_puede_reponer_tras_eliminarlo(): void
    {
        $id = $this->admin()->postJson(self::PRODUCTO, ['video' => Mp4DePrueba::archivo()])->assertCreated()->json('id');
        $this->postJson(self::PRODUCTO, ['video' => Mp4DePrueba::archivo()])->assertUnprocessable()->assertJsonValidationErrors('video');

        $ruta = MedioModelo::query()->find($id)->variants['url'];
        $this->deleteJson("/api/v1/admin/media/{$id}")->assertNoContent();
        $this->assertFalse(Storage::disk('media')->exists($ruta)); // se borra el archivo
        $this->postJson(self::PRODUCTO, ['video' => Mp4DePrueba::archivo()])->assertCreated();
    }

    public function test_portada_opcional_se_convierte_a_webp(): void
    {
        $r = $this->admin()->postJson(self::PRODUCTO, ['video' => Mp4DePrueba::archivo(), 'poster' => $this->imagen()])->assertCreated();
        $medio = MedioModelo::query()->firstOrFail();
        $this->assertStringEndsWith('-portada.webp', $medio->variants['poster']);
        $this->assertTrue(Storage::disk('media')->exists($medio->variants['poster']));
        $r->assertJsonStructure(['urls' => ['url', 'poster']]);

        // eliminar borra el video y su portada
        $this->deleteJson('/api/v1/admin/media/'.$medio->id)->assertNoContent();
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_una_portada_que_no_es_imagen_se_rechaza_sin_guardar_el_video(): void
    {
        $txt = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($txt, 'no es imagen');
        $this->admin()->postJson(self::PRODUCTO, ['video' => Mp4DePrueba::archivo(), 'poster' => new UploadedFile($txt, 'p.png', null, null, true)])
            ->assertUnprocessable();
        $this->assertSame(0, MedioModelo::query()->count());
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_una_imagen_no_puede_subirse_ni_cambiarse_a_tipo_video(): void
    {
        $this->admin()->postJson('/api/v1/admin/products/PRO-HOMERO/media', ['alt' => 'x', 'role' => 'video', 'image' => $this->imagen()])
            ->assertUnprocessable()->assertJsonValidationErrors('role');

        $img = $this->postJson('/api/v1/admin/products/PRO-HOMERO/media', ['alt' => 'Foto', 'role' => 'angle', 'image' => $this->imagen()])->assertCreated()->json('id');
        $this->putJson("/api/v1/admin/media/{$img}", ['role' => 'video'])->assertUnprocessable()->assertJsonValidationErrors('role');

        $vid = $this->postJson(self::PRODUCTO, ['video' => Mp4DePrueba::archivo()])->assertCreated()->json('id');
        $this->putJson("/api/v1/admin/media/{$vid}", ['role' => 'front'])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_el_video_de_producto_sale_en_la_api_publica(): void
    {
        $this->admin()->postJson(self::PRODUCTO, ['video' => Mp4DePrueba::archivo(5), 'poster' => $this->imagen()])->assertCreated();
        $medios = $this->getJson('/api/v1/products/homero')->assertOk()->json('data.media') ?? $this->getJson('/api/v1/products/homero')->json('media');
        $video = collect($medios)->firstWhere('type', 'video');
        $this->assertNotNull($video);
        $this->assertSame(5, $video['duration_s']);
        $this->assertStringEndsWith('.mp4', $video['url']);
        $this->assertNotNull($video['poster']);
    }

    public function test_categoria_acepta_video_de_hasta_un_minuto_y_lo_expone_como_hero_video(): void
    {
        $url = "/api/v1/admin/categories/{$this->categoria}/video";
        $this->admin()->postJson($url, ['video' => Mp4DePrueba::archivo(75.0, 1280, 720)])
            ->assertUnprocessable()->assertJsonPath('errors.video.0', fn ($m) => str_contains($m, 'un minuto') && str_contains($m, '75 s'));
        $this->postJson($url, ['video' => Mp4DePrueba::archivo(59.6, 1280, 720)])->assertCreated()->assertJsonPath('duration_s', 60);

        $r = $this->getJson("/api/v1/categories/{$this->categoria}")->assertOk();
        $r->assertJsonPath('hero_video.type', 'video')->assertJsonPath('hero_video.duration_s', 60);
        // La foto del banner sigue siendo la imagen (el video no la reemplaza)
        $this->assertNull($r->json('hero'));
    }

    public function test_la_imagen_del_banner_no_se_confunde_con_el_video(): void
    {
        $this->admin()->postJson("/api/v1/admin/categories/{$this->categoria}/media", ['alt' => 'Banner', 'role' => 'hero', 'image' => $this->imagen()])->assertCreated();
        $this->postJson("/api/v1/admin/categories/{$this->categoria}/video", ['video' => Mp4DePrueba::archivo(8, 1280, 720)])->assertCreated();

        $r = $this->getJson("/api/v1/categories/{$this->categoria}")->assertOk();
        $r->assertJsonPath('hero.type', 'image')->assertJsonPath('hero_video.type', 'video');
    }

    public function test_no_se_sube_video_a_una_campana(): void
    {
        $this->admin()->postJson('/api/v1/admin/campaigns/inexistente/video', ['video' => Mp4DePrueba::archivo()])->assertNotFound();
    }

    // ===== Portada de la página principal =====

    public function test_sin_portada_la_api_responde_200_con_null(): void
    {
        $this->getJson('/api/v1/home/hero')->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_solo_el_administrador_cambia_la_portada(): void
    {
        $this->postJson('/api/v1/admin/home/hero', ['image' => $this->imagen(), 'alt' => 'x'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'api');
        $this->postJson('/api/v1/admin/home/hero', ['image' => $this->imagen(), 'alt' => 'x'])->assertForbidden();
        $this->deleteJson('/api/v1/admin/home/hero')->assertForbidden();
    }

    public function test_la_portada_puede_ser_una_imagen_y_es_publica(): void
    {
        $this->admin()->postJson('/api/v1/admin/home/hero', ['image' => $this->imagen(), 'alt' => 'Maceta doctora'])->assertCreated()
            ->assertJsonPath('data.type', 'image');

        $r = $this->getJson('/api/v1/home/hero')->assertOk();
        $r->assertJsonPath('data.type', 'image')->assertJsonPath('data.alt', 'Maceta doctora')->assertJsonStructure(['data' => ['srcset' => ['600', '1080']]]);
        $this->assertNotEmpty(Storage::disk('media')->allFiles('home'));
    }

    public function test_la_portada_puede_ser_un_video_con_su_portada(): void
    {
        $this->admin()->postJson('/api/v1/admin/home/hero', ['video' => Mp4DePrueba::archivo(9), 'poster' => $this->imagen()])->assertCreated();

        $this->getJson('/api/v1/home/hero')->assertOk()
            ->assertJsonPath('data.type', 'video')->assertJsonPath('data.duration_s', 9)
            ->assertJsonStructure(['data' => ['url', 'poster']]);
    }

    public function test_el_video_de_la_portada_pasa_de_un_minuto_se_rechaza(): void
    {
        $this->admin()->postJson('/api/v1/admin/home/hero', ['video' => Mp4DePrueba::archivo(61)])->assertUnprocessable()->assertJsonValidationErrors('video');
        $this->postJson('/api/v1/admin/home/hero', ['video' => Mp4DePrueba::archivo(60)])->assertCreated();
        $this->deleteJson('/api/v1/admin/home/hero')->assertNoContent();
        $this->getJson('/api/v1/home/hero')->assertExactJson(['data' => null]);
    }

    public function test_subir_otra_portada_reemplaza_y_borra_los_archivos_anteriores(): void
    {
        $this->admin()->postJson('/api/v1/admin/home/hero', ['image' => $this->imagen(), 'alt' => 'Una'])->assertCreated();
        $antes = Storage::disk('media')->allFiles('home');
        $this->assertNotEmpty($antes);

        $this->postJson('/api/v1/admin/home/hero', ['video' => Mp4DePrueba::archivo(5)])->assertCreated();
        $despues = Storage::disk('media')->allFiles('home');
        $this->assertCount(1, $despues); // solo el video nuevo
        $this->assertEmpty(array_intersect($antes, $despues));
    }

    public function test_quitar_la_portada_borra_los_archivos_y_vuelve_a_la_imagen_de_siempre(): void
    {
        $this->admin()->postJson('/api/v1/admin/home/hero', ['video' => Mp4DePrueba::archivo(5), 'poster' => $this->imagen()])->assertCreated();
        $this->deleteJson('/api/v1/admin/home/hero')->assertNoContent();

        $this->assertSame([], Storage::disk('media')->allFiles('home'));
        $this->getJson('/api/v1/home/hero')->assertExactJson(['data' => null]);
    }

    public function test_la_portada_exige_una_imagen_o_un_video_pero_no_los_dos(): void
    {
        $this->admin()->postJson('/api/v1/admin/home/hero', [])->assertUnprocessable();
        $this->postJson('/api/v1/admin/home/hero', ['image' => $this->imagen(), 'video' => Mp4DePrueba::archivo(), 'alt' => 'x'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/home/hero', ['image' => $this->imagen()])->assertUnprocessable()->assertJsonValidationErrors('alt');
    }
}
