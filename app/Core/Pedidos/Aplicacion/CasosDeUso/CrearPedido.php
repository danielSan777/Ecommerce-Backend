<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\ValueObjects\Precio;
use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion;
use App\Core\Entrega\Aplicacion\CasosDeUso\ConsultarEntrega;
use App\Core\Entrega\Dominio\MotorDeEntrega;
use App\Core\Entrega\Dominio\TokenDePromesa;
use App\Core\Envios\Infraestructura\Modelos\CantonModelo;
use App\Core\Pedidos\Dominio\Documentos;
use App\Core\Pedidos\Dominio\PedidoRechazado;
use App\Core\Pedidos\Infraestructura\Modelos\ClienteModelo;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use App\Shared\Dominio\BusinessCalendar;
use App\Shared\Presentacion\ValidacionFallida;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Paso 12 (transferencia): crea el pedido. Nada de lo que envía Angular decide el total: precios, descuentos, envío y
 * ventana se recalculan aquí. Si difieren de lo que el comprador vio, se responde `price_changed` o `window_changed`
 * con el valor nuevo para mostrarlo antes de pagar.
 */
final class CrearPedido
{
    public function __construct(
        private readonly RepositorioConfiguracion $ajustes,
        private readonly MotorDeEntrega $motor,
        private readonly CapacidadDeCiclos $capacidad,
        private readonly ConsultarEntrega $entrega,
        private readonly TokenDePromesa $tokens,
        private readonly BusinessCalendar $calendario,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  petición ya validada en forma (ver ControladorCheckout)
     */
    public function __invoke(array $datos, ?string $ip, ?string $userAgent, ?CarbonImmutable $ahora = null): PedidoModelo
    {
        $ahora ??= CarbonImmutable::now('UTC');

        if (($datos['payment_method'] ?? '') !== 'transfer') {
            throw new PedidoRechazado('payment_method_unavailable', 'El pago con tarjeta estará disponible pronto. Por ahora elige transferencia bancaria.');
        }

        $envio = $this->normalizarEnvio($datos['shipping']);
        $facturacion = ($datos['billing']['same_as_shipping'] ?? true) ? ['same_as_shipping' => true] : $this->normalizarFacturacion($datos['billing']);
        $contacto = ($datos['contact']['same_as_shipping'] ?? true) ? ['same_as_shipping' => true, 'phone' => $envio['phone']] : $this->normalizarContacto($datos['contact']);

        [$lineas, $macetas] = $this->armarLineas($datos['items']);
        $canton = CantonModelo::query()->with(['zona', 'provincia'])->where('code', $envio['canton_code'])->first();
        if ($canton === null || ($canton->provincia->code !== $envio['province_code'])) {
            throw new ValidacionFallida(['shipping.canton_code' => ['Elige un cantón válido.']]);
        }
        if (! $canton->zona->is_available) {
            throw new PedidoRechazado('zone_unavailable', 'Por ahora no enviamos a esta zona desde la web. Escríbenos por WhatsApp y lo coordinamos.');
        }

        $subtotal = array_sum(array_column($lineas, 'line_total_cents'));
        $gratis = $macetas >= (int) $this->ajustes->obtener('free_shipping_min_planters', 2);
        $envioCents = $gratis ? 0 : $canton->zona->price_cents;
        $total = $subtotal + $envioCents;

        if (isset($datos['expected_total_cents']) && (int) $datos['expected_total_cents'] !== $total) {
            throw new PedidoRechazado('price_changed', 'Actualizamos el total de tu pedido. Revísalo antes de pagar.', 409, ['total_cents' => $total, 'shipping_cents' => $envioCents, 'subtotal_cents' => $subtotal]);
        }

        $pedido = DB::transaction(function () use ($datos, $envio, $facturacion, $contacto, $lineas, $canton, $subtotal, $envioCents, $total, $ahora, $ip, $userAgent) {
            [$ciclo, $ventana, $gracia, $despacho, $tipo] = $this->asignar($datos, $canton->zona->extra_days, $ahora);

            $horas = (int) $this->ajustes->obtener('transfer_window_hours', 12);
            $cliente = ClienteModelo::query()->updateOrCreate(
                ['email' => $envio['email']],
                ['name' => $envio['name'], 'phone' => $contacto['phone'], 'id_type' => $envio['id_type'], 'id_number' => $envio['id_number']],
            );

            $uuid = (string) Str::uuid();
            $pedido = PedidoModelo::query()->create([
                'uuid' => $uuid,
                'token' => bin2hex(random_bytes(32)),
                'customer_id' => $cliente->id,
                'channel' => 'web', 'payment_method' => 'transfer', 'payment_status' => 'pending_validation', 'status' => 'created',
                'subtotal_cents' => $subtotal, 'discount_cents' => 0, 'shipping_cents' => $envioCents, 'card_fee_cents' => 0, 'total_cents' => $total,
                'shipping_zone' => $canton->zona->code,
                'shipping' => $envio + ['province_name' => $canton->provincia->name, 'canton_name' => $canton->name],
                'billing' => $facturacion, 'contact' => $contacto,
                'window_type' => $tipo, 'window_start' => $ventana['start'], 'window_end' => $ventana['end'],
                'cycle_date' => $ciclo, 'dispatch_date' => $despacho, 'grace_applied' => $gracia,
                'transfer_deadline_at' => $ahora->addHours($horas),
                'event_id' => 'purchase-'.$uuid,
                'terms_version' => (string) $datos['terms']['version'], 'terms_accepted_at' => $ahora,
                'ip' => $ip, 'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                'tracking' => $datos['tracking'] ?? null,
            ]);
            $pedido->update(['number' => 'JDS-'.(1000 + $pedido->id)]);

            foreach ($lineas as $i => $linea) {
                $vista = $linea['preview_image'];
                unset($linea['preview_image']);
                $item = $pedido->items()->create($linea);
                if ($vista !== null) {
                    $ruta = "pedidos/{$pedido->uuid}/item-{$item->id}.jpg";
                    Storage::disk('private')->put($ruta, $vista);
                    $item->update(['preview_path' => $ruta]);
                }
            }

            return $pedido;
        });

        return $pedido->load('items', 'cliente');
    }

    // ---------- Ciclo y ventana ----------

    /** @return array{0: string, 1: array{start: string, end: string}, 2: bool, 3: string, 4: string} ciclo, ventana, gracia, despacho, tipo */
    private function asignar(array $datos, int $diasAdicionales, CarbonImmutable $ahora): array
    {
        $eleccion = $datos['window_choice'] ?? ['type' => 'immediate'];
        $ciclo = $this->motor->ciclo($ahora)['cycle_date'];
        $gracia = false;

        if (($eleccion['type'] ?? 'immediate') === 'scheduled') {
            $inicio = (string) ($eleccion['start'] ?? '');
            $primera = $this->entrega->promesa($diasAdicionales, $ahora)['window']['start'];
            $valida = collect($this->motor->ventanasDesde($primera, $diasAdicionales))->firstWhere('start', $inicio);
            if ($valida === null) {
                throw new PedidoRechazado('window_unavailable', 'La fecha que elegiste ya no está disponible. Elige otra.', 409);
            }
            // Un pedido programado se cuenta en el ciclo que lo despacha: el último ciclo cuya ventana empieza a más tardar ese día.
            $candidato = $ciclo;
            for ($i = 0; $i < 60; $i++) {
                $siguiente = $this->capacidad->siguienteDiaDeProduccion($candidato);
                $v = $this->motor->ventana($this->motor->despacho($siguiente), $diasAdicionales);
                if ($v['start'] > $inicio) {
                    break;
                }
                $candidato = $siguiente;
            }
            $this->capacidad->bloquear($candidato);
            if (! $this->capacidad->hayCupo($candidato)) {
                throw new PedidoRechazado('capacity_full', 'Esa fecha ya no tiene cupo. Elige otra.', 409);
            }

            return [$candidato, $valida, false, $this->motor->despacho($candidato), 'scheduled'];
        }

        // Lo antes posible: gracia de 30 min si el token es válido, se emitió antes del corte de su ciclo y el corte pasó hace poco.
        $token = isset($datos['promise_token']) ? $this->tokens->verificar((string) $datos['promise_token']) : null;
        if ($token !== null) {
            $corte = $this->motor->corteDe($token['cycle_date']);
            $minutos = (int) $this->ajustes->obtener('grace_minutes', 30);
            if ($token['issued_at'] <= $corte && $ahora > $corte && $ahora <= $corte->addMinutes($minutos)) {
                $this->capacidad->bloquear($token['cycle_date']);
                if ($this->capacidad->hayCupo($token['cycle_date'], true)) {
                    $ciclo = $token['cycle_date'];
                    $gracia = true;
                }
            }
        }

        if (! $gracia) {
            for ($i = 0; $i < 60; $i++) {
                $this->capacidad->bloquear($ciclo);
                if ($this->capacidad->hayCupo($ciclo)) {
                    break;
                }
                $ciclo = $this->capacidad->siguienteDiaDeProduccion($ciclo);
            }
        }

        $despacho = $this->motor->despacho($ciclo);
        $ventana = $this->motor->ventana($despacho, $diasAdicionales);

        $esperada = $datos['expected_window'] ?? null;
        if (is_array($esperada) && (($esperada['start'] ?? null) !== $ventana['start'] || ($esperada['end'] ?? null) !== $ventana['end'])) {
            throw new PedidoRechazado('window_changed', 'Actualizamos tu fecha estimada. Revísala antes de pagar.', 409, [
                'window' => $ventana, 'label' => $this->etiqueta($ventana),
            ]);
        }

        return [$ciclo, $ventana, $gracia, $despacho, 'immediate'];
    }

    /** @param array{start: string, end: string} $v */
    private function etiqueta(array $v): string
    {
        return 'Recíbelo entre '.$this->entrega->diasEnTexto($v['start'], $v['end']);
    }

    // ---------- Líneas y precios ----------

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{0: list<array<string, mixed>>, 1: int} líneas listas para guardar y cantidad de macetas
     */
    private function armarLineas(array $items): array
    {
        $lineas = [];
        $macetas = 0;
        foreach ($items as $i => $item) {
            $producto = ProductoModelo::query()->where('slug', (string) $item['slug'])->where('is_active', true)->first();
            if ($producto === null) {
                throw new ValidacionFallida(["items.$i.slug" => ['Uno de los productos ya no está disponible. Revisa tu carrito.']]);
            }
            $personalizable = $producto->type === 'personalizable';
            $cantidad = $personalizable ? 1 : max(1, min(20, (int) ($item['quantity'] ?? 1)));
            $personalizacion = $item['personalization'] ?? null;

            if ($personalizable) {
                $macetas++;
                $this->validarPersonalizacion($personalizacion, $i);
            }

            $precio = Precio::conDescuentoPorcentaje((int) $producto->base_price_cents, $producto->discount_percent ?? 0);
            $lineas[] = [
                'product_sku' => $producto->sku, 'product_slug' => $producto->slug, 'product_name' => $producto->name, 'type' => $producto->type,
                'quantity' => $cantidad, 'unit_price_cents' => $precio->baseCents, 'discount_cents' => $precio->descuentoCents() * $cantidad,
                'cost_cents' => (int) $producto->cost_cents, 'line_total_cents' => $precio->finalCents * $cantidad,
                'personalization' => $personalizacion, 'preview_image' => $this->imagen($item['preview_image'] ?? null, $i),
            ];
        }

        if ($macetas === 0) {
            throw new PedidoRechazado('planter_required', 'Agrega al menos una maceta para continuar.');
        }

        return [$lineas, $macetas];
    }

    private function validarPersonalizacion(mixed $p, int $i): void
    {
        $errores = [];
        $p = is_array($p) ? $p : [];
        $nombre = trim((string) ($p['name_text'] ?? ''));
        if (mb_strlen($nombre) > 30) {
            $errores["items.$i.personalization.name_text"] = ['El nombre no puede pasar de 30 caracteres.'];
        }
        $dedicatoria = trim((string) ($p['dedication']['text'] ?? ''));
        $modo = $p['dedication']['mode'] ?? null;
        if (! in_array($modo, ['text', 'none'], true) || ($modo === 'text' && $dedicatoria === '')) {
            $errores["items.$i.personalization.dedication"] = ['Escribe la dedicatoria o elige «No quiero dedicatoria».'];
        }
        if ($errores !== []) {
            throw new ValidacionFallida($errores);
        }
    }

    /** Foto de la maceta personalizada (data URL JPEG o PNG, hasta 600 KB). Es solo la vista previa: no decide nada. */
    private function imagen(mixed $dataUrl, int $i): ?string
    {
        if (! is_string($dataUrl) || $dataUrl === '') {
            return null;
        }
        if (! preg_match('#^data:image/(jpeg|png);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            throw new ValidacionFallida(["items.$i.preview_image" => ['La foto de la maceta no es válida.']]);
        }
        $binario = base64_decode($m[2], true);
        if ($binario === false || strlen($binario) > 600 * 1024 || @getimagesizefromstring($binario) === false) {
            throw new ValidacionFallida(["items.$i.preview_image" => ['La foto de la maceta no es válida o pesa demasiado.']]);
        }

        return $binario;
    }

    // ---------- Datos del comprador ----------

    /** @param array<string, mixed> $e @return array<string, mixed> */
    private function normalizarEnvio(array $e): array
    {
        $errores = [];
        $nombre = trim((string) ($e['name'] ?? ''));
        if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 150) {
            $errores['shipping.name'] = ['Escribe tu nombre completo.'];
        }
        $tipo = ($e['id_type'] ?? '') === 'ruc' ? 'ruc' : 'cedula';
        $numero = preg_replace('/\D/', '', (string) ($e['id_number'] ?? '')) ?? '';
        if (! Documentos::documentoValido($tipo, $numero)) {
            $errores['shipping.id_number'] = [$tipo === 'ruc' ? 'Ingresa un RUC válido de 13 dígitos.' : 'Ingresa una cédula válida de 10 dígitos.'];
        }
        $telefono = Documentos::telefono((string) ($e['phone'] ?? ''));
        if ($telefono === null) {
            $errores['shipping.phone'] = ['Escribe un celular válido. Ej. 099 123 4567'];
        }
        $correo = Documentos::correo((string) ($e['email'] ?? ''));
        if ($correo === null) {
            $errores['shipping.email'] = ['Ingresa un correo válido.'];
        }
        $direccion = trim((string) ($e['address'] ?? ''));
        if (mb_strlen($direccion) < 5 || mb_strlen($direccion) > 255) {
            $errores['shipping.address'] = ['Escribe tu dirección completa.'];
        }
        if ($errores !== []) {
            throw new ValidacionFallida($errores);
        }

        return [
            'name' => $nombre, 'id_type' => $tipo, 'id_number' => $numero, 'phone' => $telefono, 'email' => $correo,
            'province_code' => (string) ($e['province_code'] ?? ''), 'canton_code' => (string) ($e['canton_code'] ?? ''),
            'address' => $direccion, 'reference' => mb_substr(trim((string) ($e['reference'] ?? '')), 0, 255),
        ];
    }

    /** @param array<string, mixed> $b @return array<string, mixed> */
    private function normalizarFacturacion(array $b): array
    {
        $errores = [];
        $nombre = trim((string) ($b['name'] ?? ''));
        if (mb_strlen($nombre) < 3) {
            $errores['billing.name'] = ['Escribe el nombre o razón social.'];
        }
        $tipo = ($b['id_type'] ?? '') === 'ruc' ? 'ruc' : 'cedula';
        $numero = preg_replace('/\D/', '', (string) ($b['id_number'] ?? '')) ?? '';
        if (! Documentos::documentoValido($tipo, $numero)) {
            $errores['billing.id_number'] = [$tipo === 'ruc' ? 'Ingresa un RUC válido de 13 dígitos.' : 'Ingresa una cédula válida de 10 dígitos.'];
        }
        $correo = Documentos::correo((string) ($b['email'] ?? ''));
        if ($correo === null) {
            $errores['billing.email'] = ['Ingresa un correo válido.'];
        }
        $telefono = Documentos::telefono((string) ($b['phone'] ?? ''));
        if ($telefono === null) {
            $errores['billing.phone'] = ['Escribe un celular válido. Ej. 099 123 4567'];
        }
        $direccion = trim((string) ($b['address'] ?? ''));
        if (mb_strlen($direccion) < 5) {
            $errores['billing.address'] = ['Escribe la dirección de facturación.'];
        }
        if ($errores !== []) {
            throw new ValidacionFallida($errores);
        }

        return [
            'same_as_shipping' => false, 'name' => $nombre, 'id_type' => $tipo, 'id_number' => $numero, 'email' => $correo, 'phone' => $telefono,
            'province_code' => (string) ($b['province_code'] ?? ''), 'canton_code' => (string) ($b['canton_code'] ?? ''), 'address' => $direccion,
        ];
    }

    /** @param array<string, mixed> $c @return array<string, mixed> */
    private function normalizarContacto(array $c): array
    {
        $telefono = Documentos::telefono((string) ($c['phone'] ?? ''));
        if ($telefono === null) {
            throw new ValidacionFallida(['contact.phone' => ['Escribe un celular válido. Ej. 099 123 4567']]);
        }

        return ['same_as_shipping' => false, 'phone' => $telefono];
    }
}
