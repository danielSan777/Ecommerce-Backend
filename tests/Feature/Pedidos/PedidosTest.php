<?php

declare(strict_types=1);

namespace Tests\Feature\Pedidos;

use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use App\Core\Configuracion\Infraestructura\Modelos\Ajuste;
use App\Core\Configurador\Infraestructura\Modelos\AccesorioModelo;
use App\Core\Configurador\Infraestructura\Modelos\CategoriaDeAccesorioModelo;
use App\Core\Entrega\Dominio\TokenDePromesa;
use App\Core\Entrega\Infraestructura\Modelos\DiaNoHabilModelo;
use App\Core\Pedidos\Dominio\Documentos;
use App\Core\Pedidos\Infraestructura\Modelos\ClienteModelo;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AjustesIniciales;
use Database\Seeders\ProductosDePrueba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Core\Pedidos\Aplicacion\CasosDeUso\GestionarPedido;
use App\Core\Pedidos\Aplicacion\CasosDeUso\NotificarPedido;
use App\Core\Pedidos\Infraestructura\Correo\AvisoDeCompraNueva;
use App\Core\Pedidos\Infraestructura\Correo\CorreoDePedido;
use App\Core\Pedidos\Infraestructura\Modelos\EventoDePedidoModelo;
use Illuminate\Support\Facades\Mail;
use App\Core\Pagos\Infraestructura\Modelos\IntentoDePagoModelo;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PedidosTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductosDePrueba::class);
        $this->seed(AjustesIniciales::class);
        DiaNoHabilModelo::query()->delete();
        Storage::fake('private');
        $this->ahora('2026-11-09 10:00:00');   // lunes 10:00 en Guayaquil
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Fija "ahora" en una hora de pared de Guayaquil. */
    private function ahora(string $guayaquil): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($guayaquil, 'America/Guayaquil')->utc());
    }

    private function slug(): string
    {
        return ProductoModelo::query()->where('type', 'personalizable')->orderBy('id')->firstOrFail()->slug;
    }

    private function precio(string $slug): int
    {
        $p = ProductoModelo::query()->where('slug', $slug)->firstOrFail();

        return (int) $p->base_price_cents;
    }

    /** @return array<string, mixed> */
    private function peticion(array $cambios = []): array
    {
        $slug = $this->slug();

        return array_replace_recursive([
            'items' => [['slug' => $slug, 'personalization' => ['name_text' => 'Josué', 'dedication' => ['mode' => 'text', 'text' => 'Feliz día']], 'preview_image' => self::PNG]],
            'shipping' => ['name' => 'Josué de Santis', 'id_type' => 'cedula', 'id_number' => '0930247994', 'phone' => '099 123 4567', 'email' => ' Josue@Correo.COM ',
                'province_code' => '09', 'canton_code' => '0901', 'address' => 'Av. Principal 123', 'reference' => 'Frente al parque'],
            'billing' => ['same_as_shipping' => true],
            'contact' => ['same_as_shipping' => true],
            'payment_method' => 'transfer',
            'window_choice' => ['type' => 'immediate'],
            'terms' => ['accepted' => true, 'version' => '2026-11'],
        ], $cambios);
    }

    private function crear(array $cambios = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/checkout', $this->peticion($cambios));
    }

    /** Dos macetas de distinto precio ($20,00 y $19,00) sin descuento propio, para probar el descuento a la de menor valor. */
    private function dosMacetasDe2000y1900(): array
    {
        $macetas = ProductoModelo::query()->where('type', 'personalizable')->orderBy('id')->take(2)->get();
        $macetas[0]->update(['base_price_cents' => 2000, 'discount_percent' => 0]);
        $macetas[1]->update(['base_price_cents' => 1900, 'discount_percent' => 0]);
        $item = $this->peticion()['items'][0];

        return [array_replace($item, ['slug' => $macetas[0]->slug]), array_replace($item, ['slug' => $macetas[1]->slug])];
    }

    public function test_modo_todo_gratis_envio_gratis_sin_recargo_y_30_por_ciento_a_las_macetas_menos_la_mas_cara(): void
    {
        [$a, $b] = $this->dosMacetasDe2000y1900();
        app(\App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion::class)->guardar('sales_mode', 'free_shipping_discount');

        $this->crear(['items' => [$a, $b], 'payment_method' => 'card'])->assertCreated();
        $p = PedidoModelo::query()->latest('id')->firstOrFail();

        $this->assertSame(3900, $p->subtotal_cents);
        $this->assertSame(570, $p->discount_cents);        // 30 % de $19,00: la de $20,00 se paga completa
        $this->assertSame(0, $p->shipping_cents);
        $this->assertSame(0, $p->card_fee_cents);           // sin comisión bancaria: el total es el final
        $this->assertSame(3330, $p->total_cents);
    }

    public function test_modo_todo_gratis_con_tres_macetas_solo_se_paga_completa_la_mas_cara(): void
    {
        $macetas = ProductoModelo::query()->where('type', 'personalizable')->orderBy('id')->take(3)->get();
        $this->assertCount(3, $macetas);
        foreach ([2000, 1900, 1800] as $i => $centavos) {
            $macetas[$i]->update(['base_price_cents' => $centavos, 'discount_percent' => 0]);
        }
        $item = $this->peticion()['items'][0];
        $items = $macetas->map(fn ($m) => array_replace($item, ['slug' => $m->slug]))->all();
        app(\App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion::class)->guardar('sales_mode', 'free_shipping_discount');

        $this->crear(['items' => [$items[2], $items[0], $items[1]]])->assertCreated();   // el orden del carrito no importa
        $p = PedidoModelo::query()->latest('id')->firstOrFail();

        $this->assertSame(5700, $p->subtotal_cents);
        $this->assertSame(570 + 540, $p->discount_cents);   // 30 % de $19,00 y de $18,00; la de $20,00 completa
        $this->assertSame(4590, $p->total_cents);
    }

    public function test_modo_todo_gratis_con_el_porcentaje_editado_por_el_admin(): void
    {
        [$a, $b] = $this->dosMacetasDe2000y1900();
        $this->admin();
        $this->putJson('/api/v1/admin/sales-mode', ['mode' => 'free_shipping_discount', 'discount_percent' => 50])->assertOk()
            ->assertJsonPath('mode', 'free_shipping_discount')->assertJsonPath('discount_percent', 50);
        $this->getJson('/api/v1/store/pricing')->assertJsonPath('discount_percent', 50);

        $this->crear(['items' => [$a, $b]])->assertCreated();
        $p = PedidoModelo::query()->latest('id')->firstOrFail();
        $this->assertSame(950, $p->discount_cents);          // 50 % de $19,00
        $this->assertSame(2950, $p->total_cents);

        // Solo el porcentaje: el modo no cambia
        $this->putJson('/api/v1/admin/sales-mode', ['discount_percent' => 12.5])->assertOk()->assertJsonPath('mode', 'free_shipping_discount')->assertJsonPath('discount_percent', 12.5);
        foreach ([-1, 101, 'abc', 10.123] as $malo) {
            $this->putJson('/api/v1/admin/sales-mode', ['discount_percent' => $malo])->assertStatus(422);
        }
        $this->getJson('/api/v1/admin/sales-mode')->assertJsonPath('discount_percent', 12.5);
    }

    public function test_modo_todo_gratis_con_una_sola_maceta_no_hay_descuento_pero_el_envio_es_gratis(): void
    {
        app(\App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion::class)->guardar('sales_mode', 'free_shipping_discount');
        $this->crear(['payment_method' => 'card'])->assertCreated();
        $p = PedidoModelo::query()->latest('id')->firstOrFail();

        $this->assertSame(0, $p->discount_cents);
        $this->assertSame(0, $p->shipping_cents);
        $this->assertSame(0, $p->card_fee_cents);
        $this->assertSame($this->precio($this->slug()), $p->total_cents);
    }

    public function test_modo_todo_gratis_si_el_total_esperado_no_coincide_responde_price_changed_con_el_descuento(): void
    {
        [$a, $b] = $this->dosMacetasDe2000y1900();
        app(\App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion::class)->guardar('sales_mode', 'free_shipping_discount');

        $this->crear(['items' => [$a, $b], 'expected_total_cents' => 3900])->assertStatus(409)
            ->assertJsonPath('code', 'price_changed')->assertJsonPath('details.total_cents', 3330)->assertJsonPath('details.discount_cents', 570);
        $this->crear(['items' => [$a, $b], 'expected_total_cents' => 3330])->assertCreated();
    }

    public function test_modo_estandar_conserva_envio_por_zona_envio_gratis_desde_2_y_recargo(): void
    {
        [$a, $b] = $this->dosMacetasDe2000y1900();

        $this->crear(['items' => [$a], 'payment_method' => 'card'])->assertCreated();
        $una = PedidoModelo::query()->latest('id')->firstOrFail();
        $this->assertSame([350, 0, 141], [$una->shipping_cents, $una->discount_cents, $una->card_fee_cents]);   // 6 % de (2000 + 350) = 141
        $this->assertSame(2491, $una->total_cents);

        $this->crear(['items' => [$a, $b], 'payment_method' => 'card'])->assertCreated();
        $dos = PedidoModelo::query()->latest('id')->firstOrFail();
        $this->assertSame([0, 0, 234], [$dos->shipping_cents, $dos->discount_cents, $dos->card_fee_cents]);   // 6 % de 3900
        $this->assertSame(4134, $dos->total_cents);
    }

    public function test_los_precios_publicos_siguen_el_modo_de_venta(): void
    {
        $this->getJson('/api/v1/store/pricing')->assertOk()->assertExactJson([
            'mode' => 'standard', 'free_shipping_min_planters' => 2, 'card_surcharge_percent' => 6, 'discount_percent' => 0, 'free_shipping_everywhere' => false,
        ]);

        app(\App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion::class)->guardar('sales_mode', 'free_shipping_discount');
        $this->getJson('/api/v1/store/pricing')->assertOk()->assertExactJson([
            'mode' => 'free_shipping_discount', 'free_shipping_min_planters' => 2, 'card_surcharge_percent' => 0, 'discount_percent' => 30, 'free_shipping_everywhere' => true,
        ]);
    }

    public function test_si_todas_las_zonas_habilitadas_cuestan_cero_el_envio_es_gratis_en_todo_el_pais_aunque_sea_modo_estandar(): void
    {
        \App\Core\Envios\Infraestructura\Modelos\ZonaDeEnvioModelo::query()->update(['price_cents' => 0]);
        $this->getJson('/api/v1/store/pricing')->assertOk()->assertJsonPath('mode', 'standard')->assertJsonPath('free_shipping_everywhere', true);

        $this->crear()->assertCreated();   // una sola maceta: envío $0 porque la zona cuesta $0
        $this->assertSame(0, PedidoModelo::query()->latest('id')->firstOrFail()->shipping_cents);
    }

    public function test_el_admin_cambia_el_modo_de_venta_y_nadie_mas_puede(): void
    {
        $this->getJson('/api/v1/admin/sales-mode')->assertUnauthorized();
        $this->putJson('/api/v1/admin/sales-mode', ['mode' => 'free_shipping_discount'])->assertUnauthorized();

        $this->admin();
        $this->getJson('/api/v1/admin/sales-mode')->assertOk()->assertJsonPath('mode', 'standard')->assertJsonPath('discount_percent', 30);
        $this->putJson('/api/v1/admin/sales-mode', ['mode' => 'inventado'])->assertStatus(422);
        $this->putJson('/api/v1/admin/sales-mode', [])->assertStatus(422);
        $this->putJson('/api/v1/admin/sales-mode', ['mode' => 'free_shipping_discount'])->assertOk()->assertJsonPath('mode', 'free_shipping_discount');
        $this->getJson('/api/v1/store/pricing')->assertJsonPath('free_shipping_everywhere', true);
        $this->putJson('/api/v1/admin/sales-mode', ['mode' => 'standard'])->assertOk()->assertJsonPath('mode', 'standard');
        $this->getJson('/api/v1/store/pricing')->assertJsonPath('card_surcharge_percent', 6);
    }

    public function test_cedula_y_ruc_con_digito_verificador(): void
    {
        $this->assertTrue(Documentos::cedulaValida('0930247994'));
        $this->assertFalse(Documentos::cedulaValida('0930247995'));
        $this->assertFalse(Documentos::cedulaValida('1234567890'));
        $this->assertFalse(Documentos::cedulaValida('093024799'));
        $this->assertTrue(Documentos::rucValido('0930247994001'));
        $this->assertFalse(Documentos::rucValido('0930247994000'));
        $this->assertFalse(Documentos::rucValido('0930247995001'));
        $this->assertSame('+593991234567', Documentos::telefono('099 123 4567'));
        $this->assertSame('+593991234567', Documentos::telefono('+593991234567'));
        $this->assertNull(Documentos::telefono('12345'));
        $this->assertSame('a@b.com', Documentos::correo(' A@B.com '));
    }

    public function test_crea_un_pedido_por_transferencia_con_precios_y_envio_recalculados_por_el_servidor(): void
    {
        $r = $this->crear()->assertCreated();

        $pedido = PedidoModelo::query()->firstOrFail();
        $this->assertSame('JDS-'.(1000 + $pedido->id), $r->json('order_number'));
        $this->assertSame(64, strlen($r->json('token')));
        $this->assertSame('purchase-'.$pedido->uuid, $r->json('event_id'));
        $this->assertSame($this->precio($this->slug()) + 350, $pedido->total_cents);   // Guayaquil = Zona A
        $this->assertSame('pending_validation', $pedido->payment_status);
        $this->assertSame('2026-11-09', $pedido->cycle_date->format('Y-m-d'));
        $this->assertSame('2026-11-12', $pedido->window_start->format('Y-m-d'));
        $this->assertSame('2026-11-13', $pedido->window_end->format('Y-m-d'));
        $this->assertTrue($pedido->transfer_deadline_at->equalTo(CarbonImmutable::now()->addHours(12)));
        $this->assertSame('josue@correo.com', $pedido->cliente->email);
        $this->assertSame('+593991234567', $pedido->contact['phone']);
        $this->assertSame('Josué', $pedido->items->first()->personalization['name_text']);
        $this->assertNotNull($pedido->items->first()->preview_path);
        Storage::disk('private')->assertExists($pedido->items->first()->preview_path);
        $r->assertJsonPath('order.status', 'pending_payment')->assertJsonPath('order.deadline_label', 'Paga antes del lunes 9 de noviembre, 22:00');
    }

    public function test_ningun_valor_de_angular_decide_el_total(): void
    {
        $esperado = $this->precio($this->slug()) + 350;
        $this->crear(['expected_total_cents' => 100])->assertStatus(409)->assertJsonPath('code', 'price_changed')->assertJsonPath('details.total_cents', $esperado);
        $this->assertSame(0, PedidoModelo::query()->count());
        $this->crear(['expected_total_cents' => $esperado])->assertCreated();
    }

    public function test_dos_macetas_tienen_envio_gratis_y_el_mismo_correo_es_un_solo_cliente(): void
    {
        $item = $this->peticion()['items'][0];
        $this->postJson('/api/v1/checkout', $this->peticion(['items' => [$item, $item]]) + [])->assertCreated();
        $dos = PedidoModelo::query()->latest('id')->first();
        $this->assertSame(0, $dos->shipping_cents);
        $this->assertSame($this->precio($this->slug()) * 2, $dos->total_cents);

        $this->crear()->assertCreated();
        $this->assertSame(1, ClienteModelo::query()->count());
        $this->assertSame(2, PedidoModelo::query()->count());
    }

    public function test_rechaza_datos_invalidos(): void
    {
        $this->assertSame(['Ingresa una cédula válida de 10 dígitos.'], $this->crear(['shipping' => ['id_number' => '0930247995']])->assertStatus(422)->json('errors')['shipping.id_number']);
        $this->crear(['shipping' => ['phone' => '123']])->assertStatus(422)->assertJsonStructure(['errors' => ['shipping.phone']]);
        $this->crear(['shipping' => ['email' => 'no-es-correo']])->assertStatus(422);
        $this->assertSame(['Debes aceptar los términos y condiciones para continuar.'], $this->crear(['terms' => ['accepted' => false]])->assertStatus(422)->json('errors')['terms.accepted']);
        $this->crear(['items' => [['personalization' => ['name_text' => str_repeat('a', 31), 'dedication' => ['mode' => 'none']]]]])->assertStatus(422);
        $sinDedicatoria = array_replace($this->peticion(), ['items' => [['slug' => $this->slug(), 'personalization' => ['name_text' => 'Ana']]]]);
        $this->postJson('/api/v1/checkout', $sinDedicatoria)->assertStatus(422)->assertJsonStructure(['errors' => ['items.0.personalization.dedication']]);
        $this->crear(['shipping' => ['canton_code' => '2001', 'province_code' => '20']])->assertStatus(422)->assertJsonPath('code', 'zone_unavailable');
        $this->crear(['shipping' => ['canton_code' => '0101']])->assertStatus(422); // cantón de otra provincia
        $this->assertSame(0, PedidoModelo::query()->count());
    }

    public function test_exige_al_menos_una_maceta(): void
    {
        $complemento = ProductoModelo::query()->create(['sku' => 'COM-1', 'slug' => 'llavero', 'type' => 'complemento', 'name' => 'Llavero', 'base_price_cents' => 550, 'is_active' => true, 'is_available' => true, 'uuid' => (string) \Illuminate\Support\Str::uuid()]);
        $this->postJson('/api/v1/checkout', array_replace($this->peticion(), ['items' => [['slug' => $complemento->slug]]]))
            ->assertStatus(422)->assertJsonPath('code', 'planter_required');
    }

    public function test_facturacion_y_contacto_distintos_se_guardan_aparte(): void
    {
        $this->crear([
            'billing' => ['same_as_shipping' => false, 'name' => 'Mi Empresa', 'id_type' => 'ruc', 'id_number' => '0930247994001', 'email' => 'factura@empresa.com', 'phone' => '0987654321', 'address' => 'Calle 1 y 2', 'province_code' => '09', 'canton_code' => '0901'],
            'contact' => ['same_as_shipping' => false, 'phone' => '0911111111'],
        ])->assertCreated();

        $p = PedidoModelo::query()->firstOrFail();
        $this->assertSame('ruc', $p->billing['id_type']);
        $this->assertSame('+593911111111', $p->contact['phone']);
        $this->crear(['billing' => ['same_as_shipping' => false, 'name' => 'X', 'id_type' => 'ruc', 'id_number' => '1', 'email' => 'a', 'address' => '']])->assertStatus(422);
    }

    public function test_un_pedido_nuevo_avisa_si_su_ventana_cambio_antes_de_pagar(): void
    {
        $this->crear(['expected_window' => ['start' => '2026-11-12', 'end' => '2026-11-13']])->assertCreated();

        $this->ahora('2026-11-09 20:35:00');   // después del corte → ciclo martes
        $this->crear(['expected_window' => ['start' => '2026-11-12', 'end' => '2026-11-13']])
            ->assertStatus(409)->assertJsonPath('code', 'window_changed')->assertJsonPath('details.window.start', '2026-11-13')
            ->assertJsonPath('details.label', 'Recíbelo entre viernes 13 y sábado 14 de noviembre');
        $this->crear(['expected_window' => ['start' => '2026-11-13', 'end' => '2026-11-14']])->assertCreated();
    }

    public function test_gracia_de_30_minutos_con_token_valido(): void
    {
        $tokens = new TokenDePromesa;
        $this->ahora('2026-11-09 19:50:00');
        $token = $tokens->emitir('2026-11-09', CarbonImmutable::now());

        $this->ahora('2026-11-09 20:15:00');   // a 15 min del corte
        $this->crear(['promise_token' => $token, 'expected_window' => ['start' => '2026-11-12', 'end' => '2026-11-13']])->assertCreated();
        $p = PedidoModelo::query()->latest('id')->first();
        $this->assertTrue($p->grace_applied);
        $this->assertSame('2026-11-09', $p->cycle_date->format('Y-m-d'));

        $this->ahora('2026-11-09 20:29:59');   // dentro de 30 min
        $this->crear(['promise_token' => $token])->assertCreated();
        $this->assertSame('2026-11-09', PedidoModelo::query()->latest('id')->first()->cycle_date->format('Y-m-d'));

        $this->ahora('2026-11-09 20:35:00');   // pasados 35 min: ciclo martes
        $this->crear(['promise_token' => $token])->assertCreated();
        $tarde = PedidoModelo::query()->latest('id')->first();
        $this->assertFalse($tarde->grace_applied);
        $this->assertSame('2026-11-10', $tarde->cycle_date->format('Y-m-d'));
    }

    public function test_un_token_alterado_o_emitido_despues_del_corte_no_da_gracia(): void
    {
        $tokens = new TokenDePromesa;
        $this->ahora('2026-11-09 20:05:00');
        $despues = $tokens->emitir('2026-11-09', CarbonImmutable::now());   // emitido tras el corte
        $this->ahora('2026-11-09 20:15:00');
        $this->crear(['promise_token' => $despues])->assertCreated();
        $this->assertSame('2026-11-10', PedidoModelo::query()->latest('id')->first()->cycle_date->format('Y-m-d'));

        $alterado = $tokens->emitir('2026-11-09', CarbonImmutable::parse('2026-11-09 19:50:00', 'America/Guayaquil')->utc());
        [$carga, $firma] = explode('.', $alterado);
        $this->crear(['promise_token' => $carga.'x.'.$firma])->assertCreated();
        $this->assertSame('2026-11-10', PedidoModelo::query()->latest('id')->first()->cycle_date->format('Y-m-d'));
    }

    public function test_con_8_pedidos_el_siguiente_va_al_ciclo_siguiente(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->crear(['shipping' => ['email' => "c{$i}@correo.com"]])->assertCreated();
        }
        $this->assertSame(8, PedidoModelo::query()->where('cycle_date', '2026-11-09')->count());

        $this->crear(['shipping' => ['email' => 'otro@correo.com']])->assertCreated();
        $noveno = PedidoModelo::query()->latest('id')->first();
        $this->assertSame('2026-11-10', $noveno->cycle_date->format('Y-m-d'));
        $this->assertSame('2026-11-13', $noveno->window_start->format('Y-m-d'));
    }

    public function test_un_pedido_vencido_libera_su_cupo(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->crear(['shipping' => ['email' => "c{$i}@correo.com"]])->assertCreated();
        }
        $this->ahora('2026-11-10 10:00:01');   // pasaron más de 12 horas: los 8 ya no cuentan aunque nadie corra el comando
        $this->assertSame(8, $this->app->make(GestionarPedido::class)->vencer());
        $this->assertSame(8, PedidoModelo::query()->where('status', 'expired')->count());
    }

    public function test_ventana_programada_valida_y_con_cupo(): void
    {
        $this->crear(['window_choice' => ['type' => 'scheduled', 'start' => '2026-11-19']])->assertCreated();
        $p = PedidoModelo::query()->firstOrFail();
        $this->assertSame('scheduled', $p->window_type);
        $this->assertSame('2026-11-19', $p->window_start->format('Y-m-d'));
        $this->assertSame('2026-11-20', $p->window_end->format('Y-m-d'));

        $this->crear(['window_choice' => ['type' => 'scheduled', 'start' => '2026-11-08']])->assertStatus(409)->assertJsonPath('code', 'window_unavailable'); // pasado y domingo
        $this->crear(['window_choice' => ['type' => 'scheduled', 'start' => '2026-11-15']])->assertStatus(409);
        $this->crear(['window_choice' => ['type' => 'scheduled']])->assertStatus(422);
    }

    public function test_la_amazonia_recibe_ventana_de_tres_dias(): void
    {
        $this->crear(['shipping' => ['province_code' => '15', 'canton_code' => '1501']])->assertCreated();
        $p = PedidoModelo::query()->firstOrFail();
        $this->assertSame('2026-11-14', $p->window_end->format('Y-m-d'));
        $this->assertSame(625, $p->shipping_cents);
    }

    public function test_el_comprador_ve_su_pedido_con_el_token_y_la_vista_previa_para_whatsapp(): void
    {
        $token = $this->crear()->json('token');

        $r = $this->getJson("/api/v1/orders/{$token}")->assertOk();
        $r->assertJsonPath('status', 'pending_payment')->assertJsonPath('receipt.uploaded', false)->assertJsonCount(3, 'bank_accounts');
        $this->assertStringContainsString("/api/v1/orders/{$token}/whatsapp", $r->json('whatsapp_url'));
        $this->assertStringContainsString("/api/v1/orders/{$token}/preview", $r->json('share.image'));
        $this->assertStringContainsString('Total $', $r->json('share.description'));
        $this->assertStringNotContainsString('Josué', json_encode($r->json('share')));   // sin datos personales
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));

        $this->get("/api/v1/orders/{$token}/preview")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->getJson('/api/v1/orders/'.str_repeat('a', 64))->assertNotFound();
        $this->get('/api/v1/orders/'.str_repeat('a', 64).'/preview')->assertNotFound();
    }

    public function test_el_boton_de_whatsapp_registra_el_clic_y_lleva_el_enlace_del_pedido(): void
    {
        $token = $this->crear()->json('token');

        $r = $this->get("/api/v1/orders/{$token}/whatsapp")->assertRedirect();
        $destino = urldecode($r->headers->get('Location'));
        $this->assertStringStartsWith('https://wa.me/593999283765?text=', $destino);
        $this->assertStringContainsString('Hola 🌿 Te envío el comprobante de mi pedido JDS-', $destino);
        $this->assertStringContainsString('/pedido/'.$token, $destino);
        $this->assertNotNull(PedidoModelo::query()->firstOrFail()->whatsapp_clicked_at);
    }

    public function test_el_mensaje_de_whatsapp_sigue_compacto_con_30_items(): void
    {
        $item = $this->peticion()['items'][0];
        $token = $this->postJson('/api/v1/checkout', $this->peticion(['items' => array_fill(0, 30, $item)]))->assertCreated()->json('token');
        $destino = urldecode($this->get("/api/v1/orders/{$token}/whatsapp")->headers->get('Location'));

        $this->assertStringContainsString('• y 27 más', $destino);
        $this->assertLessThan(500, strlen($destino));
    }

    public function test_sube_el_comprobante_en_jpg_y_pdf_con_limite_de_intentos(): void
    {
        $token = $this->crear()->json('token');

        $this->post("/api/v1/orders/{$token}/receipt", ['receipt' => UploadedFile::fake()->create('c.exe', 10)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/api/v1/orders/{$token}/receipt", ['receipt' => UploadedFile::fake()->create('c.pdf', 6000, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);

        $this->post("/api/v1/orders/{$token}/receipt", ['receipt' => UploadedFile::fake()->image('c.jpg')], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('receipt.uploaded', true);
        $p = PedidoModelo::query()->firstOrFail();
        $this->assertNotNull($p->receipt_uploaded_at);
        Storage::disk('private')->assertExists($p->receipt_path);

        $this->post("/api/v1/orders/{$token}/receipt", ['receipt' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])->assertOk();
        $this->assertStringEndsWith('.pdf', PedidoModelo::query()->firstOrFail()->receipt_path);

        PedidoModelo::query()->update(['receipt_attempts' => 5]);
        $this->post("/api/v1/orders/{$token}/receipt", ['receipt' => UploadedFile::fake()->image('c.jpg')], ['Accept' => 'application/json'])->assertStatus(429)->assertJsonPath('code', 'upload_invalid');
    }

    public function test_pasado_el_plazo_no_se_puede_subir_comprobante(): void
    {
        $token = $this->crear()->json('token');
        $this->ahora('2026-11-10 10:00:01');
        $this->post("/api/v1/orders/{$token}/receipt", ['receipt' => UploadedFile::fake()->image('c.jpg')], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('code', 'order_expired');
        $this->getJson("/api/v1/orders/{$token}")->assertJsonPath('status', 'expired');
    }

    public function test_el_comando_vence_las_transferencias_sin_pagar(): void
    {
        $this->crear()->assertCreated();
        $this->artisan('orders:expire')->assertSuccessful();
        $this->assertSame('created', PedidoModelo::query()->firstOrFail()->status);

        $this->ahora('2026-11-10 10:00:01');
        $this->artisan('orders:expire')->assertSuccessful();
        $p = PedidoModelo::query()->firstOrFail();
        $this->assertSame('expired', $p->status);
        $this->assertNotNull($p->expired_at);
    }

    // ---------- Paso 13: pago con tarjeta (PayPhone) ----------

    private function pedidoConTarjeta(): array
    {
        $sinRecargo = $this->crear(['payment_method' => 'card', 'expected_total_cents' => 1])->assertStatus(409)->assertJsonPath('code', 'price_changed');
        $total = (int) $sinRecargo->json('details.total_cents');
        $r = $this->crear(['payment_method' => 'card', 'expected_total_cents' => $total])->assertCreated();

        return [PedidoModelo::query()->where('number', $r->json('order_number'))->firstOrFail(), $r->json('token')];
    }

    public function test_el_pedido_con_tarjeta_suma_el_recargo_calculado_por_laravel_y_aparta_el_cupo_15_minutos(): void
    {
        Mail::fake();
        $transferencia = $this->crear()->assertCreated();
        $base = PedidoModelo::query()->where('number', $transferencia->json('order_number'))->firstOrFail();
        $esperado = (int) floor($base->total_cents * 0.06 + 0.5);

        // Con el total de transferencia (sin recargo) Laravel responde el total nuevo y el recargo
        $r = $this->crear(['payment_method' => 'card', 'expected_total_cents' => $base->total_cents])->assertStatus(409);
        $this->assertSame($base->total_cents + $esperado, $r->json('details.total_cents'));
        $this->assertSame($esperado, $r->json('details.card_fee_cents'));

        [$p] = $this->pedidoConTarjeta();
        $this->assertSame('card', $p->payment_method);
        $this->assertSame('unpaid', $p->payment_status);
        $this->assertSame($esperado, $p->card_fee_cents);
        $this->assertSame($base->total_cents + $esperado, $p->total_cents);
        $this->assertNull($p->transfer_deadline_at);
        $this->assertEquals(CarbonImmutable::now('UTC')->addMinutes(15)->timestamp, $p->card_reserved_until->timestamp);
        Mail::assertSent(CorreoDePedido::class, 1); // solo el de la transferencia: la tarjeta no manda «recibido»
    }

    public function test_al_recargar_la_pantalla_se_crea_una_referencia_nueva_y_la_anterior_sigue_confirmable(): void
    {
        [$p, $token] = $this->pedidoConTarjeta();

        $a = $this->postJson("/api/v1/orders/{$token}/payphone", ['renew' => true])->assertOk()->json('client_transaction_id');
        $b = $this->postJson("/api/v1/orders/{$token}/payphone", ['renew' => true])->assertOk()->json('client_transaction_id');

        $this->assertSame(["{$p->number}-1", "{$p->number}-2"], [$a, $b]);
        // PayPhone no acepta mostrar dos veces la misma referencia; la anterior queda pendiente por si el cliente ya aprobó en su app
        $this->assertSame('pending', IntentoDePagoModelo::query()->where('client_transaction_id', $a)->firstOrFail()->status);
        $this->postJson('/api/v1/payments/payphone/confirm', ['id' => 777, 'client_transaction_id' => $a])->assertOk()->assertJsonPath('result', 'approved');
    }

    public function test_iniciar_el_pago_devuelve_el_mismo_intento_mientras_no_venza(): void
    {
        [$p, $token] = $this->pedidoConTarjeta();

        $a = $this->postJson("/api/v1/orders/{$token}/payphone")->assertOk()->assertJsonPath('simulated', true)->assertJsonPath('box', null);
        $this->assertSame("{$p->number}-1", $a->json('client_transaction_id'));
        $b = $this->postJson("/api/v1/orders/{$token}/payphone")->assertOk();
        $this->assertSame($a->json('client_transaction_id'), $b->json('client_transaction_id')); // doble clic o recarga
        $this->assertSame(1, IntentoDePagoModelo::query()->count());
        $this->assertSame($p->total_cents, IntentoDePagoModelo::query()->firstOrFail()->expected_amount_cents);

        // Vencida la Cajita (10 min) se crea un intento nuevo
        $this->ahora('2026-11-09 10:11:00');
        $c = $this->postJson("/api/v1/orders/{$token}/payphone")->assertOk();
        $this->assertSame("{$p->number}-2", $c->json('client_transaction_id'));
        $this->assertSame('expired', IntentoDePagoModelo::query()->where('client_transaction_id', "{$p->number}-1")->firstOrFail()->status);
    }

    public function test_un_pedido_por_transferencia_no_inicia_pago_con_tarjeta(): void
    {
        $t = $this->crear()->assertCreated()->json('token');
        $this->postJson("/api/v1/orders/{$t}/payphone")->assertStatus(409)->assertJsonPath('code', 'not_card_order');
    }

    public function test_pago_aprobado_marca_el_pedido_pagado_y_una_recarga_no_reprocesa(): void
    {
        Mail::fake();
        [$p, $token] = $this->pedidoConTarjeta();
        $cx = $this->postJson("/api/v1/orders/{$token}/payphone")->json('client_transaction_id');

        $r = $this->postJson('/api/v1/payments/payphone/confirm', ['id' => 555, 'client_transaction_id' => $cx])->assertOk()
            ->assertJsonPath('result', 'approved')->assertJsonPath('token', $token);
        $this->assertSame($p->number, $r->json('order_number'));
        $p->refresh();
        $this->assertSame('paid', $p->payment_status);
        $this->assertSame('ready_for_production', $p->status);
        $intento = IntentoDePagoModelo::query()->firstOrFail();
        $this->assertSame(['approved', 555, 'SIM555'], [$intento->status, $intento->payphone_transaction_id, $intento->authorization_code]);

        // Recarga de la página de respuesta: mismo resultado, sin pagar ni notificar otra vez
        $this->postJson('/api/v1/payments/payphone/confirm', ['id' => 555, 'client_transaction_id' => $cx])->assertOk()->assertJsonPath('result', 'approved');
        $this->assertSame(1, IntentoDePagoModelo::query()->where('status', 'approved')->count());
    }

    public function test_pago_cancelado_deja_el_pedido_sin_pagar_y_permite_reintentar_con_un_intento_nuevo(): void
    {
        [$p, $token] = $this->pedidoConTarjeta();
        $cx = $this->postJson("/api/v1/orders/{$token}/payphone")->json('client_transaction_id');

        $r = $this->postJson('/api/v1/payments/payphone/confirm', ['id' => 1, 'client_transaction_id' => $cx])->assertOk()
            ->assertJsonPath('result', 'declined')->assertJsonPath('token', null)->assertJsonPath('order', null);
        $this->assertStringContainsString('No te cobramos', $r->json('message'));
        $this->assertSame('unpaid', $p->refresh()->payment_status);

        $otro = $this->postJson("/api/v1/orders/{$token}/payphone")->assertOk()->json('client_transaction_id');
        $this->assertSame("{$p->number}-2", $otro);
    }

    public function test_confirmar_un_pago_inexistente_o_sin_datos_falla(): void
    {
        $this->postJson('/api/v1/payments/payphone/confirm', ['id' => 5, 'client_transaction_id' => 'JDS-9999-1'])->assertStatus(404)->assertJsonPath('code', 'attempt_not_found');
        $this->postJson('/api/v1/payments/payphone/confirm', [])->assertStatus(422);
    }

    public function test_con_payphone_real_valida_estado_referencia_y_monto_antes_de_pagar(): void
    {
        config(['payphone.mode' => 'real', 'payphone.token' => 'TOKEN-SECRETO', 'payphone.store_id' => 'STORE-1']);
        [$p, $token] = $this->pedidoConTarjeta();
        $inicio = $this->postJson("/api/v1/orders/{$token}/payphone")->assertOk()->assertJsonPath('simulated', false);
        $cx = $inicio->json('client_transaction_id');
        $box = $inicio->json('box');
        $this->assertSame(['TOKEN-SECRETO', 'STORE-1', $p->total_cents, $p->total_cents, 'USD', 'josue@correo.com', '+593991234567'], [$box['token'], $box['store_id'], $box['amount'], $box['amount_without_tax'], $box['currency'], $box['email'], $box['phone_number']]);
        $this->assertStringContainsString($p->number, $box['reference']);

        $cx2 = "{$p->number}-2";
        Http::fake(['*' => Http::sequence()
            ->push(['message' => 'no existe', 'errorCode' => 20], 404)                                                                                               // error de PayPhone
            ->push(['statusCode' => 3, 'transactionId' => 9001, 'clientTransactionId' => $cx, 'authorizationCode' => 'AUTH77', 'amount' => 100])                     // monto alterado
            ->push(['statusCode' => 3, 'transactionId' => 9002, 'clientTransactionId' => $cx2, 'authorizationCode' => 'AUTH78', 'amount' => $p->total_cents])]);       // pago correcto

        // Error de PayPhone: no se paga y el intento sigue pendiente (se puede volver a confirmar)
        $this->postJson('/api/v1/payments/payphone/confirm', ['id' => 9001, 'client_transaction_id' => $cx])->assertOk()->assertJsonPath('result', 'error');
        $this->assertSame('pending', IntentoDePagoModelo::query()->firstOrFail()->status);

        // Monto alterado: aprobado en PayPhone pero distinto al pedido → no se paga, queda en revisión
        $this->postJson('/api/v1/payments/payphone/confirm', ['id' => 9001, 'client_transaction_id' => $cx])->assertOk()->assertJsonPath('result', 'review');
        $this->assertSame('unpaid', $p->refresh()->payment_status);
        $this->assertSame('amount_mismatch', IntentoDePagoModelo::query()->firstOrFail()->status);

        // Intento nuevo y pago correcto: se llama a Confirm con el token en el encabezado y todo coincide
        $this->ahora('2026-11-09 10:12:00');
        $this->assertSame($cx2, $this->postJson("/api/v1/orders/{$token}/payphone")->json('client_transaction_id'));
        $this->postJson('/api/v1/payments/payphone/confirm', ['id' => 9002, 'client_transaction_id' => $cx2])->assertOk()->assertJsonPath('result', 'approved');
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer TOKEN-SECRETO') && $r['id'] === 9002 && $r['clientTxId'] === $cx2 && str_ends_with($r->url(), '/api/confirm'));
        $this->assertSame('paid', $p->refresh()->payment_status);
        $this->assertSame('AUTH78', IntentoDePagoModelo::query()->where('client_transaction_id', $cx2)->firstOrFail()->authorization_code);
    }

    public function test_un_segundo_pago_aprobado_del_mismo_pedido_queda_en_revision_sin_marcar_nada_mas(): void
    {
        [$p, $token] = $this->pedidoConTarjeta();
        $cx1 = $this->postJson("/api/v1/orders/{$token}/payphone")->json('client_transaction_id');
        $this->postJson('/api/v1/payments/payphone/confirm', ['id' => 701, 'client_transaction_id' => $cx1])->assertOk()->assertJsonPath('result', 'approved');
        $pagado = $p->refresh()->paid_at;

        // Un intento viejo que también se aprueba después
        $this->ahora('2026-11-09 10:12:00');
        $viejo = IntentoDePagoModelo::query()->create(['order_id' => $p->id, 'client_transaction_id' => "{$p->number}-9", 'expected_amount_cents' => $p->total_cents, 'simulated' => true, 'expires_at' => CarbonImmutable::now('UTC')->addMinutes(5)]);
        $this->postJson('/api/v1/payments/payphone/confirm', ['id' => 702, 'client_transaction_id' => $viejo->client_transaction_id])->assertOk()->assertJsonPath('result', 'review');
        $this->assertSame('duplicate', $viejo->refresh()->status);
        $this->assertEquals($pagado, $p->refresh()->paid_at);
    }

    public function test_no_se_paga_con_tarjeta_un_pedido_cancelado_ni_se_usa_la_simulacion_en_produccion(): void
    {
        [$p, $token] = $this->pedidoConTarjeta();
        $p->update(['status' => 'cancelled']);
        $this->postJson("/api/v1/orders/{$token}/payphone")->assertStatus(409)->assertJsonPath('code', 'order_closed');
        $p->update(['status' => 'created']);

        $this->app['env'] = 'production';
        $this->postJson("/api/v1/orders/{$token}/payphone")->assertStatus(503)->assertJsonPath('code', 'payments_unavailable');
    }

    public function test_el_cupo_de_un_pedido_con_tarjeta_cuenta_15_minutos_y_luego_se_libera(): void
    {
        [$p] = $this->pedidoConTarjeta();
        $ciclo = $p->cycle_date->format('Y-m-d');
        $capacidad = app(\App\Core\Pedidos\Aplicacion\CasosDeUso\CapacidadDeCiclos::class);

        $this->assertSame(1, $capacidad->comprometidos($ciclo));
        $this->ahora('2026-11-09 10:16:00');
        $this->assertSame(0, $capacidad->comprometidos($ciclo));

        $this->artisan('orders:expire')->assertSuccessful();
        $this->assertSame('expired', $p->refresh()->status);
    }

    // ---------- Paso 17: correos del pedido ----------

    private function pedidoConCorreos(): PedidoModelo
    {
        $r = $this->crear()->assertCreated();

        return PedidoModelo::query()->where('number', $r->json('order_number'))->firstOrFail();
    }

    public function test_al_crear_el_pedido_se_envia_el_correo_de_pedido_recibido_con_total_cuentas_y_enlace(): void
    {
        Mail::fake();
        config(['app.frontend_url' => 'https://jardindesele.com']);
        $p = $this->pedidoConCorreos();

        Mail::assertSent(CorreoDePedido::class, 1);
        Mail::assertSent(CorreoDePedido::class, function (CorreoDePedido $m) use ($p) {
            $html = $m->render();

            return $m->hasTo('josue@correo.com') && $m->hasSubject("Recibimos tu pedido {$p->number}")
                && str_contains($html, 'Paga antes del') && str_contains($html, 'Banco Guayaquil')
                && str_contains($html, "https://jardindesele.com/pedido/{$p->token}") && str_contains($html, '$'.number_format($p->total_cents / 100, 2, '.', ''));
        });
        $this->assertDatabaseHas('order_events', ['order_id' => $p->id, 'type' => 'email', 'reason' => 'received', 'idempotency_key' => "correo:{$p->id}:received"]);
    }

    public function test_la_compra_nueva_se_avisa_a_pedidos_y_las_respuestas_van_al_buzon_del_tema(): void
    {
        Mail::fake();
        $p = $this->pedidoConCorreos();

        Mail::assertSent(AvisoDeCompraNueva::class, fn (AvisoDeCompraNueva $m) => $m->hasTo('pedidos@jardindesele.com') && $m->hasSubject("Compra nueva · {$p->number}"));
        Mail::assertSent(CorreoDePedido::class, fn (CorreoDePedido $m) => $m->hasReplyTo('pagos@jardindesele.com'));
        $this->assertNull(config('mail.reply_to'));   // si existiera, Laravel sumaría hola@ a las respuestas de todos los correos

        app(NotificarPedido::class)($p, NotificarPedido::RECIBIDO); // repetido: no vuelve a avisar
        Mail::assertSent(AvisoDeCompraNueva::class, 1);
    }

    public function test_el_comando_reenvia_un_correo_que_no_salio(): void
    {
        Mail::fake();
        $p = $this->pedidoConCorreos();

        $this->artisan('pedidos:reenviar-correo', ['numero' => $p->number, 'motivo' => 'received'])->assertSuccessful();
        Mail::assertSent(CorreoDePedido::class, 2);
        $this->artisan('pedidos:reenviar-correo', ['numero' => 'JDS-0'])->assertFailed();
    }

    public function test_el_mismo_correo_no_se_envia_dos_veces(): void
    {
        Mail::fake();
        $p = $this->pedidoConCorreos();
        $notificar = app(NotificarPedido::class);

        $this->assertFalse($notificar($p, NotificarPedido::RECIBIDO));
        $this->assertFalse($notificar($p, NotificarPedido::RECIBIDO));
        Mail::assertSent(CorreoDePedido::class, 1);
        $this->assertSame(1, EventoDePedidoModelo::query()->where('order_id', $p->id)->count());
    }

    public function test_pago_enviado_y_vencido_envian_su_correo_una_sola_vez(): void
    {
        Mail::fake();
        $this->withoutDefer(); // sin petición HTTP: los correos salen al instante
        $gestionar = app(GestionarPedido::class);
        $p = $this->pedidoConCorreos();

        $gestionar->marcarPagado($p);
        $gestionar->marcarEnProduccion($p->refresh());
        $gestionar->marcarEnviado($p->refresh(), 'SV123456');
        $gestionar->marcarEnviado($p->refresh(), 'SV123457'); // corregir la guía no reenvía

        Mail::assertSent(CorreoDePedido::class, 3); // recibido + pagado + enviado
        Mail::assertSent(CorreoDePedido::class, fn (CorreoDePedido $m) => $m->hasSubject("Pago confirmado · Pedido {$p->number}") && str_contains($m->render(), 'Entrega estimada'));
        Mail::assertSent(CorreoDePedido::class, fn (CorreoDePedido $m) => $m->hasSubject("Tu pedido {$p->number} va en camino") && str_contains($m->render(), 'SV123456'));

        $otro = PedidoModelo::query()->where('id', '!=', $p->id)->first() ?? $this->pedidoConCorreos();
        $gestionar->vencer(CarbonImmutable::now('UTC')->addDays(3));
        Mail::assertSent(CorreoDePedido::class, fn (CorreoDePedido $m) => $m->hasSubject("Tu pedido {$otro->number} venció"));
        $gestionar->vencer(CarbonImmutable::now('UTC')->addDays(4));
        $this->assertSame(1, EventoDePedidoModelo::query()->where('order_id', $otro->id)->where('reason', 'expired')->count());
    }

    public function test_cancelar_solo_envia_correo_si_josue_marca_notificar_y_lleva_el_motivo(): void
    {
        Mail::fake();
        $sin = $this->pedidoConCorreos();
        $this->admin()->postJson("/api/v1/admin/orders/{$sin->number}/cancel")->assertOk();
        Mail::assertSent(CorreoDePedido::class, 1); // solo el de «recibido»

        $p = $this->pedidoConCorreos();
        $this->admin()->postJson("/api/v1/admin/orders/{$p->number}/cancel", ['notify_buyer' => true, 'reason' => '<b>Sin stock</b> del producto'])->assertOk();
        Mail::assertSent(CorreoDePedido::class, fn (CorreoDePedido $m) => $m->hasSubject("Tu pedido {$p->number} fue cancelado") && str_contains($m->render(), 'Sin stock del producto'));
    }

    public function test_si_el_correo_falla_el_pedido_se_crea_igual_y_se_puede_reintentar(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caído'));
        $p = $this->pedidoConCorreos();

        $this->assertNotNull($p->number);
        $this->assertSame(0, EventoDePedidoModelo::query()->where('order_id', $p->id)->count()); // quedó libre para reintentar
    }

    private function admin(): static
    {
        return $this->actingAs(User::factory()->administrador()->create(), 'api');
    }

    public function test_solo_el_administrador_gestiona_pedidos(): void
    {
        $this->crear()->assertCreated();
        $n = PedidoModelo::query()->firstOrFail()->number;
        $this->getJson('/api/v1/admin/orders')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'api');
        $this->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->postJson("/api/v1/admin/orders/{$n}/mark-paid")->assertForbidden();
    }

    public function test_el_admin_busca_pedidos_por_codigo_dentro_del_filtro(): void
    {
        $this->crear();
        $this->admin();
        $n = PedidoModelo::query()->firstOrFail()->number;
        $numeros = substr($n, strrpos($n, '-') + 1);

        $this->getJson('/api/v1/admin/orders?status=pending&q='.$n)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $n);
        $this->getJson('/api/v1/admin/orders?status=pending&q='.strtolower($n))->assertOk()->assertJsonCount(1, 'data');   // sin importar mayúsculas
        $this->getJson("/api/v1/admin/orders?status=pending&q={$numeros}")->assertOk()->assertJsonCount(1, 'data');       // solo los números
        $this->getJson('/api/v1/admin/orders?status=pending&q=JDS-9999')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/orders?status=shipped&q='.$n)->assertOk()->assertJsonCount(0, 'data');             // otro filtro: no aparece
        $this->getJson('/api/v1/admin/orders?status=pending&q=%25')->assertOk()->assertJsonCount(0, 'data');             // % no es comodín
    }

    public function test_marcar_pagado_conserva_el_ciclo_y_el_comprador_ve_el_seguimiento(): void
    {
        $token = $this->crear()->json('token');
        $this->admin();
        $n = PedidoModelo::query()->firstOrFail()->number;

        $this->getJson('/api/v1/admin/orders?status=pending')->assertOk()->assertJsonPath('data.0.number', $n);
        $this->postJson("/api/v1/admin/orders/{$n}/mark-paid")->assertOk()->assertJsonPath('payment_status', 'paid')->assertJsonPath('cycle_date', '2026-11-09');
        $this->postJson("/api/v1/admin/orders/{$n}/mark-paid")->assertStatus(409)->assertJsonPath('code', 'already_paid');

        $this->getJson("/api/v1/orders/{$token}")->assertJsonPath('visible_status', 'confirmado')->assertJsonPath('payment.status', 'paid');
    }

    /** @return array<string, AccesorioModelo> accesorios de prueba, con los comportamientos que el pedido debe exigir */
    private function accesorios(): array
    {
        $cat = CategoriaDeAccesorioModelo::query()->create(['name' => 'Moda', 'sort_order' => 1]);
        $nuevo = fn (string $nombre, ?string $comportamiento) => AccesorioModelo::query()->create(['accessory_category_id' => $cat->id, 'name' => $nombre, 'behavior' => $comportamiento, 'is_active' => true]);

        return ['lentes' => $nuevo('Lentes', 'lentes'), 'marco_foto' => $nuevo('Marco con foto', 'marco_foto'), 'marco_frase' => $nuevo('Marco con frase', 'marco_frase'), 'libre' => $nuevo('Estetoscopio', null)];
    }

    /** @param array<string, mixed> $cambios */
    private function conPersonalizacion(array $cambios): \Illuminate\Testing\TestResponse
    {
        $base = ['name_text' => 'Ana', 'dedication' => ['mode' => 'none', 'text' => '']];

        return $this->crear(['items' => [['personalization' => array_replace($base, $cambios)]]]);
    }

    public function test_guarda_accesorios_colores_frase_y_foto_del_marco_y_el_administrador_los_ve(): void
    {
        Storage::fake('private');
        $a = $this->accesorios();
        $r = $this->conPersonalizacion([
            'accessory_ids' => [$a['lentes']->id, $a['marco_frase']->id, $a['marco_foto']->id, $a['libre']->id],
            'lens_color' => 'cafe', 'frame_text' => '  Mi doctora favorita ', 'frame_photo' => self::PNG,
        ])->assertCreated();

        $this->admin();
        $n = $r->json('order_number');
        $item = $this->getJson("/api/v1/admin/orders/{$n}")->assertOk()->json('items.0');
        $this->assertSame('cafe', $item['personalization']['lens_color']);
        $this->assertSame('Mi doctora favorita', $item['personalization']['frame_text']);
        $this->assertTrue($item['personalization']['has_frame_photo']);
        $this->assertSame(['Lentes', 'Marco con frase', 'Marco con foto', 'Estetoscopio'], array_column($item['personalization']['accessories'], 'name'));
        $this->assertTrue($item['has_preview']);
        $this->assertTrue($item['has_frame_photo']);

        // Las fotos solo las descarga el administrador
        $this->get("/api/v1/admin/orders/{$n}/items/{$item['id']}/frame-photo")->assertOk();
        $this->get("/api/v1/admin/orders/{$n}/items/{$item['id']}/preview")->assertOk();
        $this->get("/api/v1/admin/orders/{$n}/items/999999/frame-photo")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(), 'api');
        $this->get("/api/v1/admin/orders/{$n}/items/{$item['id']}/frame-photo")->assertForbidden();
    }

    public function test_exige_lo_que_pide_cada_accesorio_y_rechaza_accesorios_inexistentes(): void
    {
        $a = $this->accesorios();

        $this->conPersonalizacion(['accessory_ids' => [$a['marco_frase']->id], 'frame_text' => ''])->assertStatus(422);
        $this->conPersonalizacion(['accessory_ids' => [$a['marco_frase']->id], 'frame_text' => ''])->assertJsonPath('errors', fn ($e) => isset($e['items.0.personalization.frame_text']));
        $this->conPersonalizacion(['accessory_ids' => [$a['marco_foto']->id]])->assertJsonPath('errors', fn ($e) => isset($e['items.0.personalization.frame_photo']));
        $this->conPersonalizacion(['accessory_ids' => [$a['marco_foto']->id], 'frame_photo' => 'data:text/html;base64,AAAA'])->assertJsonPath('errors', fn ($e) => isset($e['items.0.personalization.frame_photo']));
        $this->conPersonalizacion(['accessory_ids' => [$a['lentes']->id]])->assertJsonPath('errors', fn ($e) => isset($e['items.0.personalization.lens_color']));
        $this->conPersonalizacion(['accessory_ids' => [$a['lentes']->id], 'lens_color' => 'fucsia'])->assertJsonPath('errors', fn ($e) => isset($e['items.0.personalization.lens_color']));
        $this->conPersonalizacion(['accessory_ids' => [987654]])->assertJsonPath('errors', fn ($e) => isset($e['items.0.personalization.accessory_ids']));

        $a['libre']->update(['is_active' => false]);
        $this->conPersonalizacion(['accessory_ids' => [$a['libre']->id]])->assertStatus(422);
        $this->assertSame(0, PedidoModelo::query()->count());
    }

    public function test_produccion_y_envio_con_guia_se_ven_en_el_seguimiento_del_comprador(): void
    {
        $token = $this->crear()->json('token');
        $this->admin();
        $n = PedidoModelo::query()->firstOrFail()->number;

        // Antes de pagar no se puede producir ni enviar
        $this->postJson("/api/v1/admin/orders/{$n}/production")->assertStatus(409)->assertJsonPath('code', 'not_paid');
        $this->postJson("/api/v1/admin/orders/{$n}/mark-paid")->assertOk();
        $this->getJson('/api/v1/admin/orders?status=to_produce')->assertJsonPath('data.0.number', $n);

        // Y tampoco enviar sin haber pasado por producción
        $this->postJson("/api/v1/admin/orders/{$n}/ship", ['tracking_number' => '1234567890'])->assertStatus(409)->assertJsonPath('code', 'not_in_production');

        $this->postJson("/api/v1/admin/orders/{$n}/production")->assertOk()->assertJsonPath('status', 'in_production');
        $this->getJson("/api/v1/orders/{$token}")->assertJsonPath('visible_status', 'en_produccion')->assertJsonPath('shipment.tracking_number', null);
        $this->getJson('/api/v1/admin/orders?status=in_production')->assertJsonPath('data.0.number', $n);

        // La guía es obligatoria y válida
        $this->postJson("/api/v1/admin/orders/{$n}/ship", ['tracking_number' => ''])->assertStatus(422)->assertJsonPath('errors.tracking_number.0', fn ($m) => str_contains($m, 'guía'));
        $this->postJson("/api/v1/admin/orders/{$n}/ship", ['tracking_number' => 'ab !'])->assertStatus(422);

        $this->postJson("/api/v1/admin/orders/{$n}/ship", ['tracking_number' => ' 1234567890 '])->assertOk()
            ->assertJsonPath('status', 'shipped')->assertJsonPath('tracking_number', '1234567890');
        $this->getJson("/api/v1/orders/{$token}")->assertJsonPath('visible_status', 'enviado')->assertJsonPath('shipment.tracking_number', '1234567890')
            ->assertJsonPath('shipment.tracking_url', 'https://www.servientrega.com.ec/');

        // Con {guia} en el ajuste, el número va dentro del enlace; un enlace que no es https no se muestra
        Ajuste::query()->where('key', 'tracking_url')->update(['value' => json_encode('https://rastreo.test/?guia={guia}')]);
        $this->getJson("/api/v1/orders/{$token}")->assertJsonPath('shipment.tracking_url', 'https://rastreo.test/?guia=1234567890');
        Ajuste::query()->where('key', 'tracking_url')->update(['value' => json_encode('javascript:alert(1)')]);
        $this->getJson("/api/v1/orders/{$token}")->assertJsonPath('shipment.tracking_url', null);
        Ajuste::query()->where('key', 'tracking_url')->update(['value' => json_encode('https://www.servientrega.com.ec/')]);

        // Ya enviado: no se cancela, no vuelve a producción, pero se puede corregir la guía
        $this->postJson("/api/v1/admin/orders/{$n}/cancel")->assertStatus(409)->assertJsonPath('code', 'already_shipped');
        $this->postJson("/api/v1/admin/orders/{$n}/production")->assertStatus(409)->assertJsonPath('code', 'already_shipped');
        $this->postJson("/api/v1/admin/orders/{$n}/ship", ['tracking_number' => '99999-AB'])->assertOk()->assertJsonPath('tracking_number', '99999-AB');
        $this->getJson('/api/v1/admin/orders?status=shipped')->assertJsonPath('data.0.number', $n);
    }

    public function test_un_pedido_cancelado_no_se_produce_ni_se_envia(): void
    {
        $this->crear();
        $this->admin();
        $n = PedidoModelo::query()->firstOrFail()->number;
        $this->postJson("/api/v1/admin/orders/{$n}/cancel")->assertOk();

        $this->postJson("/api/v1/admin/orders/{$n}/production")->assertStatus(409)->assertJsonPath('code', 'order_cancelled');
        $this->postJson("/api/v1/admin/orders/{$n}/ship", ['tracking_number' => '1234567890'])->assertStatus(409);
    }

    public function test_un_pedido_vencido_marcado_pagado_conserva_su_ciclo_y_sobre_10_pide_confirmacion(): void
    {
        $this->crear()->assertCreated();
        $vencido = PedidoModelo::query()->firstOrFail();
        $this->ahora('2026-11-10 10:00:01');
        $this->app->make(GestionarPedido::class)->vencer();
        $this->admin();

        $this->postJson("/api/v1/admin/orders/{$vencido->number}/mark-paid")->assertOk()->assertJsonPath('cycle_date', '2026-11-09');

        // Ciclo con 10 pagados: otro vencido que pagó a tiempo pide confirmación explícita
        for ($i = 0; $i < 10; $i++) {
            PedidoModelo::query()->create($this->base($vencido, ['number' => "JDS-P{$i}", 'token' => bin2hex(random_bytes(32)), 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'payment_status' => 'paid', 'status' => 'ready_for_production']));
        }
        $otro = PedidoModelo::query()->create($this->base($vencido, ['number' => 'JDS-X', 'token' => bin2hex(random_bytes(32)), 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'payment_status' => 'pending_validation', 'status' => 'expired']));
        $this->postJson('/api/v1/admin/orders/JDS-X/mark-paid')->assertStatus(409)->assertJsonPath('code', 'capacity_over_limit');
        $this->postJson('/api/v1/admin/orders/JDS-X/mark-paid', ['confirm_over_capacity' => true])->assertOk()->assertJsonPath('payment_status', 'paid');
        $this->assertNotNull($otro);
    }

    /** @return array<string, mixed> */
    private function base(PedidoModelo $modelo, array $cambios): array
    {
        return $cambios + collect($modelo->getAttributes())->except(['id', 'number', 'token', 'uuid', 'created_at', 'updated_at'])->map(fn ($v) => $v)->all();
    }

    public function test_pagado_fuera_de_plazo_asigna_el_siguiente_ciclo_y_actualiza_la_ventana(): void
    {
        $this->crear()->assertCreated();
        $n = PedidoModelo::query()->firstOrFail()->number;
        $this->ahora('2026-11-10 21:00:00');   // martes 21:00: pagó tarde
        $this->app->make(GestionarPedido::class)->vencer();
        $this->admin();

        $this->postJson("/api/v1/admin/orders/{$n}/mark-paid", ['late' => true])->assertOk()
            ->assertJsonPath('cycle_date', '2026-11-11')->assertJsonPath('window.start', '2026-11-14');
    }

    public function test_cancelar_libera_el_cupo_y_un_cancelado_no_se_marca_pagado(): void
    {
        $this->crear()->assertCreated();
        $n = PedidoModelo::query()->firstOrFail()->number;
        $this->admin();

        $this->postJson("/api/v1/admin/orders/{$n}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson("/api/v1/admin/orders/{$n}/mark-paid")->assertStatus(409)->assertJsonPath('code', 'order_cancelled');
        $this->postJson('/api/v1/admin/orders/JDS-0/cancel')->assertNotFound();
    }

    public function test_el_administrador_descarga_el_comprobante(): void
    {
        $token = $this->crear()->json('token');
        $this->post("/api/v1/orders/{$token}/receipt", ['receipt' => UploadedFile::fake()->image('c.jpg')], ['Accept' => 'application/json'])->assertOk();
        $n = PedidoModelo::query()->firstOrFail()->number;
        $this->admin();

        $this->getJson('/api/v1/admin/orders')->assertJsonPath('data.0.receipt_uploaded_at', CarbonImmutable::now()->format('Y-m-d\TH:i:s\Z'));
        $this->get("/api/v1/admin/orders/{$n}/receipt")->assertOk();
        $this->crear(['shipping' => ['email' => 'otro@correo.com']])->assertCreated();
        $otro = PedidoModelo::query()->latest('id')->first()->number;
        $this->getJson("/api/v1/admin/orders/{$otro}/receipt")->assertNotFound();
    }
}
