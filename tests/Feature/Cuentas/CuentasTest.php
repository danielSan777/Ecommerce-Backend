<?php

declare(strict_types=1);

namespace Tests\Feature\Cuentas;

use App\Core\Cuentas\Infraestructura\Modelos\TokenDeAcceso;
use App\Models\User;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CuentasTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE = 'una-clave-larga-123';

    private function usuario(): User
    {
        return User::factory()->administrador()->create(['email' => 'sele@jardindesele.com', 'password' => self::CLAVE]);
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

        return $this->postJson('/api/v1/auth/login', ['email' => 'sele@jardindesele.com', 'password' => self::CLAVE])->assertOk()->json('token');
    }

    public function test_login_correcto_entrega_token_y_solo_guarda_su_hash(): void
    {
        $this->usuario();

        $r = $this->postJson('/api/v1/auth/login', ['email' => 'Sele@JardindeSele.com', 'password' => self::CLAVE, 'device' => 'Chrome'])->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'expires_at', 'expires_in', 'user' => ['name', 'email', 'role']])->assertJsonPath('user.role', 'admin')->assertJsonPath('token_type', 'Bearer');

        $this->assertEqualsWithDelta(12 * 3600, $r->json('expires_in'), 5);
        $this->assertDatabaseMissing('api_tokens', ['token_hash' => $r->json('token')]);
        $this->assertDatabaseHas('api_tokens', ['token_hash' => hash('sha256', $r->json('token')), 'device' => 'Chrome']);
        $this->assertArrayNotHasKey('password', $r->json('user'));
    }

    public function test_credenciales_incorrectas_responden_lo_mismo_exista_o_no_el_correo(): void
    {
        $this->usuario();

        $a = $this->postJson('/api/v1/auth/login', ['email' => 'sele@jardindesele.com', 'password' => 'incorrecta'])->assertUnauthorized();
        $b = $this->postJson('/api/v1/auth/login', ['email' => 'otro@jardindesele.com', 'password' => 'incorrecta'])->assertUnauthorized();

        $this->assertSame($a->json(), $b->json());
        $a->assertJsonPath('code', 'invalid_credentials')->assertJsonPath('message', 'Correo o contraseña incorrectos.');
    }

    public function test_datos_faltantes_responden_422_en_espanol(): void
    {
        $this->postJson('/api/v1/auth/login', [])->assertStatus(422)->assertJsonPath('code', 'validation_error')
            ->assertJsonPath('errors.email.0', 'Escribe tu correo.')->assertJsonPath('errors.password.0', 'Escribe tu contraseña.');
    }

    public function test_el_token_abre_las_rutas_de_administracion(): void
    {
        $token = $this->entrar();

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertOk()->assertJsonPath('user.email', 'sele@jardindesele.com');
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer falso'])->assertUnauthorized();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }

    public function test_un_token_vencido_deja_de_servir(): void
    {
        $token = $this->entrar();
        TokenDeAcceso::query()->update(['expires_at' => now()->subMinute()]);

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_logout_cierra_solo_esa_sesion_y_logout_all_cierra_todas(): void
    {
        $uno = $this->entrar();
        $dos = $this->postJson('/api/v1/auth/login', ['email' => 'sele@jardindesele.com', 'password' => self::CLAVE])->json('token');

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$uno}"])->assertNoContent();
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$uno}"])->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$dos}"])->assertOk();

        $tres = $this->postJson('/api/v1/auth/login', ['email' => 'sele@jardindesele.com', 'password' => self::CLAVE])->json('token');
        $this->postJson('/api/v1/auth/logout-all', [], ['Authorization' => "Bearer {$tres}"])->assertNoContent();
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$dos}"])->assertUnauthorized();
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_el_login_limita_los_intentos(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'x@x.com', 'password' => 'mala'])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'x@x.com', 'password' => 'mala'])->assertStatus(429);
    }

    public function test_sin_configuracion_de_horas_el_token_dura_12_horas_y_no_nace_vencido(): void
    {
        $this->usuario();
        config(['auth.token_hours' => 0]);

        $r = $this->postJson('/api/v1/auth/login', ['email' => 'sele@jardindesele.com', 'password' => self::CLAVE])->assertOk();

        $this->assertEqualsWithDelta(12 * 3600, $r->json('expires_in'), 5);
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$r->json('token')])->assertOk();
    }

    public function test_el_login_de_un_cliente_devuelve_rol_customer(): void
    {
        User::factory()->create(['email' => 'ana@correo.com', 'password' => self::CLAVE]);

        $this->postJson('/api/v1/auth/login', ['email' => 'ana@correo.com', 'password' => self::CLAVE])->assertOk()->assertJsonPath('user.role', 'customer');
    }

    public function test_registro_crea_un_cliente_con_sesion_y_guarda_la_clave_cifrada(): void
    {
        $r = $this->postJson('/api/v1/auth/register', ['name' => 'Ana Pérez', 'email' => 'Ana@Correo.com', 'password' => 'clave-segura-1', 'password_confirmation' => 'clave-segura-1'])
            ->assertCreated()->assertJsonPath('user.role', 'customer')->assertJsonPath('user.email', 'ana@correo.com');

        $usuario = User::query()->where('email', 'ana@correo.com')->firstOrFail();
        $this->assertSame('customer', $usuario->role);
        $this->assertNotSame('clave-segura-1', $usuario->password);
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$r->json('token')])->assertOk()->assertJsonPath('user.name', 'Ana Pérez');
    }

    public function test_el_registro_nunca_permite_elegirse_administrador(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'Intruso', 'email' => 'x@correo.com', 'password' => 'clave-segura-1', 'password_confirmation' => 'clave-segura-1', 'role' => 'admin'])
            ->assertCreated()->assertJsonPath('user.role', 'customer');

        $this->assertSame('customer', User::query()->where('email', 'x@correo.com')->value('role'));
    }

    public function test_el_registro_valida_en_espanol(): void
    {
        $this->usuario();

        $r = $this->postJson('/api/v1/auth/register', ['name' => 'A', 'email' => 'sele@jardindesele.com', 'password' => 'corta', 'password_confirmation' => 'otra'])->assertStatus(422);

        $r->assertJsonPath('code', 'validation_error')->assertJsonPath('errors.email.0', 'Ya existe una cuenta con ese correo. Inicia sesión.')
            ->assertJsonPath('errors.name.0', 'Escribe tu nombre completo.');
        $this->assertContains('La contraseña debe tener al menos 8 caracteres.', $r->json('errors.password'));
    }

    public function test_un_cliente_recibe_403_en_la_administracion_y_el_administrador_pasa(): void
    {
        $cliente = User::factory()->create(['email' => 'ana@correo.com', 'password' => self::CLAVE]);
        $tokenCliente = $this->postJson('/api/v1/auth/login', ['email' => 'ana@correo.com', 'password' => self::CLAVE])->json('token');
        $tokenAdmin = $this->entrar();

        $this->deleteJson('/api/v1/admin/categories/no-existe', [], ['Authorization' => "Bearer {$tokenCliente}"])->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->deleteJson('/api/v1/admin/categories/no-existe', [], ['Authorization' => "Bearer {$tokenAdmin}"])->assertNotFound();
        $this->assertNotNull($cliente);
    }

    public function test_el_comando_crea_el_administrador_y_pide_la_clave_por_consola(): void
    {
        $this->artisan('admin:crear', ['email' => 'nuevo@jardindesele.com', '--nombre' => 'Sele'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'clave-muy-larga-1')->expectsQuestion('Repite la contraseña', 'clave-muy-larga-1')
            ->assertSuccessful();

        $this->postJson('/api/v1/auth/login', ['email' => 'nuevo@jardindesele.com', 'password' => 'clave-muy-larga-1'])->assertOk()->assertJsonPath('user.name', 'Sele')->assertJsonPath('user.role', 'admin');
    }

    public function test_el_comando_rechaza_claves_cortas(): void
    {
        $this->artisan('admin:crear', ['email' => 'nuevo@jardindesele.com'])->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'corta')->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_cors_solo_permite_el_origen_del_frontend(): void
    {
        config(['cors.allowed_origins' => ['https://jardindesele.com']]);

        $permitido = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], ['HTTP_ORIGIN' => 'https://jardindesele.com', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
        $this->assertSame('https://jardindesele.com', $permitido->headers->get('Access-Control-Allow-Origin'));

        $ajeno = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], ['HTTP_ORIGIN' => 'https://malo.com', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
        // El navegador solo acepta la respuesta si el origen coincide: para otro sitio nunca se devuelve su origen ni '*'.
        $this->assertNotContains($ajeno->headers->get('Access-Control-Allow-Origin'), ['https://malo.com', '*']);
    }
}
