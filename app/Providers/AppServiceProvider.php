<?php

namespace App\Providers;

use App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion;
use App\Core\Configuracion\Infraestructura\Repositorios\RepositorioConfiguracionEloquent;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(RepositorioConfiguracion::class, RepositorioConfiguracionEloquent::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Límites de intentos del Paso 4 (por IP real). Los módulos los aplican con ->middleware('throttle:nombre').
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('comprobantes', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('consultar-pedido', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('fotos', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
    }
}
