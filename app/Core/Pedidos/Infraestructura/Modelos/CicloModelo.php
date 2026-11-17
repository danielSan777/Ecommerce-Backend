<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Model;

class CicloModelo extends Model
{
    protected $table = 'cycles';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'closed_manually' => 'boolean'];
    }
}
