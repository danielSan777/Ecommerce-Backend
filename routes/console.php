<?php

use App\Core\Salud\Presentacion\Controladores\ControladorSalud;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ----- Scheduler del negocio (Paso 4) -----
// Hostinger corre `php artisan schedule:run` cada minuto. No hay Supervisor: la cola se procesa desde aquí.
Schedule::call(fn () => Cache::forever(ControladorSalud::CLAVE_SCHEDULER, now('UTC')->toIso8601String()))
    ->everyMinute()->name('salud-scheduler');

Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3 --timeout=30')
    ->everyMinute()->withoutOverlapping()->name('cola')
    ->after(fn () => Cache::forever(ControladorSalud::CLAVE_COLA, now('UTC')->toIso8601String()));
