<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Porcentaje de descuento fijo del producto (0 = sin descuento). El monto en dinero lo calcula Laravel, en centavos.
            $table->decimal('discount_percent', 5, 2)->default(0)->after('cost_cents');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });
    }
};
