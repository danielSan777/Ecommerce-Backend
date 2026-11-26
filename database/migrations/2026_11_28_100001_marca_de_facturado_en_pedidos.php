<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('invoiced_at')->nullable()->index();   // marca «Facturado»: la factura SRI la emite Josué a mano
            $table->string('invoice_number', 30)->nullable();        // número de factura, opcional
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['invoiced_at', 'invoice_number']);
        });
    }
};
