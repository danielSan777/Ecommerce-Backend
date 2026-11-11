<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Algunos accesorios tienen un comportamiento especial en el personalizador (los lentes y las gafas cambian de color;
 * los marcos piden una foto o una frase). Se marca en el propio accesorio en lugar de depender de su número (id).
 * Cada comportamiento lo tiene como máximo un accesorio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accessories', function (Blueprint $table) {
            $table->string('behavior', 20)->nullable()->unique()->after('type');   // lentes | gafas | marco_foto | marco_frase
        });
    }

    public function down(): void
    {
        Schema::table('accessories', function (Blueprint $table) {
            $table->dropColumn('behavior');
        });
    }
};
