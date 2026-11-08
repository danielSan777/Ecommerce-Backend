<?php

use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionCategorias;
use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionMedios;
use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionProductos;
use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionSubcategorias;
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
    Route::get('products', [ControladorProductos::class, 'index'])->name('api.v1.products.index');
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
        Route::get('products/next-sku', [ControladorAdministracionProductos::class, 'siguienteSku'])->name('api.v1.admin.products.next-sku');
        Route::get('products/price-preview', [ControladorAdministracionProductos::class, 'previsualizarPrecio'])->name('api.v1.admin.products.price-preview');
        Route::get('products', [ControladorAdministracionProductos::class, 'index'])->name('api.v1.admin.products.index');
        Route::post('products', [ControladorAdministracionProductos::class, 'store'])->name('api.v1.admin.products.store');
        Route::get('products/{sku}', [ControladorAdministracionProductos::class, 'show'])->name('api.v1.admin.products.show');
        Route::put('products/{sku}', [ControladorAdministracionProductos::class, 'update'])->name('api.v1.admin.products.update');
        Route::delete('products/{sku}', [ControladorAdministracionProductos::class, 'destroy'])->name('api.v1.admin.products.destroy');
        Route::get('categories/{categoria}/subcategories', [ControladorAdministracionSubcategorias::class, 'index'])->name('api.v1.admin.subcategories.index');
        Route::post('categories/{categoria}/subcategories', [ControladorAdministracionSubcategorias::class, 'store'])->name('api.v1.admin.subcategories.store');
        Route::put('categories/{categoria}/subcategories/{slug}', [ControladorAdministracionSubcategorias::class, 'update'])->name('api.v1.admin.subcategories.update');
        Route::delete('categories/{categoria}/subcategories/{slug}', [ControladorAdministracionSubcategorias::class, 'destroy'])->name('api.v1.admin.subcategories.destroy');
        Route::delete('categories/{slug}', [ControladorAdministracionCategorias::class, 'destroy'])->name('api.v1.admin.categories.destroy');
        // Imágenes: se suben una por una (multipart, campo `image`); Laravel genera las versiones WebP.
        Route::get('products/{sku}/media', [ControladorAdministracionMedios::class, 'indexProducto'])->name('api.v1.admin.products.media.index');
        Route::post('products/{sku}/media', [ControladorAdministracionMedios::class, 'storeProducto'])->name('api.v1.admin.products.media.store');
        Route::get('categories/{slug}/media', [ControladorAdministracionMedios::class, 'indexCategoria'])->name('api.v1.admin.categories.media.index');
        Route::post('categories/{slug}/media', [ControladorAdministracionMedios::class, 'storeCategoria'])->name('api.v1.admin.categories.media.store');
        Route::put('media/{id}', [ControladorAdministracionMedios::class, 'update'])->whereNumber('id')->name('api.v1.admin.media.update');
        Route::delete('media/{id}', [ControladorAdministracionMedios::class, 'destroy'])->whereNumber('id')->name('api.v1.admin.media.destroy');
    });

    Route::get('health', ControladorSalud::class)->name('api.v1.health');
});
