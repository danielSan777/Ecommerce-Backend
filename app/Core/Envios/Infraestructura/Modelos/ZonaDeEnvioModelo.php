<?php

declare(strict_types=1);

namespace App\Core\Envios\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ZonaDeEnvioModelo extends Model
{
    protected $table = 'shipping_zones';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'extra_days' => 'integer', 'is_available' => 'boolean'];
    }

    public function cantones(): HasMany
    {
        return $this->hasMany(CantonModelo::class, 'shipping_zone_id');
    }
}
