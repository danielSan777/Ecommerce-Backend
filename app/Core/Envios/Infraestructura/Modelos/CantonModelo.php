<?php

declare(strict_types=1);

namespace App\Core\Envios\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CantonModelo extends Model
{
    protected $table = 'cantons';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_locality' => 'boolean'];
    }

    public function provincia(): BelongsTo
    {
        return $this->belongsTo(ProvinciaModelo::class, 'province_id');
    }

    public function zona(): BelongsTo
    {
        return $this->belongsTo(ZonaDeEnvioModelo::class, 'shipping_zone_id');
    }
}
