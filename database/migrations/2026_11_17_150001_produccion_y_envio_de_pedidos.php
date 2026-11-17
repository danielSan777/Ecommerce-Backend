<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paso 15: «En producción» y «Enviado» (con la guía de Servientrega). El comprador ve el estado y la guía en /pedido/{token}.
 * Estados operativos de un pedido pagado: ready_for_production → in_production → shipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('production_started_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->string('tracking_number', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['production_started_at', 'shipped_at', 'tracking_number']);
        });
    }
};
