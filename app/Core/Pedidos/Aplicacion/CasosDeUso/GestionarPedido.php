<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Aplicacion\CasosDeUso;

use App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion;
use App\Core\Entrega\Dominio\MotorDeEntrega;
use App\Core\Pedidos\Dominio\PedidoRechazado;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
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
    ) {}

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

        return PedidoModelo::query()->where('payment_status', 'pending_validation')->where('status', 'created')
            ->where('transfer_deadline_at', '<=', $ahora)
            ->update(['status' => 'expired', 'expired_at' => $ahora]);
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

            return $p->refresh();
        });
    }

    public function cancelar(PedidoModelo $p): PedidoModelo
    {
        if ($p->status === 'cancelled') {
            throw new PedidoRechazado('order_cancelled', 'Este pedido ya está cancelado.', 409);
        }
        $p->update(['status' => 'cancelled']);

        return $p->refresh();
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
