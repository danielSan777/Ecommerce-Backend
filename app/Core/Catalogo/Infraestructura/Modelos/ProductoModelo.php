<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['sku', 'slug', 'type', 'name', 'category_id', 'subcategory_id', 'base_price_cents', 'cost_cents', 'discount_percent', 'size_text',
    'material_text', 'includes', 'description_html', 'is_available', 'is_active', 'uses_own_configurator', 'personalization_image', 'personalization_scale', 'personalization_offset_x', 'personalization_offset_y', 'search_synonyms', 'seo_title', 'seo_description'])]
#[Hidden(['cost_cents'])]
class ProductoModelo extends Model
{
    use SoftDeletes;

    protected $table = 'products';

    protected static function booted(): void
    {
        static::creating(function (self $producto): void {
            $producto->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['includes' => 'array', 'search_synonyms' => 'array', 'is_available' => 'boolean', 'is_active' => 'boolean', 'uses_own_configurator' => 'boolean', 'personalization_image' => 'array', 'personalization_scale' => 'float', 'personalization_offset_x' => 'float', 'personalization_offset_y' => 'float', 'discount_percent' => 'decimal:2'];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaModelo::class, 'category_id');
    }

    public function subcategoria(): BelongsTo
    {
        return $this->belongsTo(SubcategoriaModelo::class, 'subcategory_id');
    }

    public function medios(): HasMany
    {
        return $this->hasMany(MedioModelo::class, 'product_id')->orderByRaw("role = 'front' desc")->orderBy('sort_order');
    }
}
