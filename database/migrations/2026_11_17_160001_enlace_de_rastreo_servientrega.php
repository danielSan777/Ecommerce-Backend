<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enlace de rastreo de Servientrega que ve el comprador junto a su guía. Puede llevar `{guia}`, que se reemplaza por el
 * número. Mientras no se confirme un enlace directo, apunta al sitio oficial (el comprador copia su guía allí).
 */
return new class extends Migration
{
    public function up(): void
    {
        $ahora = now();
        DB::table('settings')->insertOrIgnore([
            'key' => 'tracking_url', 'value' => '"https://www.servientrega.com.ec/"',
            'description' => 'Enlace de rastreo de Servientrega. Puede incluir {guia} para poner el número de guía en el enlace.',
            'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
    }

    public function down(): void
    {
    }
};
