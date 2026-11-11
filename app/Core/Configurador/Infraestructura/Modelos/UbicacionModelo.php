<?php

declare(strict_types=1);

namespace App\Core\Configurador\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'sort_order'])]
class UbicacionModelo extends Model
{
    protected $table = 'locations';
}
