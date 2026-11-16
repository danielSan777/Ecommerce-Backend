<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Color del texto de la placa (el nombre del cliente) de cada producto. Depende de la foto: el dorado claro se pierde
 * sobre una base blanca. Es un código «#RRGGBB»; null = el color por defecto del personalizador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('personalization_text_color', 7)->nullable()->after('personalization_offset_y');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('personalization_text_color');
        });
    }
};
