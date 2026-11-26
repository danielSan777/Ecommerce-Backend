<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Una búsqueda hecha en la tienda: término normalizado y cantidad de resultados. */
#[Fillable(['term', 'results_count'])]
class BusquedaRegistradaModelo extends Model
{
    protected $table = 'search_logs';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['results_count' => 'integer', 'created_at' => 'datetime'];
    }
}
