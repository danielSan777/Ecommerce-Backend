<?php

declare(strict_types=1);

namespace App\Core\Seguimiento\Aplicacion;

use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use App\Core\Seguimiento\Dominio\EnviadorDeCompra;
use App\Core\Seguimiento\Infraestructura\EnviadorGa4;
use App\Core\Seguimiento\Infraestructura\EnviadorMeta;
use App\Core\Seguimiento\Infraestructura\Modelos\EnvioDeSeguimientoModelo;

/**
 * Cuando un pedido se paga de verdad, deja un registro por plataforma activa y manda un trabajo a la cola para enviarlo.
 * Los pedidos de WhatsApp cargados en el panel nunca se envían: se reportan aparte como canal WhatsApp.
 */
final class ProgramarEnvioDeCompra
{
    /** @return list<EnviadorDeCompra> las plataformas que tienen sus datos puestos */
    public static function enviadores(): array
    {
        return array_values(array_filter([new EnviadorGa4(), new EnviadorMeta()], fn (EnviadorDeCompra $e) => $e->activo()));
    }

    public function __invoke(PedidoModelo $pedido): void
    {
        if ($pedido->channel !== 'web' || $pedido->payment_status !== 'paid') {
            return;
        }
        foreach (self::enviadores() as $enviador) {
            // El mismo `event_id` en cada reintento: no se duplica ni el registro ni el envío
            $envio = EnvioDeSeguimientoModelo::query()->firstOrCreate(
                ['platform' => $enviador->plataforma(), 'event_id' => $pedido->event_id],
                ['event' => 'purchase', 'order_id' => $pedido->id, 'status' => 'pending'],
            );
            if ($envio->wasRecentlyCreated) {
                EnviarCompraAPlataforma::dispatch($envio->id)->afterCommit();
            }
        }
    }
}
