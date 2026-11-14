<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Accesorio especial de una profesión (corazón del Doctor, balanza del Abogado): el cliente solo lo ve
        // como accesorio fijo del producto al que se le asignó, nunca en la lista de accesorios para elegir.
        Schema::table('accessories', function (Blueprint $table) {
            $table->boolean('is_exclusive')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('accessories', function (Blueprint $table) {
            $table->dropColumn('is_exclusive');
        });
    }
};
