<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Ya no existe «por producir»: los pedidos pagados que quedaban en ese estado pasan a producción. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')->where('status', 'ready_for_production')
            ->update(['status' => 'in_production', 'production_started_at' => DB::raw('COALESCE(production_started_at, paid_at)')]);
    }

    public function down(): void {}
};
