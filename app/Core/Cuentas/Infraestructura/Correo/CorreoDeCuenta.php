<?php

declare(strict_types=1);

namespace App\Core\Cuentas\Infraestructura\Correo;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Correo de la cuenta del cliente con un enlace de un solo uso (confirmar correo o restablecer contraseña). */
final class CorreoDeCuenta extends Mailable
{
    public function __construct(private readonly string $asunto, private readonly string $titulo, private readonly string $texto, private readonly string $boton, private readonly string $enlace, private readonly string $aviso) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->asunto);
    }

    public function content(): Content
    {
        return new Content(view: 'correos.cuenta', with: ['titulo' => $this->titulo, 'texto' => $this->texto, 'boton' => $this->boton, 'enlace' => $this->enlace, 'aviso' => $this->aviso]);
    }
}
