<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Aplicacion\CasosDeUso;

use App\Core\Pedidos\Infraestructura\Modelos\EventoDePedidoModelo;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Historial del pedido (`order_events`), solo de escritura: qué pasó, cuándo y quién lo hizo (sistema, comprador o administrador).
 * El autor administrador se toma de la sesión solo en las rutas del panel; en cualquier otra (pago con tarjeta, comprador) es el
 * que indica quien llama. Un fallo al registrar nunca rompe la acción que se está guardando.
 */
final class RegistrarEventoDePedido
{
    /** @param array<string, mixed> $datos */
    public function __invoke(PedidoModelo $p, string $evento, array $datos = [], string $autor = 'system'): void
    {
        try {
            $admin = str_starts_with((string) request()->route()?->getName(), 'api.v1.admin.') ? Auth::guard('api')->user() : null;
            EventoDePedidoModelo::query()->create([
                'order_id' => $p->id, 'type' => 'order', 'reason' => $evento,
                'actor' => $admin !== null ? 'admin' : $autor,
                'actor_name' => $admin !== null ? mb_substr((string) ($admin->name ?? $admin->email ?? ''), 0, 80) : null,
                'data' => $datos === [] ? null : $datos,
            ]);
        } catch (Throwable $e) {
            Log::warning("No se pudo registrar «{$evento}» en el historial del pedido {$p->number}: {$e->getMessage()}");
        }
    }
}
