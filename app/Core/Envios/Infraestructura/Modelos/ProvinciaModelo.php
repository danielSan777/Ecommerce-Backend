<?php

declare(strict_types=1);

namespace App\Core\Envios\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProvinciaModelo extends Model
{
    protected $table = 'provinces';

    public $timestamps = false;

    protected $guarded = [];

    public function cantones(): HasMany
    {
        return $this->hasMany(CantonModelo::class, 'province_id');
    }
}
