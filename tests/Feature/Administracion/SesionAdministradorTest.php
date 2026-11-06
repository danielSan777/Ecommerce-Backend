<?php

declare(strict_types=1);

namespace Tests\Feature\Administracion;

use App\Core\Administracion\Infraestructura\Modelos\TokenAdministrador;
use App\Models\User;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SesionAdministradorTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE = 'una-clave-larga-123';

    private function usuario(): User
    {
        return User::factory()->create(['email' => 'sele@jardindesele.com', 'password' => self::CLAVE]);
    }

    /** En producción cada petición es un proceso nuevo; en la prueba hay que olvidar el usuario ya resuelto. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['events']->listen(RequestHandled::class, fn () => $this->app['auth']->forgetGuards());
    }

    private function entrar(): string
    {
        $this->usuario();

        return $this->postJson('/api/v1/admin/login', ['email' => 'sele@jardindesele.com', 'password' => self::CLAVE])->assertOk()->json('token');
    }

    public function test_login_correcto_entrega_token_y_solo_guarda_su_hash(): void
    {
        $this->usuario();

        $r = $this->postJson('/api/v1/admin/login', ['email' => 'Sele@JardindeSele.com', 'password' => self::CLAVE, 'device' => 'Chrome'])->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'expires_at', 'user' => ['name', 'email']])->assertJsonPath('token_type', 'Bearer');

        $this->assertDatabaseMissing('admin_tokens', ['token_hash' => $r->json('token')]);
        $this->assertDatabaseHas('admin_tokens', ['token_hash' => hash('sha256', $r->json('token')), 'device' => 'Chrome']);
        $this->assertArrayNotHasKey('password', $r->json('user'));
    }

    public function test_credenciales_incorrectas_responden_lo_mismo_exista_o_no_el_correo(): void
    {
        $this->usuario();

        $a = $this->postJson('/api/v1/admin/login', ['email' => 'sele@jardindesele.com', 'password' => 'incorrecta'])->assertUnauthorized();
        $b = $this->postJson('/api/v1/admin/login', ['email' => 'otro@jardindesele.com', 'password' => 'incorrecta'])->assertUnauthorized();

        $this->assertSame($a->json(), $b->json());
        $a->assertJsonPath('code', 'invalid_credentials')->assertJsonPath('message', 'Correo o contraseña incorrectos.');
    }

    public function test_datos_faltantes_responden_422_en_espanol(): void
    {
        $this->postJson('/api/v1/admin/login', [])->assertStatus(422)->assertJsonPath('code', 'validation_error')
            ->assertJsonPath('errors.email.0', 'Escribe tu correo.')->assertJsonPath('errors.password.0', 'Escribe tu contraseña.');
    }

    public function test_el_token_abre_las_rutas_de_administracion(): void
    {
        $token = $this->entrar();

        $this->getJson('/api/v1/admin/me', ['Authorization' => "Bearer {$token}"])->assertOk()->assertJsonPath('user.email', 'sele@jardindesele.com');
        $this->getJson('/api/v1/admin/me', ['Authorization' => 'Bearer falso'])->assertUnauthorized();
        $this->getJson('/api/v1/admin/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }

    public function test_un_token_vencido_deja_de_servir(): void
    {
        $token = $this->entrar();
        TokenAdministrador::query()->update(['expires_at' => now()->subMinute()]);

        $this->getJson('/api/v1/admin/me', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
        $this->assertDatabaseCount('admin_tokens', 0);
    }

    public function test_logout_cierra_solo_esa_sesion_y_logout_all_cierra_todas(): void
    {
        $uno = $this->entrar();
        $dos = $this->postJson('/api/v1/admin/login', ['email' => 'sele@jardindesele.com', 'password' => self::CLAVE])->json('token');

        $this->postJson('/api/v1/admin/logout', [], ['Authorization' => "Bearer {$uno}"])->assertNoContent();
        $this->getJson('/api/v1/admin/me', ['Authorization' => "Bearer {$uno}"])->assertUnauthorized();
        $this->getJson('/api/v1/admin/me', ['Authorization' => "Bearer {$dos}"])->assertOk();

        $tres = $this->postJson('/api/v1/admin/login', ['email' => 'sele@jardindesele.com', 'password' => self::CLAVE])->json('token');
        $this->postJson('/api/v1/admin/logout-all', [], ['Authorization' => "Bearer {$tres}"])->assertNoContent();
        $this->getJson('/api/v1/admin/me', ['Authorization' => "Bearer {$dos}"])->assertUnauthorized();
        $this->assertDatabaseCount('admin_tokens', 0);
    }

    public function test_el_login_limita_los_intentos(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/admin/login', ['email' => 'x@x.com', 'password' => 'mala'])->assertUnauthorized();
        }

        $this->postJson('/api/v1/admin/login', ['email' => 'x@x.com', 'password' => 'mala'])->assertStatus(429);
    }

    public function test_el_comando_crea_el_administrador_y_pide_la_clave_por_consola(): void
    {
        $this->artisan('admin:crear', ['email' => 'nuevo@jardindesele.com', '--nombre' => 'Sele'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'clave-muy-larga-1')->expectsQuestion('Repite la contraseña', 'clave-muy-larga-1')
            ->assertSuccessful();

        $this->postJson('/api/v1/admin/login', ['email' => 'nuevo@jardindesele.com', 'password' => 'clave-muy-larga-1'])->assertOk()->assertJsonPath('user.name', 'Sele');
    }

    public function test_el_comando_rechaza_claves_cortas(): void
    {
        $this->artisan('admin:crear', ['email' => 'nuevo@jardindesele.com'])->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'corta')->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_cors_solo_permite_el_origen_del_frontend(): void
    {
        config(['cors.allowed_origins' => ['https://jardindesele.com']]);

        $permitido = $this->call('OPTIONS', '/api/v1/admin/login', [], [], [], ['HTTP_ORIGIN' => 'https://jardindesele.com', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
        $this->assertSame('https://jardindesele.com', $permitido->headers->get('Access-Control-Allow-Origin'));

        $ajeno = $this->call('OPTIONS', '/api/v1/admin/login', [], [], [], ['HTTP_ORIGIN' => 'https://malo.com', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
        // El navegador solo acepta la respuesta si el origen coincide: para otro sitio nunca se devuelve su origen ni '*'.
        $this->assertNotContains($ajeno->headers->get('Access-Control-Allow-Origin'), ['https://malo.com', '*']);
    }
}
