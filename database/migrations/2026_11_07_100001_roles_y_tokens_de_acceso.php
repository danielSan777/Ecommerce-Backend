<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hasta ahora todos los usuarios eran administradores: se conservan como tal. Los nuevos nacen como cliente.
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('customer')->after('password')->index();
        });
        DB::table('users')->update(['role' => 'admin']);

        // Los tokens ahora sirven a clientes y administradores
        Schema::rename('admin_tokens', 'api_tokens');
    }

    public function down(): void
    {
        Schema::rename('api_tokens', 'admin_tokens');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
