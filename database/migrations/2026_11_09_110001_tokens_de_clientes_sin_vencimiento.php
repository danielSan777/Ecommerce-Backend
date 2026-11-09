<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** La sesión del cliente no vence (`expires_at` null); la del administrador sí, a las 2 horas. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Los tokens sin vencimiento se vencen ya para poder volver a exigir la columna.
        DB::table('api_tokens')->whereNull('expires_at')->update(['expires_at' => now()]);

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable(false)->change();
        });
    }
};
