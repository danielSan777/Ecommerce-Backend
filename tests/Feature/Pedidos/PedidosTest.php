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
use App\Core\Pedidos\Infraestructura\Correo\CorreoDePedido;
use App\Core\Pedidos\Infraestructura\Modelos\EventoDePedidoModelo;
use Illuminate\Support\Facades\Mail;
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
        $this->crear(['payment_method' => 'card'])->assertStatus(422)->assertJsonPath('code', 'payment_method_unavailable');
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
