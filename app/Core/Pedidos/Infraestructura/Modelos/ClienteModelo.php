<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Model;

class ClienteModelo extends Model
{
    protected $table = 'customers';

    protected $guarded = [];
}
