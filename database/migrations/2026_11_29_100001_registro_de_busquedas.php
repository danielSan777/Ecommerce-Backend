<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una fila por búsqueda de la tienda: solo el término (en minúsculas y sin tildes) y cuántos resultados dio. Sin IP ni datos de la persona.
        Schema::create('search_logs', function (Blueprint $table) {
            $table->id();
            $table->string('term', 80);
            $table->unsignedSmallInteger('results_count');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['results_count', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_logs');
    }
};
