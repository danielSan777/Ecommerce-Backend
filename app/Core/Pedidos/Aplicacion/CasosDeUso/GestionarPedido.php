<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Aplicacion\CasosDeUso;

use App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion;
use App\Core\Entrega\Dominio\MotorDeEntrega;
use App\Core\Pedidos\Dominio\PedidoRechazado;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use App\Shared\Presentacion\ValidacionFallida;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Paso 14: comprobante, clic de WhatsApp, vencimiento y las acciones de Josué (marcar pagado, cancelar). */
final class GestionarPedido
{
    public function __construct(
        private readonly RepositorioConfiguracion $ajustes,
        private readonly MotorDeEntrega $motor,
        private readonly CapacidadDeCiclos $capacidad,
        private readonly NotificarPedido $correo,
    ) {}

    /** El correo sale después de responder (no hace esperar a nadie) y, si falla, no rompe la acción. */
    private function avisar(PedidoModelo $p, string $motivo, array $extra = []): void
    {
        defer(fn () => ($this->correo)($p->refresh(), $motivo, $extra));
    }

    /** Sube el comprobante (JPG, PNG o PDF) al disco privado, con límite de intentos. */
    public function subirComprobante(PedidoModelo $p, UploadedFile $archivo, ?CarbonImmutable $ahora = null): PedidoModelo
    {
        $ahora ??= CarbonImmutable::now('UTC');
        $this->exigirPendienteVigente($p, $ahora);

        $maximo = (int) $this->ajustes->obtener('max_receipt_attempts', 5);
        if ($p->receipt_attempts >= $maximo) {
            throw new PedidoRechazado('upload_invalid', 'Ya subiste el comprobante varias veces. Escríbenos por WhatsApp y lo revisamos.', 429);
        }

        $ruta = $archivo->storeAs("comprobantes/{$p->uuid}", bin2hex(random_bytes(8)).'.'.$archivo->extension(), 'private');
        if ($ruta === false) {
            throw new PedidoRechazado('upload_invalid', 'No pudimos guardar el comprobante. Inténtalo de nuevo.', 500);
        }
        if ($p->receipt_path !== null) {
            Storage::disk('private')->delete($p->receipt_path);
        }
        $p->update([
            'receipt_path' => $ruta, 'receipt_original_name' => mb_substr($archivo->getClientOriginalName(), 0, 255),
            'receipt_uploaded_at' => $ahora, 'receipt_attempts' => $p->receipt_attempts + 1,
        ]);

        return $p->refresh();
    }

    public function registrarClicDeWhatsapp(PedidoModelo $p, ?CarbonImmutable $ahora = null): void
    {
        $p->update(['whatsapp_clicked_at' => $ahora ?? CarbonImmutable::now('UTC')]);
    }

    /** Pasa a `expired` las transferencias cuyo plazo terminó: libera el cupo y no borra nada. */
    public function vencer(?CarbonImmutable $ahora = null): int
    {
        $ahora ??= CarbonImmutable::now('UTC');

        $vencidos = PedidoModelo::query()->where('payment_status', 'pending_validation')->where('status', 'created')
            ->where('transfer_deadline_at', '<=', $ahora)->get();
        foreach ($vencidos as $pedido) {
            $pedido->update(['status' => 'expired', 'expired_at' => $ahora]);
            $this->avisar($pedido, NotificarPedido::VENCIDO);
        }

        // Pedidos con tarjeta sin pagar cuyo cupo apartado (15 min) terminó: se liberan sin avisar al comprador
        $tarjetas = PedidoModelo::query()->where('payment_method', 'card')->where('payment_status', 'unpaid')->where('status', 'created')
            ->where('card_reserved_until', '<=', $ahora)->update(['status' => 'expired', 'expired_at' => $ahora]);

        return $vencidos->count() + $tarjetas;
    }

    /**
     * «Marcar pagado». Un pedido vencido que sí pagó a tiempo conserva su ciclo (hasta 10; más pide confirmación).
     * «Pagado fuera de plazo» (`fueraDePlazo`) le asigna el siguiente ciclo disponible y actualiza la ventana.
     */
    public function marcarPagado(PedidoModelo $p, bool $fueraDePlazo = false, bool $confirmarSobrecupo = false, ?CarbonImmutable $ahora = null): PedidoModelo
    {
        $ahora ??= CarbonImmutable::now('UTC');
        if ($p->payment_status === 'paid') {
            throw new PedidoRechazado('already_paid', 'Este pedido ya está pagado.', 409);
        }
        if ($p->status === 'cancelled') {
            throw new PedidoRechazado('order_cancelled', 'Este pedido está cancelado.', 409);
        }

        return DB::transaction(function () use ($p, $fueraDePlazo, $confirmarSobrecupo, $ahora) {
            $cambios = ['payment_status' => 'paid', 'status' => 'ready_for_production', 'paid_at' => $ahora, 'expired_at' => null];

            if ($fueraDePlazo) {
                $ciclo = $this->motor->ciclo($ahora)['cycle_date'];
                for ($i = 0; $i < 60; $i++) {
                    $this->capacidad->bloquear($ciclo);
                    if ($this->capacidad->hayCupo($ciclo)) {
                        break;
                    }
                    $ciclo = $this->capacidad->siguienteDiaDeProduccion($ciclo);
                }
                $despacho = $this->motor->despacho($ciclo);
                $extra = $p->shipping_zone === 'B' ? 1 : 0;
                $ventana = $this->motor->ventana($despacho, $extra);
                $cambios += ['cycle_date' => $ciclo, 'dispatch_date' => $despacho, 'window_start' => $ventana['start'], 'window_end' => $ventana['end'], 'window_type' => 'immediate'];
            } else {
                $this->capacidad->bloquear($p->cycle_date->format('Y-m-d'));
                $enElCiclo = $this->capacidad->comprometidos($p->cycle_date->format('Y-m-d'), $ahora);
                // Si el pedido ya estaba contando (en plazo) no se suma otra vez.
                $yaCuenta = $p->status === 'created' && $p->transfer_deadline_at > $ahora;
                $limite = (int) $this->ajustes->obtener('cycle_tolerance', 10);
                if (! $yaCuenta && $enElCiclo >= $limite && ! $confirmarSobrecupo) {
                    throw new PedidoRechazado('capacity_over_limit', "El ciclo ya tiene {$enElCiclo} pedidos (límite {$limite}). Confirma para superarlo.", 409, ['committed' => $enElCiclo, 'limit' => $limite]);
                }
            }
            $p->update($cambios);
            $this->avisar($p, NotificarPedido::PAGADO);

            return $p->refresh();
        });
    }

    /** Con `$notificar` Josué avisa al comprador por correo, con un motivo breve. */
    public function cancelar(PedidoModelo $p, bool $notificar = false, string $motivo = ''): PedidoModelo
    {
        if ($p->status === 'cancelled') {
            throw new PedidoRechazado('order_cancelled', 'Este pedido ya está cancelado.', 409);
        }
        if ($p->status === 'shipped') {
            throw new PedidoRechazado('already_shipped', 'Este pedido ya fue enviado: no se puede cancelar desde aquí.', 409);
        }
        $p->update(['status' => 'cancelled']);
        if ($notificar) {
            $this->avisar($p, NotificarPedido::CANCELADO, ['reason' => $motivo]);
        }

        return $p->refresh();
    }

    /** «En producción»: solo un pedido pagado que todavía no salió. */
    public function marcarEnProduccion(PedidoModelo $p, ?CarbonImmutable $ahora = null): PedidoModelo
    {
        $this->exigirPagadoSinCancelar($p);
        if ($p->status === 'shipped') {
            throw new PedidoRechazado('already_shipped', 'Este pedido ya fue enviado.', 409);
        }
        if ($p->status !== 'in_production') {
            $p->update(['status' => 'in_production', 'production_started_at' => $ahora ?? CarbonImmutable::now('UTC')]);
        }

        return $p->refresh();
    }

    /**
     * «Enviado» con la guía de Servientrega (obligatoria: es lo que el comprador ve para seguir su envío).
     * Solo sale un pedido que ya está en producción.
     * Si ya estaba enviado, volver a llamarlo corrige la guía.
     */
    public function marcarEnviado(PedidoModelo $p, string $guia, ?CarbonImmutable $ahora = null): PedidoModelo
    {
        $this->exigirPagadoSinCancelar($p);
        if (! in_array($p->status, ['in_production', 'shipped'], true)) {
            throw new PedidoRechazado('not_in_production', 'Primero pasa el pedido a producción.', 409);
        }
        $guia = trim($guia);
        if (preg_match('/^[A-Za-z0-9-]{5,40}$/', $guia) !== 1) {
            throw new ValidacionFallida(['tracking_number' => ['Escribe la guía de Servientrega (solo letras, números y guiones, de 5 a 40 caracteres).']]);
        }
        $ahora ??= CarbonImmutable::now('UTC');
        $p->update([
            'status' => 'shipped', 'tracking_number' => $guia,
            'production_started_at' => $p->production_started_at ?? $ahora,
            'shipped_at' => $p->shipped_at ?? $ahora,
        ]);
        $this->avisar($p, NotificarPedido::ENVIADO); // una sola vez: corregir la guía después no vuelve a enviarlo

        return $p->refresh();
    }

    private function exigirPagadoSinCancelar(PedidoModelo $p): void
    {
        if ($p->status === 'cancelled') {
            throw new PedidoRechazado('order_cancelled', 'Este pedido está cancelado.', 409);
        }
        if ($p->payment_status !== 'paid') {
            throw new PedidoRechazado('not_paid', 'Primero marca el pedido como pagado.', 409);
        }
    }

    private function exigirPendienteVigente(PedidoModelo $p, CarbonImmutable $ahora): void
    {
        if ($p->payment_status === 'paid') {
            throw new PedidoRechazado('already_paid', 'Este pedido ya está pagado.', 409);
        }
        if ($p->status === 'cancelled') {
            throw new PedidoRechazado('order_cancelled', 'Este pedido está cancelado.', 409);
        }
        if ($p->status === 'expired' || $p->transfer_deadline_at <= $ahora) {
            throw new PedidoRechazado('order_expired', 'El plazo de este pedido terminó. Escríbenos por WhatsApp si ya pagaste.', 409);
        }
    }
}
