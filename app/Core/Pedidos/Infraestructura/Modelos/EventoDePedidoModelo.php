<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Model;

class EventoDePedidoModelo extends Model
{
    protected $table = 'order_events';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }
}
