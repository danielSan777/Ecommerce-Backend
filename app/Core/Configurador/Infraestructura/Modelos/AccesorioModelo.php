<?php

declare(strict_types=1);

namespace App\Core\Configurador\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['accessory_category_id', 'name', 'type', 'default_text', 'image_variants', 'alt', 'sort_order', 'is_active'])]
class AccesorioModelo extends Model
{
    protected $table = 'accessories';

    protected function casts(): array
    {
        return ['image_variants' => 'array', 'is_active' => 'boolean'];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaDeAccesorioModelo::class, 'accessory_category_id');
    }
}
