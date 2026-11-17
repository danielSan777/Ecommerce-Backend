<?php

declare(strict_types=1);

namespace App\Core\Envios\Aplicacion\CasosDeUso;

use App\Core\Envios\Infraestructura\Modelos\CantonModelo;
use App\Core\Envios\Infraestructura\Modelos\ZonaDeEnvioModelo;
use Illuminate\Support\Facades\DB;

final class AdministrarEnvios
{
    /** @return list<array<string, mixed>> */
    public function zonas(): array
    {
        return ZonaDeEnvioModelo::query()->withCount('cantones')->orderBy('id')->get()
            ->map(fn ($z) => ConsultarUbicaciones::zona($z) + ['cantons_count' => $z->cantones_count])->all();
    }

    /** @param array{price_cents?: int, extra_days?: int, is_available?: bool, name?: string} $datos */
    public function actualizarZona(string $codigo, array $datos): array
    {
        $zona = ZonaDeEnvioModelo::query()->where('code', $codigo)->firstOrFail();
        $zona->update($datos);

        return ConsultarUbicaciones::zona($zona->refresh());
    }

    /** @return list<array<string, mixed>> */
    public function cantones(?string $provincia, ?string $zona, ?string $texto): array
    {
        return CantonModelo::query()->with(['provincia', 'zona'])
            ->when($provincia, fn ($q) => $q->whereHas('provincia', fn ($p) => $p->where('code', $provincia)))
            ->when($zona, fn ($q) => $q->whereHas('zona', fn ($z) => $z->where('code', $zona)))
            ->when($texto, fn ($q) => $q->where('name', 'like', '%'.addcslashes($texto, '%_\\').'%'))
            ->orderBy('province_id')->orderBy('is_locality')->orderBy('name')->get()
            ->map(fn ($c) => [
                'code' => $c->code, 'name' => $c->name, 'is_locality' => $c->is_locality,
                'province_code' => $c->provincia->code, 'province_name' => $c->provincia->name, 'zone' => $c->zona->code,
            ])->all();
    }

    /** @param list<string> $codigos @return int cantidad de cantones cambiados */
    public function asignarZona(string $zona, array $codigos): int
    {
        $idZona = ZonaDeEnvioModelo::query()->where('code', $zona)->firstOrFail()->id;

        return DB::transaction(fn () => CantonModelo::query()->whereIn('code', $codigos)->update(['shipping_zone_id' => $idZona]));
    }
}
