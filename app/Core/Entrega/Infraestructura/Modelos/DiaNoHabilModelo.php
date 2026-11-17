<?php

declare(strict_types=1);

namespace App\Core\Entrega\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Model;

class DiaNoHabilModelo extends Model
{
    protected $table = 'non_working_days';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'affects_production' => 'boolean', 'affects_dispatch' => 'boolean', 'affects_delivery' => 'boolean'];
    }
}
