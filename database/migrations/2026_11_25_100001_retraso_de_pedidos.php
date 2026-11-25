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
            $table->string('delay_reason', 200)->nullable()->after('window_type');   // por qué se retrasó (lo ve el comprador en el correo)
            $table->timestamp('delayed_at')->nullable()->after('delay_reason');       // cuándo se avisó el último retraso
            $table->date('original_window_start')->nullable()->after('delayed_at');   // fecha prometida al comprar (solo se guarda la primera vez)
            $table->date('original_window_end')->nullable()->after('original_window_start');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delay_reason', 'delayed_at', 'original_window_start', 'original_window_end']);
        });
    }
};
