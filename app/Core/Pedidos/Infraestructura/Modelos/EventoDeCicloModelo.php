<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Model;

class EventoDeCicloModelo extends Model
{
    protected $table = 'cycle_events';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cycle_date' => 'date:Y-m-d', 'data' => 'array', 'created_at' => 'datetime'];
    }
}
