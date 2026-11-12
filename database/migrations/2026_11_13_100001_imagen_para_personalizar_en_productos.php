<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada producto tiene su propia «imagen para personalizar»: la foto base sobre la que se colocan los accesorios,
 * tanto en el editor del administrador como en el personalizador del cliente. Es un archivo: en la base solo va la
 * ruta relativa de cada tamaño (mismo formato que la imagen de un accesorio).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('personalization_image')->nullable()->after('uses_own_configurator');   // {"1080":"personalization/..-1080.webp","160":"..-160.webp"}
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('personalization_image');
        });
    }
};
