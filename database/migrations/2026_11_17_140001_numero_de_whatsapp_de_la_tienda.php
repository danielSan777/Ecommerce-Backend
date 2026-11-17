<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El botón "Subir comprobante por WhatsApp" abre wa.me/{número}. Si el ajuste no existía (solo estaba en el seeder),
 * el enlace quedaba sin número y WhatsApp pedía elegir un contacto. Se crea aquí si falta; si ya existe no se toca.
 * Josué puede cambiar el número desde los ajustes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ahora = now();
        DB::table('settings')->insertOrIgnore([
            'key' => 'whatsapp_phone', 'value' => '"593999283765"',
            'description' => 'WhatsApp de la tienda (solo dígitos, con código de país)', 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
    }

    public function down(): void
    {
    }
};
