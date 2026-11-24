<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Infraestructura\Consola;

use App\Core\Pedidos\Aplicacion\CasosDeUso\NotificarPedido;
use App\Core\Pedidos\Infraestructura\Modelos\EventoDePedidoModelo;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pedidos:reenviar-correo {numero : Número del pedido, por ejemplo JDS-1015} {motivo=received : received, paid, shipped, expired o cancelled}')]
#[Description('Reenvía un correo del pedido que no salió (por ejemplo, por un SMTP mal configurado). Libera el candado de «ya enviado» y lo manda de nuevo.')]
final class ReenviarCorreoDePedido extends Command
{
    private const MOTIVOS = [NotificarPedido::RECIBIDO, NotificarPedido::PAGADO, NotificarPedido::ENVIADO, NotificarPedido::VENCIDO, NotificarPedido::CANCELADO];

    public function handle(NotificarPedido $notificar): int
    {
        $numero = mb_strtoupper((string) $this->argument('numero'));
        $motivo = (string) $this->argument('motivo');

        if (! in_array($motivo, self::MOTIVOS, true)) {
            $this->error('Motivo no válido. Usa: '.implode(', ', self::MOTIVOS));

            return self::FAILURE;
        }
        $pedido = PedidoModelo::query()->where('number', $numero)->first();
        if ($pedido === null) {
            $this->error("No existe el pedido {$numero}.");

            return self::FAILURE;
        }

        EventoDePedidoModelo::query()->where('idempotency_key', "correo:{$pedido->id}:{$motivo}")->delete();

        if (! $notificar($pedido, $motivo)) {
            $this->error('No se pudo enviar. Revisa storage/logs/laravel.log.');

            return self::FAILURE;
        }
        $this->info("Correo «{$motivo}» del pedido {$numero} enviado a {$pedido->cliente?->email}.");

        return self::SUCCESS;
    }
}
