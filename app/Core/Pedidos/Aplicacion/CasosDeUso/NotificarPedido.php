<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Aplicacion\CasosDeUso;

use App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion;
use App\Core\Pedidos\Infraestructura\Correo\AvisoDeCompraNueva;
use App\Core\Pedidos\Infraestructura\Correo\CorreoDePedido;
use App\Core\Pedidos\Infraestructura\Modelos\EventoDePedidoModelo;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Paso 17: correos del pedido al comprador. Cada motivo se envía una sola vez por pedido (clave de idempotencia
 * «correo:{pedido}:{motivo}» en `order_events`) y un fallo del correo nunca rompe lo que el comprador o Josué estaban haciendo.
 */
final class NotificarPedido
{
    public const RECIBIDO = 'received';

    public const PAGADO = 'paid';

    public const ENVIADO = 'shipped';

    public const VENCIDO = 'expired';

    public const CANCELADO = 'cancelled';

    /** Josué avisa un retraso y la nueva fecha de entrega (puede haber varios, uno por cada fecha nueva). */
    public const RETRASADO = 'delayed';

    private const DIAS = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    private const MESES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(private readonly RepositorioConfiguracion $ajustes) {}

    /**
     * @param  array{reason?: string, previous_window?: string}  $extra  el motivo breve que escribió Josué (cancelado y retraso) y, en el retraso, la fecha anterior ya escrita
     * @return bool true si se envió ahora; false si ya se había enviado o no se pudo enviar
     */
    public function __invoke(PedidoModelo $p, string $motivo, array $extra = []): bool
    {
        $p->loadMissing('items', 'cliente');
        $destino = (string) $p->cliente?->email;
        if ($destino === '') {
            return false;
        }
        [$asunto, $datos] = $this->armar($p, $motivo, $extra);

        try {
            $evento = EventoDePedidoModelo::query()->create([
                'order_id' => $p->id, 'type' => 'email', 'reason' => $motivo,
                // el retraso lleva las fechas en la clave: cada fecha nueva es un aviso nuevo; repetir la misma no reenvía
                'idempotency_key' => "correo:{$p->id}:{$motivo}".($motivo === self::RETRASADO ? ':'.$p->window_start->format('Ymd').'-'.$p->window_end->format('Ymd') : ''),
                'data' => ['to' => $destino, 'subject' => $asunto],
            ]);
        } catch (UniqueConstraintViolationException) {
            return false; // ya se envió (reintento o doble clic)
        }

        try {
            Mail::to($destino)->send(new CorreoDePedido($asunto, $datos, $this->buzonDeRespuesta($p, $motivo)));
        } catch (Throwable $e) {
            $evento->delete(); // no salió: que un reintento pueda enviarlo
            Log::warning("No se pudo enviar el correo «{$motivo}» del pedido {$p->number}: {$e->getMessage()}");

            return false;
        }
        $this->avisarCompraNueva($p, $motivo);

        return true;
    }

    /** A qué buzón llegan las respuestas del comprador, según el tema del correo. */
    private function buzonDeRespuesta(PedidoModelo $p, string $motivo): string
    {
        $clave = match ($motivo) {
            self::RECIBIDO, self::VENCIDO => 'pagos',
            self::ENVIADO => 'soporte',
            self::CANCELADO => $p->paid_at !== null ? 'pagos' : 'pedidos',
            default => 'pedidos',
        };

        return (string) config("mail.buzones.{$clave}");
    }

    /**
     * Avisa a pedidos@ cuando entra una compra: transferencia recién creada (por validar) o tarjeta aprobada.
     * Va con el mismo candado del correo al comprador (una sola vez por pedido) y su fallo nunca rompe el pedido.
     */
    private function avisarCompraNueva(PedidoModelo $p, string $motivo): void
    {
        $esCompraNueva = ($motivo === self::RECIBIDO) || ($motivo === self::PAGADO && $p->payment_method === 'card');
        $destino = (string) config('mail.buzones.pedidos');
        if (! $esCompraNueva || $destino === '') {
            return;
        }
        try {
            $metodo = $p->payment_method === 'card' ? 'Tarjeta (pagada)' : 'Transferencia (por validar)';
            Mail::to($destino)->send(new AvisoDeCompraNueva($p->number, $metodo, number_format($p->total_cents / 100, 2, '.', ''), $this->urlDeLaTienda().'/admin/pedidos?q='.rawurlencode($p->number)));
        } catch (Throwable $e) {
            Log::warning("No se pudo avisar la compra nueva del pedido {$p->number}: {$e->getMessage()}");
        }
    }

    /** @return array{0: string, 1: array<string, mixed>} asunto y datos de la plantilla */
    private function armar(PedidoModelo $p, string $motivo, array $extra): array
    {
        $base = [
            'numero' => $p->number,
            'productos' => $p->items->pluck('product_name')->all(),
            'total' => number_format($p->total_cents / 100, 2, '.', ''),
            'enlace' => $this->enlaceDelPedido($p),
        ];

        return match ($motivo) {
            self::RECIBIDO => ["Recibimos tu pedido {$p->number}", $base + [
                'titulo' => '¡Recibimos tu pedido!',
                'intro' => 'Para confirmarlo, transfiere el total y sube tu comprobante con el botón de abajo (o envíalo por WhatsApp).',
                'limite' => $this->etiquetaLimite(CarbonImmutable::instance($p->transfer_deadline_at)),
                'cuentas' => $this->ajustes->obtener('bank_accounts', []),
                'ventana' => $this->ventana($p),
                'boton' => 'Subir mi comprobante',
            ]],
            self::PAGADO => ["Pago confirmado · Pedido {$p->number}", $base + [
                'titulo' => '¡Pago confirmado!',
                'intro' => 'Ya confirmamos tu pago. Estamos preparando tu pedido con mucho cariño.',
                'ventana' => $this->ventana($p),
                'boton' => 'Ver el seguimiento de mi pedido',
            ]],
            self::ENVIADO => ["Tu pedido {$p->number} va en camino", $base + [
                'titulo' => '¡Tu pedido va en camino!',
                'intro' => 'Ya lo entregamos a Servientrega.',
                'ventana' => $this->ventana($p),
                'guia' => $p->tracking_number,
                'enlaceGuia' => $this->enlaceDeRastreo($p->tracking_number),
                'boton' => 'Ver el seguimiento de mi pedido',
            ]],
            self::VENCIDO => ["Tu pedido {$p->number} venció", $base + [
                'titulo' => 'Tu pedido venció',
                'intro' => 'No recibimos tu pago dentro del plazo, así que liberamos tu pedido. Si todavía lo quieres, puedes volver a hacerlo.',
                'boton' => 'Volver a la tienda',
                'enlace' => $this->urlDeLaTienda(),
            ]],
            self::CANCELADO => ["Tu pedido {$p->number} fue cancelado", $base + [
                'titulo' => 'Tu pedido fue cancelado',
                'intro' => 'Cancelamos tu pedido.',
                'motivo' => ($extra['reason'] ?? '') !== '' ? $extra['reason'] : null,
                'detalle' => $p->refund_cents ? 'Registramos el reembolso de $'.number_format($p->refund_cents / 100, 2).' por tu pago. Si necesitamos algo más, te escribiremos.' : ($p->paid_at !== null ? 'Como ya habías pagado, te escribiremos para coordinar el reembolso.' : null),
                'boton' => 'Ver mi pedido',
            ]],
            self::RETRASADO => ["Nueva fecha de entrega · Pedido {$p->number}", $base + [
                'titulo' => 'Tu pedido tiene una nueva fecha de entrega',
                'intro' => 'Tuvimos un inconveniente con tu pedido y se va a retrasar. Lamentamos las molestias: lo estamos resolviendo para que te llegue perfecto.',
                'motivo' => ($extra['reason'] ?? '') !== '' ? $extra['reason'] : null,
                'ventana' => $this->ventana($p),
                'ventanaAnterior' => $extra['previous_window'] ?? null,
                'boton' => 'Ver el seguimiento de mi pedido',
            ]],
            default => throw new \InvalidArgumentException("Motivo de correo desconocido: {$motivo}"),
        };
    }

    private function urlDeLaTienda(): string
    {
        return rtrim(explode(',', (string) config('app.frontend_url'))[0], '/');
    }

    private function enlaceDelPedido(PedidoModelo $p): string
    {
        return $this->urlDeLaTienda().'/pedido/'.$p->token;
    }

    private function enlaceDeRastreo(?string $guia): ?string
    {
        if ($guia === null) {
            return null;
        }
        $enlace = str_replace('{guia}', rawurlencode($guia), (string) $this->ajustes->obtener('tracking_url', ''));

        return str_starts_with($enlace, 'https://') ? $enlace : null;
    }

    private function etiquetaLimite(CarbonImmutable $instante): string
    {
        $l = $instante->setTimezone('America/Guayaquil');

        return sprintf('Paga antes del %s %d de %s, %s', self::DIAS[$l->dayOfWeekIso], $l->day, self::MESES[$l->month], $l->format('H:i'));
    }

    /** «martes 17 de noviembre» o «del martes 17 al miércoles 18 de noviembre». */
    public function ventana(PedidoModelo $p): string
    {
        return $this->etiquetaDeVentana($p->window_start->format('Y-m-d'), $p->window_end->format('Y-m-d'));
    }

    /** Igual que `ventana`, para dos fechas `Y-m-d` cualesquiera (por ejemplo, la fecha anterior a un retraso). */
    public function etiquetaDeVentana(string $inicio, string $fin): string
    {
        $a = CarbonImmutable::parse($inicio, 'America/Guayaquil');
        $b = CarbonImmutable::parse($fin, 'America/Guayaquil');
        $dia = fn (CarbonImmutable $d) => self::DIAS[$d->dayOfWeekIso].' '.$d->day;
        if ($a->isSameDay($b)) {
            return $dia($a).' de '.self::MESES[$a->month];
        }
        if ($a->month === $b->month) {
            return 'del '.$dia($a).' al '.$dia($b).' de '.self::MESES[$b->month];
        }

        return 'del '.$dia($a).' de '.self::MESES[$a->month].' al '.$dia($b).' de '.self::MESES[$b->month];
    }
}
