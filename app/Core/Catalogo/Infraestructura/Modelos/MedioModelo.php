<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['type', 'role', 'disk', 'variants', 'original_path', 'width', 'height', 'duration_s', 'alt', 'sort_order'])]
class MedioModelo extends Model
{
    protected $table = 'media';

    protected function casts(): array
    {
        return ['variants' => 'array'];
    }

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }
}
