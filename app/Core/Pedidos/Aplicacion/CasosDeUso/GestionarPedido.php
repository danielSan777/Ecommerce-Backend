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
            $cambios = ['payment_status' => 'paid', 'status' => 'in_production', 'paid_at' => $ahora, 'production_started_at' => $ahora, 'expired_at' => null];

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
    public function cancelar(PedidoModelo $p, bool $notificar = false, string $motivo = '', ?int $reembolsoCents = null, ?CarbonImmutable $ahora = null): PedidoModelo
    {
        $ahora ??= CarbonImmutable::now('UTC');
        if ($p->status === 'cancelled') {
            throw new PedidoRechazado('order_cancelled', 'Este pedido ya está cancelado.', 409);
        }
        if ($p->status === 'shipped') {
            throw new PedidoRechazado('already_shipped', 'Este pedido ya fue enviado: no se puede cancelar desde aquí.', 409);
        }
        if ($reembolsoCents !== null) {
            $this->validarReembolso($p, $reembolsoCents);
        }
        $p->update(['status' => 'cancelled', 'cancel_reason' => $motivo !== '' ? $motivo : null, 'cancelled_at' => $ahora]
            + ($reembolsoCents !== null ? ['payment_status' => 'refunded', 'refund_cents' => $reembolsoCents, 'refunded_at' => $ahora] : []));
        if ($notificar) {
            $this->avisar($p, NotificarPedido::CANCELADO, ['reason' => $motivo]);
        }

        return $p->refresh();
    }

    /**
     * Reembolso: Josué devuelve el dinero a mano (en el panel de PayPhone o por transferencia) y aquí solo queda registrado cuánto.
     * Para un pedido ya cancelado que se pagó y todavía no tiene reembolso registrado.
     */
    public function registrarReembolso(PedidoModelo $p, int $cents, ?CarbonImmutable $ahora = null): PedidoModelo
    {
        $ahora ??= CarbonImmutable::now('UTC');
        if ($p->status !== 'cancelled') {
            throw new PedidoRechazado('not_cancelled', 'Cancela el pedido antes de registrar el reembolso.', 409);
        }
        if ($p->payment_status === 'refunded') {
            throw new PedidoRechazado('already_refunded', 'Este pedido ya tiene un reembolso registrado.', 409);
        }
        $this->validarReembolso($p, $cents);
        $p->update(['payment_status' => 'refunded', 'refund_cents' => $cents, 'refunded_at' => $ahora]);

        return $p->refresh();
    }

    private function validarReembolso(PedidoModelo $p, int $cents): void
    {
        if ($p->payment_status !== 'paid') {
            throw new ValidacionFallida(['refund_amount' => ['Solo se puede reembolsar un pedido que ya está pagado.']]);
        }
        if ($cents < 1 || $cents > $p->total_cents) {
            throw new ValidacionFallida(['refund_amount' => ['El reembolso debe ser mayor a $0 y no pasar de $'.number_format($p->total_cents / 100, 2).'.']]);
        }
    }

    /**
     * Retraso (por ejemplo, se rompió la maceta): Josué fija la nueva fecha de entrega y el comprador recibe un correo con ella.
     * Solo un pedido pagado, sin cancelar. Se guarda la fecha prometida al comprar la primera vez, para no perder el dato.
     * Repetir el aviso con las mismas fechas no reenvía el correo; con fechas distintas, sí.
     */
    public function registrarRetraso(PedidoModelo $p, string $inicio, string $fin, string $motivo = '', ?CarbonImmutable $ahora = null): PedidoModelo
    {
        $this->exigirPagadoSinCancelar($p);
        $ahora ??= CarbonImmutable::now('UTC');
        $hoy = $ahora->setTimezone('America/Guayaquil')->format('Y-m-d');
        $errores = [];
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', $fin);
        if ($a === false || $a->format('Y-m-d') !== $inicio) {
            $errores['window_start'] = ['Elige la fecha desde la que entregas.'];
        } elseif ($inicio < $hoy) {
            $errores['window_start'] = ['La nueva fecha no puede ser anterior a hoy.'];
        }
        if ($b === false || $b->format('Y-m-d') !== $fin) {
            $errores['window_end'] = ['Elige la fecha hasta la que entregas.'];
        } elseif ($a !== false && $fin < $inicio) {
            $errores['window_end'] = ['La fecha final no puede ser anterior a la inicial.'];
        }
        if ($errores !== []) {
            throw new ValidacionFallida($errores);
        }

        $anterior = $this->correo->etiquetaDeVentana($p->window_start->format('Y-m-d'), $p->window_end->format('Y-m-d'));
        $p->update([
            'original_window_start' => $p->original_window_start ?? $p->window_start,
            'original_window_end' => $p->original_window_end ?? $p->window_end,
            'window_start' => $inicio, 'window_end' => $fin,
            'delay_reason' => $motivo !== '' ? $motivo : null, 'delayed_at' => $ahora,
        ]);
        $this->avisar($p, NotificarPedido::RETRASADO, ['reason' => $motivo, 'previous_window' => $anterior]);

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
