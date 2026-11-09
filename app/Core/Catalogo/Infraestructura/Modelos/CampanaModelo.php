<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name', 'related_category_id', 'start_md', 'end_md', 'event_md', 'tagline', 'slogan', 'theme_color', 'emoji', 'is_active', 'seo_title', 'seo_description'])]
class CampanaModelo extends Model
{
    protected $table = 'campaigns';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function categoriaRelacionada(): BelongsTo
    {
        return $this->belongsTo(CategoriaModelo::class, 'related_category_id');
    }

    public function medios(): HasMany
    {
        return $this->hasMany(MedioModelo::class, 'campaign_id')->orderBy('sort_order');
    }

    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(ProductoModelo::class, 'campaign_products', 'campaign_id', 'product_id')->orderByPivot('sort_order');
    }
}
