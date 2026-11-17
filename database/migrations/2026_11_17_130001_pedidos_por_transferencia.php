<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pasos 12 y 14 (transferencia): clientes, pedidos con sus ítems y los ciclos que se bloquean para asignar capacidad
 * sin sobreventa. Dinero en centavos; instantes en UTC; fechas de negocio (ciclo, despacho, ventana) en Guayaquil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('email', 150)->unique();   // identidad del cliente: correo + número de contacto
            $table->string('name', 150);
            $table->string('phone', 20);
            $table->string('id_type', 10);
            $table->string('id_number', 13);
            $table->timestamps();
        });

        Schema::create('cycles', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedSmallInteger('capacity_override')->nullable();
            $table->boolean('closed_manually')->default(false);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 20)->nullable()->unique();   // JDS-1001, se arma con el id
            $table->string('token', 64)->unique();                // privado: es el enlace /pedido/{token}
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('channel', 15)->default('web');
            $table->string('payment_method', 15);                 // transfer | card
            $table->string('payment_status', 25);                 // pending_validation | paid ...
            $table->string('status', 25)->default('created');     // created | cancelled | expired ...
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('shipping_cents')->default(0);
            $table->unsignedInteger('card_fee_cents')->default(0);
            $table->unsignedInteger('total_cents');
            $table->string('shipping_zone', 20);
            $table->json('shipping');
            $table->json('billing');
            $table->json('contact');
            $table->string('window_type', 12);                    // immediate | scheduled
            $table->date('window_start');
            $table->date('window_end');
            $table->date('cycle_date')->index();
            $table->date('dispatch_date');
            $table->boolean('grace_applied')->default(false);
            $table->timestamp('transfer_deadline_at')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_original_name')->nullable();
            $table->timestamp('receipt_uploaded_at')->nullable();
            $table->unsignedSmallInteger('receipt_attempts')->default(0);
            $table->timestamp('whatsapp_clicked_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->string('event_id', 60);
            $table->string('terms_version', 20);
            $table->timestamp('terms_accepted_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('tracking')->nullable();
            $table->timestamps();
            $table->index(['payment_status', 'transfer_deadline_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('product_sku', 40);
            $table->string('product_slug', 150);
            $table->string('product_name', 150);
            $table->string('type', 20);                           // personalizable | complemento
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('cost_cents')->default(0);
            $table->unsignedInteger('line_total_cents');
            $table->json('personalization')->nullable();          // copia completa de lo que eligió el comprador
            $table->string('preview_path')->nullable();           // foto de la maceta ya personalizada (disco privado)
            $table->timestamps();
        });

        $ahora = now();
        $cuentas = [
            ['bank' => 'Banco Guayaquil', 'type' => 'Cuenta de ahorros', 'number' => '17488885', 'holder' => 'JOSUE DE SANTIS', 'id_number' => '0930247994'],
            ['bank' => 'Banco Pacífico', 'type' => 'Cuenta de ahorros', 'number' => '1051285363', 'holder' => 'JOSUE DE SANTIS', 'id_number' => '0930247994'],
            ['bank' => 'Banco Pichincha', 'type' => 'Cuenta de ahorros', 'number' => '2206070147', 'holder' => 'JOSUE DE SANTIS', 'id_number' => '0930247994'],
        ];
        foreach ([
            ['bank_accounts', json_encode($cuentas, JSON_UNESCAPED_UNICODE), 'Cuentas para transferencias (texto exacto de Josué)'],
            ['cycle_capacity', '8', 'Pedidos por ciclo antes de pasar al siguiente'],
            ['cycle_tolerance', '10', 'Pedidos por ciclo para pedidos con gracia o pagos validados a mano'],
            ['terms_version', '"2026-11"', 'Versión vigente de los términos y condiciones'],
            ['max_receipt_attempts', '5', 'Veces que se puede subir un comprobante por pedido'],
        ] as [$clave, $valor, $descripcion]) {
            DB::table('settings')->insertOrIgnore(['key' => $clave, 'value' => $valor, 'description' => $descripcion, 'created_at' => $ahora, 'updated_at' => $ahora]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cycles');
        Schema::dropIfExists('customers');
    }
};
