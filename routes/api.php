<?php

use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionCategorias;
use App\Core\Catalogo\Presentacion\Controladores\ControladorCampanas;
use App\Core\Catalogo\Presentacion\Controladores\ControladorCategorias;
use App\Core\Catalogo\Presentacion\Controladores\ControladorProductos;
use App\Core\Cuentas\Presentacion\Controladores\ControladorCuenta;
use App\Core\Salud\Presentacion\Controladores\ControladorSalud;
use Illuminate\Support\Facades\Route;

// API versionada. Cada módulo agrega aquí su grupo de rutas.
Route::prefix('v1')->group(function () {
    Route::get('categories', [ControladorCategorias::class, 'index'])->name('api.v1.categories.index');
    Route::get('categories/{slug}', [ControladorCategorias::class, 'show'])->name('api.v1.categories.show');
    Route::get('products/{slug}', [ControladorProductos::class, 'show'])->name('api.v1.products.show');
    Route::get('campaigns/{slug}', [ControladorCampanas::class, 'show'])->name('api.v1.campaigns.show');
    // Cuenta única (clientes y administradores). El rol viene en la respuesta; Angular decide a dónde llevar al usuario.
    Route::prefix('auth')->group(function () {
        Route::post('register', [ControladorCuenta::class, 'register'])->middleware('throttle:registro')->name('api.v1.auth.register');
        Route::post('login', [ControladorCuenta::class, 'login'])->middleware('throttle:login')->name('api.v1.auth.login');
        Route::middleware('auth.token')->group(function () {
            Route::get('me', [ControladorCuenta::class, 'me'])->name('api.v1.auth.me');
            Route::post('logout', [ControladorCuenta::class, 'logout'])->name('api.v1.auth.logout');
            Route::post('logout-all', [ControladorCuenta::class, 'logoutAll'])->name('api.v1.auth.logout-all');
        });
    });

    // Administración (Angular del administrador): solo rol administrador.
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('categories', [ControladorAdministracionCategorias::class, 'index'])->name('api.v1.admin.categories.index');
        Route::post('categories', [ControladorAdministracionCategorias::class, 'store'])->name('api.v1.admin.categories.store');
        Route::put('categories/{slug}', [ControladorAdministracionCategorias::class, 'update'])->name('api.v1.admin.categories.update');
        Route::delete('categories/{slug}', [ControladorAdministracionCategorias::class, 'destroy'])->name('api.v1.admin.categories.destroy');
    });

    Route::get('health', ControladorSalud::class)->name('api.v1.health');
});
