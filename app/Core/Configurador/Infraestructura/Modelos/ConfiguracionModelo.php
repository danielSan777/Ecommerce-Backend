<?php

declare(strict_types=1);

namespace App\Core\Configurador\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Configuración del configurador de una categoría de producto (`category_id`) o de un producto (`product_id`). */
#[Fillable(['category_id', 'product_id'])]
class ConfiguracionModelo extends Model
{
    protected $table = 'configurator_configs';
}
