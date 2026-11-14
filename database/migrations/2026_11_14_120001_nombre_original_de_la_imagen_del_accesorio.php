<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nombre con el que el administrador subió la imagen (el archivo guardado lleva un nombre propio generado)
        Schema::table('accessories', function (Blueprint $table) {
            $table->string('image_original_name', 255)->nullable()->after('image_variants');
        });
    }

    public function down(): void
    {
        Schema::table('accessories', function (Blueprint $table) {
            $table->dropColumn('image_original_name');
        });
    }
};
