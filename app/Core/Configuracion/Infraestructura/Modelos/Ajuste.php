<?php

declare(strict_types=1);

namespace App\Core\Configuracion\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value', 'description'])]
class Ajuste extends Model
{
    protected $table = 'settings';

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
