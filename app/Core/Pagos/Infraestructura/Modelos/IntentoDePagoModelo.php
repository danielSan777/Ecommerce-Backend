<?php

declare(strict_types=1);

namespace App\Core\Pagos\Infraestructura\Modelos;

use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntentoDePagoModelo extends Model
{
    protected $table = 'payment_attempts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'simulated' => 'boolean'];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoModelo::class, 'order_id');
    }
}
