<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paso 17: historial del pedido. Por ahora registra cada correo enviado; la clave de idempotencia
 * («correo:{pedido}:{motivo}») es única, así un reintento o un doble clic nunca envía el mismo correo dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('type', 30);                    // email
            $table->string('reason', 30);                  // received | paid | shipped | expired | cancelled
            $table->string('idempotency_key', 80)->unique();
            $table->json('data')->nullable();              // destinatario y asunto (sin datos de pago)
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
    }
};
