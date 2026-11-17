<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Infraestructura\Consola;

use App\Core\Pedidos\Aplicacion\CasosDeUso\GestionarPedido;
use Illuminate\Console\Command;

final class VencerPedidos extends Command
{
    protected $signature = 'orders:expire';

    protected $description = 'Pasa a «vencido» las transferencias cuyo plazo de 12 horas terminó y libera su cupo';

    public function handle(GestionarPedido $pedidos): int
    {
        $this->info('Pedidos vencidos: '.$pedidos->vencer());

        return self::SUCCESS;
    }
}
