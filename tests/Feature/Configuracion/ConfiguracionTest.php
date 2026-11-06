<?php

declare(strict_types=1);

namespace Tests\Feature\Configuracion;

use App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion;
use Database\Seeders\AjustesIniciales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guarda_y_lee_texto_con_emojis_y_enie(): void
    {
        $repositorio = $this->app->make(RepositorioConfiguracion::class);
        $repositorio->guardar('mensaje', 'Año nuevo 🌿 niño');

        $this->assertSame('Año nuevo 🌿 niño', $repositorio->obtener('mensaje'));
    }

    public function test_devuelve_el_valor_por_defecto_si_la_clave_no_existe(): void
    {
        $this->assertSame('x', $this->app->make(RepositorioConfiguracion::class)->obtener('no-existe', 'x'));
    }

    public function test_los_parametros_iniciales_se_cargan_y_no_pisan_cambios_del_administrador(): void
    {
        $repositorio = $this->app->make(RepositorioConfiguracion::class);
        $repositorio->guardar('card_surcharge_percent', 7);

        $this->seed(AjustesIniciales::class);
        $this->seed(AjustesIniciales::class);

        $this->assertSame(7, $repositorio->obtener('card_surcharge_percent'));
        $this->assertSame(12, $repositorio->obtener('transfer_window_hours'));
        $this->assertSame(['A' => 350, 'B' => 625, 'C' => 500], $repositorio->obtener('shipping_rates_cents'));
    }

    public function test_la_zona_horaria_de_la_aplicacion_es_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));
        $this->assertSame('utf8mb4_unicode_ci', config('database.connections.mysql.collation'));
    }
}
