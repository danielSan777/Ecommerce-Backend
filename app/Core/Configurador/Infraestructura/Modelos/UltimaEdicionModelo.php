<?php

declare(strict_types=1);

namespace App\Core\Configurador\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Categoría y producto que un administrador estaba configurando (una fila por administrador). */
#[Fillable(['user_id', 'category_id', 'product_id'])]
class UltimaEdicionModelo extends Model
{
    protected $table = 'configurator_last_edits';
}
