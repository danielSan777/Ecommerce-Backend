<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Infraestructura\Correo;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Un correo del pedido: textos fijos en la plantilla Blade, datos reales del pedido. */
final class CorreoDePedido extends Mailable
{
    /** @param  array<string, mixed>  $datos */
    public function __construct(private readonly string $asunto, private readonly array $datos) {}

    public function envelope(): Envelope
    {
        $responder = (string) config('mail.reply_to.address', '');

        return new Envelope(
            subject: $this->asunto,
            replyTo: $responder !== '' ? [new Address($responder, (string) config('mail.from.name'))] : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'correos.pedido', with: $this->datos);
    }
}
