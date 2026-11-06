<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada foto o video pertenece a un producto o a una categoría, con llave foránea real (en lugar del campo genérico
 * `mediable_*`, que MySQL no puede proteger). Si se borra el producto o la categoría, se borran sus registros de medios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropMorphs('mediable');
        });

        Schema::table('media', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->after('product_id')->constrained('categories')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::table('media', function (Blueprint $table) {
            $table->nullableMorphs('mediable');
        });
    }
};
