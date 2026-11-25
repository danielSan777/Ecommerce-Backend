<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historial de los ciclos (solo de escritura): quién cerró, reabrió o cambió la capacidad de un ciclo, y cuándo.
        Schema::create('cycle_events', function (Blueprint $table) {
            $table->id();
            $table->date('cycle_date')->index();
            $table->string('type', 30);          // closed | reopened | capacity_changed
            $table->string('actor_name', 80)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycle_events');
    }
};
