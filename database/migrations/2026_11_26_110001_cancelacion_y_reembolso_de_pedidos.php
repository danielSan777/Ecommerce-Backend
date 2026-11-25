<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('cancel_reason', 200)->nullable();        // motivo que escribió Josué al cancelar
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('refund_cents')->nullable();     // monto que Josué registró como reembolsado (el reembolso real se hace a mano)
            $table->timestamp('refunded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['cancel_reason', 'cancelled_at', 'refund_cents', 'refunded_at']);
        });
    }
};
