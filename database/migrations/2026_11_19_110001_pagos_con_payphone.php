<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Paso 13: pedido con tarjeta (cupo apartado 15 min) e intentos de pago con PayPhone. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('card_reserved_until')->nullable()->after('transfer_deadline_at');
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('client_transaction_id', 50)->unique();      // JDS-1001-1 (máximo 50 caracteres)
            $table->unsignedInteger('expected_amount_cents');
            // pending | approved | declined | amount_mismatch | duplicate | order_closed | expired
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('payphone_transaction_id')->nullable()->unique();
            $table->string('authorization_code', 40)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('message', 255)->nullable();
            $table->boolean('simulated')->default(false);
            $table->timestamp('expires_at');                              // la Cajita vence a los 10 minutos
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('card_reserved_until'));
    }
};
