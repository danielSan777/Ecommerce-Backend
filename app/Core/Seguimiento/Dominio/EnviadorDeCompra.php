<?php

declare(strict_types=1);

namespace App\Core\Seguimiento\Dominio;

use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;

/** Una plataforma que recibe las compras reales desde el servidor. */
interface EnviadorDeCompra
{
    /** Nombre corto que se guarda en `tracking_events.platform`: ga4, meta, tiktok. */
    public function plataforma(): string;

    /** Tiene todos sus datos en el `.env`; si no, queda apagada. */
    public function activo(): bool;

    /** Motivo por el que esta compra no se envía a esta plataforma, o null si sí se envía. */
    public function omitirPorque(PedidoModelo $pedido): ?string;

    /**
     * Envía la compra. Devuelve el código HTTP y el cuerpo de la respuesta; lanza una excepción si no se pudo hablar con la plataforma.
     *
     * @return array{status: int, body: string}
     */
    public function enviar(PedidoModelo $pedido): array;
}
