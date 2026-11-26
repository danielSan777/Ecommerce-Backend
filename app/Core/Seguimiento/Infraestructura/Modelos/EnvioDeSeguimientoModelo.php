<?php

declare(strict_types=1);

namespace App\Core\Seguimiento\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Model;

/** Registro de cada compra enviada (o no) a una plataforma de medición. */
class EnvioDeSeguimientoModelo extends Model
{
    protected $table = 'tracking_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'http_status' => 'integer', 'sent_at' => 'datetime'];
    }
}
