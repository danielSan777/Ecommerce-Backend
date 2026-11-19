<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Infraestructura\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PedidoModelo extends Model
{
    protected $table = 'orders';

    protected $guarded = [];

    protected $hidden = ['token', 'ip', 'user_agent', 'tracking'];

    protected function casts(): array
    {
        return [
            'shipping' => 'array', 'billing' => 'array', 'contact' => 'array', 'tracking' => 'array', 'grace_applied' => 'boolean',
            'window_start' => 'date:Y-m-d', 'window_end' => 'date:Y-m-d', 'cycle_date' => 'date:Y-m-d', 'dispatch_date' => 'date:Y-m-d',
            'transfer_deadline_at' => 'datetime', 'card_reserved_until' => 'datetime', 'receipt_uploaded_at' => 'datetime', 'whatsapp_clicked_at' => 'datetime',
            'paid_at' => 'datetime', 'production_started_at' => 'datetime', 'shipped_at' => 'datetime', 'expired_at' => 'datetime', 'terms_accepted_at' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(ClienteModelo::class, 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ItemDePedidoModelo::class, 'order_id')->orderBy('id');
    }
}
