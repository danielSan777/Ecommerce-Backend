<?php

use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionCampanas;
use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionCategorias;
use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionInicio;
use App\Core\Catalogo\Presentacion\Controladores\ControladorBusquedas;
use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionMedios;
use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionProductos;
use App\Core\Catalogo\Presentacion\Controladores\ControladorAdministracionSubcategorias;
use App\Core\Catalogo\Presentacion\Controladores\ControladorCampanas;
use App\Core\Catalogo\Presentacion\Controladores\ControladorCategorias;
use App\Core\Catalogo\Presentacion\Controladores\ControladorInicio;
use App\Core\Catalogo\Presentacion\Controladores\ControladorProductos;
use App\Core\Catalogo\Presentacion\Controladores\ControladorLimitesDeSubida;
use App\Core\Configurador\Presentacion\Controladores\ControladorAdministracionAccesorios;
use App\Core\Configurador\Presentacion\Controladores\ControladorAdministracionConfigurador;
use App\Core\Configurador\Presentacion\Controladores\ControladorAdministracionUbicaciones;
use App\Core\Configurador\Presentacion\Controladores\ControladorConfigurador;
use App\Core\Pedidos\Presentacion\Controladores\ControladorAdministracionCiclos;
use App\Core\Pedidos\Presentacion\Controladores\ControladorAdministracionFacturacion;
use App\Core\Pedidos\Presentacion\Controladores\ControladorAdministracionHojas;
use App\Core\Pedidos\Presentacion\Controladores\ControladorAdministracionPedidos;
use App\Core\Pedidos\Presentacion\Controladores\ControladorCheckout;
use App\Core\Pedidos\Presentacion\Controladores\ControladorPedidos;
use App\Core\Pagos\Presentacion\Controladores\ControladorPagos;
use App\Core\Entrega\Presentacion\Controladores\ControladorAdministracionDiasNoHabiles;
use App\Core\Entrega\Presentacion\Controladores\ControladorEntrega;
use App\Core\Envios\Presentacion\Controladores\ControladorAdministracionEnvios;
use App\Core\Configuracion\Presentacion\Controladores\ControladorModoDeVenta;
use App\Core\Envios\Presentacion\Controladores\ControladorUbicaciones;
use App\Core\Cuentas\Presentacion\Controladores\ControladorCuenta;
use App\Core\Salud\Presentacion\Controladores\ControladorSalud;
use App\Core\Catalogo\Presentacion\Controladores\ControladorMediaPublica;
use Illuminate\Support\Facades\Route;

// API versionada. Cada módulo agrega aquí su grupo de rutas.
Route::prefix('v1')->group(function () {
    Route::get('delivery/promise', [ControladorEntrega::class, 'promesa'])->name('api.v1.delivery.promise');
    Route::get('delivery/windows', [ControladorEntrega::class, 'ventanas'])->name('api.v1.delivery.windows');
    Route::post('search-log', [ControladorBusquedas::class, 'registrar'])->middleware('throttle:busquedas')->name('api.v1.search-log');
    Route::post('cart/quote', [ControladorCheckout::class, 'cotizar'])->middleware('throttle:cotizar')->name('api.v1.cart.quote');
    Route::post('checkout', [ControladorCheckout::class, 'store'])->middleware('throttle:checkout')->name('api.v1.checkout');
    Route::post('orders/{token}/payphone', [ControladorPagos::class, 'iniciar'])->middleware('throttle:pagos')->name('api.v1.orders.payphone');
    Route::post('payments/payphone/confirm', [ControladorPagos::class, 'confirmar'])->middleware('throttle:pagos')->name('api.v1.payments.payphone.confirm');
    // Notificación Externa de PayPhone (POST «NotificacionPago»): sin firma, así que solo avisa; Laravel confirma con PayPhone.
    Route::post('payments/payphone/NotificacionPago', [ControladorPagos::class, 'notificacion'])->name('api.v1.payments.payphone.notification');
    Route::get('orders/{token}', [ControladorPedidos::class, 'show'])->name('api.v1.orders.show');
    Route::post('orders/{token}/receipt', [ControladorPedidos::class, 'comprobante'])->middleware('throttle:comprobantes')->name('api.v1.orders.receipt');
    Route::get('orders/{token}/whatsapp', [ControladorPedidos::class, 'whatsapp'])->name('api.v1.orders.whatsapp');
    Route::get('orders/{token}/preview', [ControladorPedidos::class, 'vistaPrevia'])->name('api.v1.orders.preview');
    Route::get('locations', ControladorUbicaciones::class)->name('api.v1.locations');
    Route::get('store/pricing', [ControladorModoDeVenta::class, 'precios'])->name('api.v1.store.pricing');
    Route::get('categories', [ControladorCategorias::class, 'index'])->name('api.v1.categories.index');
    Route::get('categories/{slug}', [ControladorCategorias::class, 'show'])->name('api.v1.categories.show');
    Route::get('media/{ruta}', ControladorMediaPublica::class)->where('ruta', '.*')->name('api.v1.media');
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
        Route::get('orders', [ControladorAdministracionPedidos::class, 'index'])->name('api.v1.admin.orders.index');
        Route::get('orders/{numero}', [ControladorAdministracionPedidos::class, 'show'])->name('api.v1.admin.orders.show');
        Route::get('orders/{numero}/receipt', [ControladorAdministracionPedidos::class, 'comprobante'])->name('api.v1.admin.orders.receipt');
        Route::get('orders/{numero}/items/{item}/preview', [ControladorAdministracionPedidos::class, 'vistaDelItem'])->name('api.v1.admin.orders.item-preview');
        Route::get('orders/{numero}/items/{item}/frame-photo', [ControladorAdministracionPedidos::class, 'fotoDelMarco'])->name('api.v1.admin.orders.item-frame-photo');
        Route::post('orders/{numero}/mark-paid', [ControladorAdministracionPedidos::class, 'marcarPagado'])->name('api.v1.admin.orders.mark-paid');
        Route::post('orders/{numero}/production', [ControladorAdministracionPedidos::class, 'enProduccion'])->name('api.v1.admin.orders.production');
        Route::post('orders/{numero}/ship', [ControladorAdministracionPedidos::class, 'enviado'])->name('api.v1.admin.orders.ship');
        Route::get('orders/{numero}/history', [ControladorAdministracionPedidos::class, 'historial'])->name('api.v1.admin.orders.history');
        Route::get('searches/no-results', [ControladorBusquedas::class, 'sinResultados'])->name('api.v1.admin.searches.no-results');
        Route::post('orders/{numero}/move-cycle', [ControladorAdministracionPedidos::class, 'moverDeCiclo'])->name('api.v1.admin.orders.move-cycle');
        Route::post('orders/{numero}/delay', [ControladorAdministracionPedidos::class, 'retraso'])->name('api.v1.admin.orders.delay');
        Route::post('orders/{numero}/refund', [ControladorAdministracionPedidos::class, 'reembolso'])->name('api.v1.admin.orders.refund');
        Route::post('orders/{numero}/cancel', [ControladorAdministracionPedidos::class, 'cancelar'])->name('api.v1.admin.orders.cancel');
        Route::get('cycles/{fecha}/production', [ControladorAdministracionHojas::class, 'produccion'])->name('api.v1.admin.cycles.production');
        Route::get('cycles/{fecha}/shipping', [ControladorAdministracionHojas::class, 'envios'])->name('api.v1.admin.cycles.shipping');
        Route::post('orders/ship-batch', [ControladorAdministracionHojas::class, 'enviarLote'])->name('api.v1.admin.orders.ship-batch');
        Route::get('invoicing', [ControladorAdministracionFacturacion::class, 'index'])->name('api.v1.admin.invoicing.index');
        Route::post('invoicing/batch', [ControladorAdministracionFacturacion::class, 'marcarLote'])->name('api.v1.admin.invoicing.batch');
        Route::delete('invoicing/{numero}', [ControladorAdministracionFacturacion::class, 'quitar'])->name('api.v1.admin.invoicing.clear');
        Route::get('cycles', [ControladorAdministracionCiclos::class, 'index'])->name('api.v1.admin.cycles.index');
        Route::post('cycles/{fecha}/close', [ControladorAdministracionCiclos::class, 'cerrar'])->name('api.v1.admin.cycles.close');
        Route::post('cycles/{fecha}/reopen', [ControladorAdministracionCiclos::class, 'reabrir'])->name('api.v1.admin.cycles.reopen');
        Route::put('cycles/{fecha}/capacity', [ControladorAdministracionCiclos::class, 'capacidad'])->name('api.v1.admin.cycles.capacity');
        Route::get('non-working-days', [ControladorAdministracionDiasNoHabiles::class, 'index'])->name('api.v1.admin.non-working-days.index');
        Route::post('non-working-days', [ControladorAdministracionDiasNoHabiles::class, 'store'])->name('api.v1.admin.non-working-days.store');
        Route::delete('non-working-days/{fecha}', [ControladorAdministracionDiasNoHabiles::class, 'destroy'])->name('api.v1.admin.non-working-days.destroy');
        Route::get('sales-mode', [ControladorModoDeVenta::class, 'mostrar'])->name('api.v1.admin.sales-mode.show');
        Route::put('sales-mode', [ControladorModoDeVenta::class, 'guardar'])->name('api.v1.admin.sales-mode.update');
        Route::get('shipping/zones', [ControladorAdministracionEnvios::class, 'zonas'])->name('api.v1.admin.shipping.zones');
        Route::put('shipping/zones/{codigo}', [ControladorAdministracionEnvios::class, 'actualizarZona'])->name('api.v1.admin.shipping.zones.update');
        Route::get('shipping/cantons', [ControladorAdministracionEnvios::class, 'cantones'])->name('api.v1.admin.shipping.cantons');
        Route::put('shipping/cantons/zone', [ControladorAdministracionEnvios::class, 'asignarZona'])->name('api.v1.admin.shipping.cantons.zone');
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
        Route::post('products/{sku}/complement-photo', [ControladorAdministracionMedios::class, 'storeFotoComplemento'])->name('api.v1.admin.products.complement-photo.store');
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
        Route::get('upload-limits', ControladorLimitesDeSubida::class)->name('api.v1.admin.upload-limits');
        Route::get('accessories', [ControladorAdministracionAccesorios::class, 'index'])->name('api.v1.admin.accessories.index');
        Route::post('accessories', [ControladorAdministracionAccesorios::class, 'store'])->name('api.v1.admin.accessories.store');
        Route::put('accessories/{id}', [ControladorAdministracionAccesorios::class, 'update'])->whereNumber('id')->name('api.v1.admin.accessories.update');
        Route::post('accessories/{id}/image', [ControladorAdministracionAccesorios::class, 'imagen'])->whereNumber('id')->name('api.v1.admin.accessories.image');
        Route::delete('accessories/{id}', [ControladorAdministracionAccesorios::class, 'destroy'])->whereNumber('id')->name('api.v1.admin.accessories.destroy');
        Route::get('locations', [ControladorAdministracionUbicaciones::class, 'index'])->name('api.v1.admin.locations.index');
        Route::post('locations', [ControladorAdministracionUbicaciones::class, 'store'])->name('api.v1.admin.locations.store');
        Route::put('locations/{id}', [ControladorAdministracionUbicaciones::class, 'update'])->whereNumber('id')->name('api.v1.admin.locations.update');
        Route::delete('locations/{id}', [ControladorAdministracionUbicaciones::class, 'destroy'])->whereNumber('id')->name('api.v1.admin.locations.destroy');
        Route::get('configurator/last-edit', [ControladorAdministracionConfigurador::class, 'ultimaEdicion'])->name('api.v1.admin.configurator.last-edit.show');
        Route::put('configurator/last-edit', [ControladorAdministracionConfigurador::class, 'recordarUltimaEdicion'])->name('api.v1.admin.configurator.last-edit.update');
        Route::get('configurator/categories/{slug}', [ControladorAdministracionConfigurador::class, 'categoria'])->name('api.v1.admin.configurator.categories.show');
        Route::put('configurator/categories/{slug}', [ControladorAdministracionConfigurador::class, 'guardarCategoria'])->name('api.v1.admin.configurator.categories.update');
        Route::get('configurator/products/{sku}', [ControladorAdministracionConfigurador::class, 'producto'])->name('api.v1.admin.configurator.products.show');
        Route::put('configurator/products/{sku}', [ControladorAdministracionConfigurador::class, 'guardarProducto'])->name('api.v1.admin.configurator.products.update');
        Route::put('configurator/products/{sku}/fixed-accessories/{ubicacion}', [ControladorAdministracionConfigurador::class, 'accesorioFijoDelProducto'])->whereNumber('ubicacion')->name('api.v1.admin.configurator.products.fixed-accessories');
        Route::put('configurator/products/{sku}/base-adjust', [ControladorAdministracionConfigurador::class, 'ajustarImagenBase'])->name('api.v1.admin.configurator.products.base-adjust');
        Route::put('configurator/products/{sku}/mode', [ControladorAdministracionConfigurador::class, 'modo'])->name('api.v1.admin.configurator.products.mode');
    });

    Route::get('health', ControladorSalud::class)->name('api.v1.health');
});
