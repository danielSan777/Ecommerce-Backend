<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('name', 150);
            $table->string('headline')->nullable();
            $table->text('intro_html')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('subcategories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('slug', 120);
            $table->string('name', 150);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['category_id', 'slug']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('sku', 60)->unique();                  // estable y nunca reutilizado
            $table->string('slug', 150)->unique();
            $table->string('type', 20)->default('personalizable'); // personalizable | complemento
            $table->string('name', 150);
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('subcategories')->restrictOnDelete();
            $table->unsignedInteger('base_price_cents');
            $table->unsignedInteger('cost_cents')->nullable();     // interno: nunca sale en la API
            $table->string('size_text')->nullable();
            $table->string('material_text')->nullable();
            $table->json('includes')->nullable();
            $table->text('description_html')->nullable();
            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            $table->json('search_synonyms')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['category_id', 'subcategory_id', 'is_active']);
        });

        // Un registro por archivo subido (rol) con todas sus variantes ya procesadas. Solo rutas relativas, nunca el archivo.
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->morphs('mediable');
            $table->string('type', 10);                            // image | video
            $table->string('role', 20);                            // front, video, angle, detail, scale, social, hero
            $table->string('disk', 20)->default('media');
            $table->json('variants');                              // {"600":"products/PRO-X/a1-600.webp","1080":"..."} o {"url":"...","poster":"..."}
            $table->string('original_path')->nullable();           // disco privado
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedSmallInteger('duration_s')->nullable();
            $table->string('alt')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
        Schema::dropIfExists('products');
        Schema::dropIfExists('subcategories');
        Schema::dropIfExists('categories');
    }
};
