<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Infraestructura\Correo;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso interno a pedidos@: entró una compra. Solo número, método, total y enlace al panel (sin datos del comprador). */
final class AvisoDeCompraNueva extends Mailable
{
    public function __construct(
        private readonly string $numero,
        private readonly string $metodo,
        private readonly string $total,
        private readonly string $enlace,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Compra nueva · {$this->numero}");
    }

    public function content(): Content
    {
        return new Content(htmlString: sprintf(
            '<p><strong>Compra nueva %s</strong></p><p>Pago: %s<br>Total: $%s</p><p><a href="%s">Abrir en el panel</a></p>',
            e($this->numero), e($this->metodo), e($this->total), e($this->enlace),
        ));
    }
}
