<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['category_id', 'slug', 'name', 'sort_order', 'is_active'])]
class SubcategoriaModelo extends Model
{
    protected $table = 'subcategories';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
