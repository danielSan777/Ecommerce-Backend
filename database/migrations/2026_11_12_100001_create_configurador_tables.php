<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurador de la maceta (todo sale de la base de datos):
 *  - accesorios y sus categorías (la imagen es un archivo; aquí solo rutas relativas);
 *  - ubicaciones (slots) con las categorías de accesorios que admite cada una;
 *  - configuración por categoría de producto o por producto (cuando el producto tiene la suya propia).
 * Todas las relaciones son llaves foráneas reales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessory_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('accessories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accessory_category_id')->constrained('accessory_categories')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('type', 10)->default('imagen');           // imagen | texto
            $table->string('default_text', 150)->nullable();         // solo type = texto
            $table->json('image_variants')->nullable();              // {"1080":"accessories/..-1080.webp","160":"accessories/..-160.webp"}
            $table->string('alt', 200)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Qué categorías de accesorios admite una ubicación: allow = solo estas; exclude = todas menos estas.
        Schema::create('location_category_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('accessory_category_id')->constrained('accessory_categories')->cascadeOnDelete();
            $table->string('rule', 10);                              // allow | exclude
            $table->unique(['location_id', 'accessory_category_id']);
        });

        // Una configuración pertenece a una categoría de producto o a un producto (uno de los dos).
        Schema::create('configurator_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->unique()->constrained('categories')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->unique()->constrained('products')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('configurator_config_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_id')->constrained('configurator_configs')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('state', 15)->default('libre');           // libre | fija | deshabilitada
            $table->unsignedSmallInteger('priority')->default(1);    // 1 = la primera que se propone al cliente
            $table->unsignedSmallInteger('layer')->default(1);       // z-index: mayor = más al frente
            $table->foreignId('fixed_accessory_id')->nullable()->constrained('accessories')->restrictOnDelete();
            // Valores por defecto de la ubicación (preset): % del visor y giros en grados. Sin valores = nulos.
            $table->decimal('x', 7, 3)->nullable();
            $table->decimal('y', 7, 3)->nullable();
            $table->decimal('width', 7, 3)->nullable();
            $table->decimal('height', 7, 3)->nullable();
            $table->decimal('rot_x', 6, 2)->default(0);
            $table->decimal('rot_y', 6, 2)->default(0);
            $table->decimal('rot_z', 6, 2)->default(0);
            $table->boolean('mirror')->default(false);
            $table->unique(['config_id', 'location_id']);
        });

        // Accesorios marcados (los que el cliente puede elegir con esta configuración).
        Schema::create('configurator_config_accessories', function (Blueprint $table) {
            $table->foreignId('config_id')->constrained('configurator_configs')->cascadeOnDelete();
            $table->foreignId('accessory_id')->constrained('accessories')->restrictOnDelete();
            $table->primary(['config_id', 'accessory_id']);
        });

        // Excepción: valores propios de UN accesorio en UNA ubicación.
        Schema::create('configurator_config_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_id')->constrained('configurator_configs')->cascadeOnDelete();
            $table->foreignId('accessory_id')->constrained('accessories')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->decimal('x', 7, 3);
            $table->decimal('y', 7, 3);
            $table->decimal('width', 7, 3);
            $table->decimal('height', 7, 3);
            $table->decimal('rot_x', 6, 2)->default(0);
            $table->decimal('rot_y', 6, 2)->default(0);
            $table->decimal('rot_z', 6, 2)->default(0);
            $table->boolean('mirror')->default(false);
            $table->unique(['config_id', 'accessory_id', 'location_id'], 'config_exception_unique');
        });

        // false = el producto usa la configuración de su categoría; true = tiene la suya.
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('uses_own_configurator')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('uses_own_configurator');
        });
        Schema::dropIfExists('configurator_config_exceptions');
        Schema::dropIfExists('configurator_config_accessories');
        Schema::dropIfExists('configurator_config_locations');
        Schema::dropIfExists('configurator_configs');
        Schema::dropIfExists('location_category_rules');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('accessories');
        Schema::dropIfExists('accessory_categories');
    }
};
