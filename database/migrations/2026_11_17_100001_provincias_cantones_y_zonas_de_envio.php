<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paso 10: provincias y cantones del Ecuador (INEC 2026) y zonas de envío, todo editable desde el panel.
 * Los datos iniciales salen de database/data/*.json (los mismos archivos que usaba Angular). Solo cantones del Ecuador:
 * todas las ventas son para el país. Un cantón sin zona se cobra como Zona B (la más cara).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // A, B, C, no_disponible
            $table->string('name', 60);
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedTinyInteger('extra_days')->default(0);
            $table->boolean('is_available')->default(true);
            $table->timestamps();
        });

        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->string('code', 2)->unique();
            $table->string('name', 80);
        });

        Schema::create('cantons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained('provinces')->cascadeOnDelete();
            $table->string('code', 12)->unique(); // INEC (4 dígitos) o «0906-01» para un sector
            $table->string('name', 100);
            $table->boolean('is_locality')->default(false); // sector que se muestra como un cantón más (La Aurora - Daule)
            $table->foreignId('shipping_zone_id')->constrained('shipping_zones');
            $table->timestamps();
            $table->index(['province_id', 'name']);
        });

        $this->cargarDatosIniciales();
    }

    public function down(): void
    {
        Schema::dropIfExists('cantons');
        Schema::dropIfExists('provinces');
        Schema::dropIfExists('shipping_zones');
    }

    private function cargarDatosIniciales(): void
    {
        $leer = fn (string $archivo): array => json_decode((string) file_get_contents(database_path('data/'.$archivo)), true, 512, JSON_THROW_ON_ERROR);
        $ahora = now();

        $zonas = [
            'A' => ['Zona A', 350, 0, true],
            'B' => ['Zona B', 625, 1, true],
            'C' => ['Zona C', 500, 0, true],
            'no_disponible' => ['No disponible', 0, 0, false],
        ];
        $idZona = [];
        foreach ($zonas as $codigo => [$nombre, $precio, $dias, $disponible]) {
            $idZona[$codigo] = DB::table('shipping_zones')->insertGetId([
                'code' => $codigo, 'name' => $nombre, 'price_cents' => $precio, 'extra_days' => $dias,
                'is_available' => $disponible, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }

        $zonaDeCanton = [];
        foreach ($leer('zonas-envio.json')['cantones'] as $c) {
            $zonaDeCanton[$c['codigo']] = $c['zona'];
        }

        $idCanton = [];
        $idProvincia = [];
        foreach ($leer('ubicaciones-inec.json')['provincias'] as $p) {
            $idProvincia[$p['codigo']] = DB::table('provinces')->insertGetId(['code' => $p['codigo'], 'name' => $p['nombre']]);
            foreach ($p['cantones'] as $c) {
                $idCanton[$c['codigo']] = DB::table('cantons')->insertGetId([
                    'province_id' => $idProvincia[$p['codigo']], 'code' => $c['codigo'], 'name' => $c['nombre'],
                    'is_locality' => false, 'shipping_zone_id' => $idZona[$zonaDeCanton[$c['codigo']] ?? 'B'],
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }
        }

        $contador = [];
        foreach ($leer('sectores-zona-a.json')['sectores'] as $s) {
            $contador[$s['canton']] = ($contador[$s['canton']] ?? 0) + 1;
            DB::table('cantons')->insert([
                'province_id' => $idProvincia[$s['provincia']], 'code' => sprintf('%s-%02d', $s['canton'], $contador[$s['canton']]),
                'name' => $s['nombre'], 'is_locality' => true, 'shipping_zone_id' => $idZona['A'],
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }
    }
};
