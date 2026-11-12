<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajuste del encuadre de la imagen para personalizar de cada producto. Los accesorios de una categoría comparten sus
 * posiciones (porcentajes del visor); si la foto de un producto está un poco más cerca, más lejos o corrida, se
 * compensa moviendo la foto y no los accesorios. Escala en % (100 = sin cambio) y desplazamiento en % del visor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('personalization_scale', 6, 2)->default(100)->after('personalization_image');
            $table->decimal('personalization_offset_x', 6, 2)->default(0)->after('personalization_scale');
            $table->decimal('personalization_offset_y', 6, 2)->default(0)->after('personalization_offset_x');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['personalization_scale', 'personalization_offset_x', 'personalization_offset_y']);
        });
    }
};
