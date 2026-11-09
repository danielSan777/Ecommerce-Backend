<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'category_id', 'campaign_id', 'type', 'role', 'disk', 'variants', 'original_path', 'width', 'height', 'duration_s', 'alt', 'sort_order'])]
class MedioModelo extends Model
{
    protected $table = 'media';

    protected function casts(): array
    {
        return ['variants' => 'array'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(ProductoModelo::class, 'product_id');
    }

    public function campana(): BelongsTo
    {
        return $this->belongsTo(CampanaModelo::class, 'campaign_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaModelo::class, 'category_id');
    }
}
