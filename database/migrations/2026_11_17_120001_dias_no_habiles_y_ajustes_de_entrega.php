<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pasos 8 y 9: días en que no se produce, no se despacha o no se entrega (feriados y cierres propios).
 * Los domingos no se producen, despachan ni entregan nunca: eso es regla fija, no una fila. Los feriados nacionales
 * y locales los carga Josué desde el panel; aquí solo van los que ya se conocían (Navidad y Año Nuevo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('non_working_days', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('type', 30); // national_holiday, local_holiday, closure
            $table->boolean('affects_production')->default(true);
            $table->boolean('affects_dispatch')->default(true);
            $table->boolean('affects_delivery')->default(true);
            $table->string('note', 150)->nullable();
            $table->timestamps();
        });

        $ahora = now();
        foreach ([['2026-12-25', 'Navidad'], ['2027-01-01', 'Año Nuevo']] as [$fecha, $nota]) {
            DB::table('non_working_days')->insert([
                'date' => $fecha, 'type' => 'national_holiday', 'affects_production' => true, 'affects_dispatch' => true,
                'affects_delivery' => true, 'note' => $nota, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }

        foreach ([
            ['cutoff_weekday', '"20:00"', 'Cierre del ciclo de producción de lunes a viernes (hora de Guayaquil)'],
            ['cutoff_saturday', '"16:00"', 'Cierre del ciclo de producción del sábado (hora de Guayaquil)'],
            ['grace_minutes', '30', 'Minutos de gracia después del corte para quien ya empezó a personalizar'],
        ] as [$clave, $valor, $descripcion]) {
            DB::table('settings')->insertOrIgnore(['key' => $clave, 'value' => $valor, 'description' => $descripcion, 'created_at' => $ahora, 'updated_at' => $ahora]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('non_working_days');
        DB::table('settings')->where('key', 'grace_minutes')->delete();
    }
};
