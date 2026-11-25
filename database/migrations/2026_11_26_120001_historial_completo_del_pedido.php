<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_events', function (Blueprint $table) {
            $table->string('idempotency_key', 80)->nullable()->change();   // solo los correos la usan; varios NULL no chocan en el índice único
            $table->string('actor', 10)->default('system')->after('reason');   // system | buyer | admin
            $table->string('actor_name', 80)->nullable()->after('actor');
        });
    }

    public function down(): void
    {
        Schema::table('order_events', function (Blueprint $table) {
            $table->dropColumn(['actor', 'actor_name']);
        });
    }
};
