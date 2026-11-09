<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diseño propio de cada campaña: color principal y emoji (columnas) y una imagen de portada
 * (la primera foto de la campaña, en la tabla `media` con llave foránea real).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('theme_color', 7)->nullable()->after('slogan');
            $table->string('emoji', 16)->nullable()->after('theme_color');
        });

        Schema::table('media', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('category_id')->constrained('campaigns')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['theme_color', 'emoji']);
        });
    }
};
