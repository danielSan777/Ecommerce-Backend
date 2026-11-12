<?php

use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionCampanas;
use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionCategorias;
use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionInicio;
use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionMedios;
use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionProductos;
use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionSubcategorias;
use App\Core\Catalogo\Presentacion\Controladores\ControladorCampanas;
use App\Core\Catalogo\Presentacion\Controladores\ControladorCategorias;
use App\Core\Catalogo\Presentacion\Controladores\ControladorInicio;
use App\Core\Catalogo\Presentacion\Controladores\ControladorProductos;
use App\Core\Configurador\Presentacion\Controladores\ControladorAdministracionAccesorios;
use App\Core\Configurador\Presentacion\Controladores\ControladorAdministracionConfigurador;
use App\Core\Configurador\Presentacion\Controladores\ControladorAdministracionUbicaciones;
use App\Core\Configurador\Presentacion\Controladores\ControladorConfigurador;
use App\Core\Cuentas\Presentacion\Controladores\ControladorCuenta;
use App\Core\Salud\Presentacion\Controladores\ControladorSalud;
use Illuminate\Support\Facades\Route;

// API versionada. Cada módulo agrega aquí su grupo de rutas.
Route::prefix('v1')->group(function () {
    Route::get('categories', [ControladorCategorias::class, 'index'])->name('api.v1.categories.index');
    Route::get('categories/{slug}', [ControladorCategorias::class, 'show'])->name('api.v1.categories.show');
    Route::get('products', [ControladorProductos::class, 'index'])->name('api.v1.products.index');
    Route::get('products/{slug}', [ControladorProductos::class, 'show'])->name('api.v1.products.show');
    Route::get('products/{slug}/configurator', [ControladorConfigurador::class, 'show'])->name('api.v1.products.configurator');
    Route::get('home/hero', [ControladorInicio::class, 'hero'])->name('api.v1.home.hero');
    Route::get('categories-page', [ControladorInicio::class, 'categorias'])->name('api.v1.categories-page');
    Route::get('campaigns/current', [ControladorCampanas::class, 'current'])->name('api.v1.campaigns.current');
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
        Route::get('campaigns', [ControladorAdministracionCampanas::class, 'index'])->name('api.v1.admin.campaigns.index');
        Route::get('campaigns/{slug}/preview', [ControladorAdministracionCampanas::class, 'preview'])->name('api.v1.admin.campaigns.preview');
        Route::post('campaigns', [ControladorAdministracionCampanas::class, 'store'])->name('api.v1.admin.campaigns.store');
        Route::get('campaigns/{slug}', [ControladorAdministracionCampanas::class, 'show'])->name('api.v1.admin.campaigns.show');
        Route::put('campaigns/{slug}', [ControladorAdministracionCampanas::class, 'update'])->name('api.v1.admin.campaigns.update');
        Route::delete('campaigns/{slug}', [ControladorAdministracionCampanas::class, 'destroy'])->name('api.v1.admin.campaigns.destroy');
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
        // Imagen para personalizar: obligatoria en los productos personalizables; se sube desde el producto, nunca desde el configurador.
        Route::post('products/{sku}/personalization-image', [ControladorAdministracionConfigurador::class, 'subirImagenBase'])->name('api.v1.admin.products.personalization-image.store');
        Route::get('products/{sku}/media', [ControladorAdministracionMedios::class, 'indexProducto'])->name('api.v1.admin.products.media.index');
        Route::post('products/{sku}/media', [ControladorAdministracionMedios::class, 'storeProducto'])->name('api.v1.admin.products.media.store');
        Route::get('campaigns/{slug}/media', [ControladorAdministracionMedios::class, 'indexCampana'])->name('api.v1.admin.campaigns.media.index');
        Route::post('campaigns/{slug}/media', [ControladorAdministracionMedios::class, 'storeCampana'])->name('api.v1.admin.campaigns.media.store');
        Route::get('categories/{slug}/media', [ControladorAdministracionMedios::class, 'indexCategoria'])->name('api.v1.admin.categories.media.index');
        Route::post('categories/{slug}/media', [ControladorAdministracionMedios::class, 'storeCategoria'])->name('api.v1.admin.categories.media.store');
        // Portada de la página principal: lista sin límite de imágenes y videos (reemplaza la foto de ejemplo).
        Route::get('home/hero', [ControladorAdministracionInicio::class, 'index'])->name('api.v1.admin.home.hero.index')->defaults('seccion', 'inicio');
        Route::post('home/hero', [ControladorAdministracionInicio::class, 'store'])->name('api.v1.admin.home.hero.store')->defaults('seccion', 'inicio');
        Route::delete('home/hero/{id}', [ControladorAdministracionInicio::class, 'destroy'])->name('api.v1.admin.home.hero.destroy')->defaults('seccion', 'inicio');
        // Encabezado de la página /categorias: imágenes y videos (igual que la portada de inicio) y su texto
        Route::get('categories-page/hero', [ControladorAdministracionInicio::class, 'index'])->name('api.v1.admin.categories-page.hero.index')->defaults('seccion', 'categorias');
        Route::post('categories-page/hero', [ControladorAdministracionInicio::class, 'store'])->name('api.v1.admin.categories-page.hero.store')->defaults('seccion', 'categorias');
        Route::delete('categories-page/hero/{id}', [ControladorAdministracionInicio::class, 'destroy'])->name('api.v1.admin.categories-page.hero.destroy')->defaults('seccion', 'categorias');
        Route::get('categories-page/text', [ControladorAdministracionInicio::class, 'texto'])->name('api.v1.admin.categories-page.text.show');
        Route::put('categories-page/text', [ControladorAdministracionInicio::class, 'guardarTexto'])->name('api.v1.admin.categories-page.text.update');
        // Video (MP4 H.264): producto de máx. 6 s, banner de categoría de máx. 10 s. Campos `video` y `poster` (opcional).
        Route::post('products/{sku}/video', [ControladorAdministracionMedios::class, 'storeProductoVideo'])->name('api.v1.admin.products.video.store');
        Route::post('campaigns/{slug}/video', [ControladorAdministracionMedios::class, 'storeCampanaVideo'])->name('api.v1.admin.campaigns.video.store');
        Route::post('categories/{slug}/video', [ControladorAdministracionMedios::class, 'storeCategoriaVideo'])->name('api.v1.admin.categories.video.store');
        Route::put('media/{id}', [ControladorAdministracionMedios::class, 'update'])->whereNumber('id')->name('api.v1.admin.media.update');
        Route::delete('media/{id}', [ControladorAdministracionMedios::class, 'destroy'])->whereNumber('id')->name('api.v1.admin.media.destroy');

        // Configurador (solo el administrador): accesorios, ubicaciones y la configuración por categoría o por producto.
        Route::get('accessory-categories', [ControladorAdministracionAccesorios::class, 'categorias'])->name('api.v1.admin.accessory-categories.index');
        Route::post('accessory-categories', [ControladorAdministracionAccesorios::class, 'crearCategoria'])->name('api.v1.admin.accessory-categories.store');
        Route::put('accessory-categories/{id}', [ControladorAdministracionAccesorios::class, 'actualizarCategoria'])->whereNumber('id')->name('api.v1.admin.accessory-categories.update');
        Route::delete('accessory-categories/{id}', [ControladorAdministracionAccesorios::class, 'eliminarCategoria'])->whereNumber('id')->name('api.v1.admin.accessory-categories.destroy');
        Route::get('accessories', [ControladorAdministracionAccesorios::class, 'index'])->name('api.v1.admin.accessories.index');
        Route::post('accessories', [ControladorAdministracionAccesorios::class, 'store'])->name('api.v1.admin.accessories.store');
        Route::put('accessories/{id}', [ControladorAdministracionAccesorios::class, 'update'])->whereNumber('id')->name('api.v1.admin.accessories.update');
        Route::post('accessories/{id}/image', [ControladorAdministracionAccesorios::class, 'imagen'])->whereNumber('id')->name('api.v1.admin.accessories.image');
        Route::delete('accessories/{id}', [ControladorAdministracionAccesorios::class, 'destroy'])->whereNumber('id')->name('api.v1.admin.accessories.destroy');
        Route::get('locations', [ControladorAdministracionUbicaciones::class, 'index'])->name('api.v1.admin.locations.index');
        Route::post('locations', [ControladorAdministracionUbicaciones::class, 'store'])->name('api.v1.admin.locations.store');
        Route::put('locations/{id}', [ControladorAdministracionUbicaciones::class, 'update'])->whereNumber('id')->name('api.v1.admin.locations.update');
        Route::delete('locations/{id}', [ControladorAdministracionUbicaciones::class, 'destroy'])->whereNumber('id')->name('api.v1.admin.locations.destroy');
        Route::get('configurator/categories/{slug}', [ControladorAdministracionConfigurador::class, 'categoria'])->name('api.v1.admin.configurator.categories.show');
        Route::put('configurator/categories/{slug}', [ControladorAdministracionConfigurador::class, 'guardarCategoria'])->name('api.v1.admin.configurator.categories.update');
        Route::get('configurator/products/{sku}', [ControladorAdministracionConfigurador::class, 'producto'])->name('api.v1.admin.configurator.products.show');
        Route::put('configurator/products/{sku}', [ControladorAdministracionConfigurador::class, 'guardarProducto'])->name('api.v1.admin.configurator.products.update');
        Route::put('configurator/products/{sku}/mode', [ControladorAdministracionConfigurador::class, 'modo'])->name('api.v1.admin.configurator.products.mode');
    });

    Route::get('health', ControladorSalud::class)->name('api.v1.health');
});
