<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Accesorio propio de un producto en una ubicación (ej. un corazón a la izquierda del Doctor) sobre la configuración
        // de su categoría: la posición y el tamaño los sigue definiendo la categoría.
        Schema::create('product_fixed_accessories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('accessory_id')->constrained('accessories')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_fixed_accessories');
    }
};
