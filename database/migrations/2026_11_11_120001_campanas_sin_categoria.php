<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Una campaña ya no pertenece a ninguna categoría: solo tiene los productos que elige el administrador. */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('related_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('related_category_id')->nullable()->after('name')->constrained('categories');
        });
    }
};
