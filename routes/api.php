<?php

use App\Core\Administracion\Presentacion\Controladores\ControladorSesionAdministrador;
use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionCategorias;
use App\Core\Catalogo\Presentacion\Controladores\ControladorCampanas;
use App\Core\Catalogo\Presentacion\Controladores\ControladorCategorias;
use App\Core\Catalogo\Presentacion\Controladores\ControladorProductos;
use App\Core\Salud\Presentacion\Controladores\ControladorSalud;
use Illuminate\Support\Facades\Route;

// API versionada. Cada módulo agrega aquí su grupo de rutas.
Route::prefix('v1')->group(function () {
    Route::get('categories', [ControladorCategorias::class, 'index'])->name('api.v1.categories.index');
    Route::get('categories/{slug}', [ControladorCategorias::class, 'show'])->name('api.v1.categories.show');
    Route::get('products/{slug}', [ControladorProductos::class, 'show'])->name('api.v1.products.show');
    Route::get('campaigns/{slug}', [ControladorCampanas::class, 'show'])->name('api.v1.campaigns.show');
    // Administración (Angular del administrador). Todo grupo bajo `admin` exige un administrador autenticado.
    Route::post('admin/login', [ControladorSesionAdministrador::class, 'login'])->middleware('throttle:login')->name('api.v1.admin.login');
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('me', [ControladorSesionAdministrador::class, 'me'])->name('api.v1.admin.me');
        Route::post('logout', [ControladorSesionAdministrador::class, 'logout'])->name('api.v1.admin.logout');
        Route::post('logout-all', [ControladorSesionAdministrador::class, 'logoutAll'])->name('api.v1.admin.logout-all');
        Route::delete('categories/{slug}', [ControladorAdministracionCategorias::class, 'destroy'])->name('api.v1.admin.categories.destroy');
    });

    Route::get('health', ControladorSalud::class)->name('api.v1.health');
});
