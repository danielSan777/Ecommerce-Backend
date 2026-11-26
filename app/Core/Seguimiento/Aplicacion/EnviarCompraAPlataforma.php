<?php

declare(strict_types=1);

namespace App\Core\Seguimiento\Aplicacion;

use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use App\Core\Seguimiento\Dominio\EnviadorDeCompra;
use App\Core\Seguimiento\Infraestructura\Modelos\EnvioDeSeguimientoModelo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Envía una compra a una plataforma y deja anotado qué pasó. Se reintenta si la plataforma no responde. */
final class EnviarCompraAPlataforma implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> segundos de espera entre reintentos */
    public array $backoff = [60, 300];

    public function __construct(private readonly int $envioId) {}

    public function handle(): void
    {
        $envio = EnvioDeSeguimientoModelo::query()->find($this->envioId);
        if ($envio === null || in_array($envio->status, ['sent', 'skipped'], true)) {
            return;
        }
        $enviador = $this->enviadorDe($envio->platform);
        $pedido = PedidoModelo::query()->with('items')->find($envio->order_id);
        if ($enviador === null || $pedido === null) {
            $envio->update(['status' => 'skipped', 'error' => 'La plataforma ya no está activa o el pedido no existe.']);

            return;
        }
        if (($motivo = $enviador->omitirPorque($pedido)) !== null) {
            $envio->update(['status' => 'skipped', 'error' => $motivo]);

            return;
        }

        $envio->increment('attempts');
        $r = $enviador->enviar($pedido);
        $ok = $r['status'] >= 200 && $r['status'] < 300;
        $envio->update(['http_status' => $r['status'], 'response' => $r['body'] !== '' ? $r['body'] : null, 'status' => $ok ? 'sent' : 'failed', 'error' => $ok ? null : 'La plataforma respondió '.$r['status'], 'sent_at' => $ok ? now('UTC') : null]);
        if (! $ok) {
            throw new \RuntimeException('La plataforma respondió '.$r['status']);
        }
    }

    public function failed(Throwable $e): void
    {
        EnvioDeSeguimientoModelo::query()->whereKey($this->envioId)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
    }

    private function enviadorDe(string $plataforma): ?EnviadorDeCompra
    {
        foreach (ProgramarEnvioDeCompra::enviadores() as $e) {
            if ($e->plataforma() === $plataforma) {
                return $e;
            }
        }

        return null;
    }
}
