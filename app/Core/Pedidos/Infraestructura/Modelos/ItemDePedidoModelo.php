<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Model;

class ItemDePedidoModelo extends Model
{
    protected $table = 'order_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['personalization' => 'array'];
    }
}
