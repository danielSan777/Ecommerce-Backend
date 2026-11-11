<?php

declare(strict_types=1);

namespace App\Core\Configurador\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sort_order'])]
class CategoriaDeAccesorioModelo extends Model
{
    protected $table = 'accessory_categories';

    public function accesorios(): HasMany
    {
        return $this->hasMany(AccesorioModelo::class, 'accessory_category_id');
    }
}
