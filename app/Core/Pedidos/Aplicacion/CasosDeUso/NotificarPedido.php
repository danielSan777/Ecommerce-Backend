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

    private const DIAS = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    private const MESES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(private readonly RepositorioConfiguracion $ajustes) {}

    /**
     * @param  array{reason?: string}  $extra  para «cancelado»: el motivo breve que escribió Josué
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
                'idempotency_key' => "correo:{$p->id}:{$motivo}",
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
            self::CANCELADO => $p->payment_status === 'paid' ? 'pagos' : 'pedidos',
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
                'detalle' => $p->payment_status === 'paid' ? 'Como ya habías pagado, te escribiremos para coordinar el reembolso.' : null,
                'boton' => 'Ver mi pedido',
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
    private function ventana(PedidoModelo $p): string
    {
        $a = CarbonImmutable::parse($p->window_start->format('Y-m-d'), 'America/Guayaquil');
        $b = CarbonImmutable::parse($p->window_end->format('Y-m-d'), 'America/Guayaquil');
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
