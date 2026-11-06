<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['slug', 'name', 'headline', 'intro_html', 'sort_order', 'is_active', 'seo_title', 'seo_description'])]
class CategoriaModelo extends Model
{
    use SoftDeletes;

    protected $table = 'categories';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function subcategorias(): HasMany
    {
        return $this->hasMany(SubcategoriaModelo::class, 'category_id')->orderBy('sort_order');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(ProductoModelo::class, 'category_id');
    }

    public function medios(): HasMany
    {
        return $this->hasMany(MedioModelo::class, 'category_id')->orderBy('sort_order');
    }
}
