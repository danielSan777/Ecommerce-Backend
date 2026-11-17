<?php

declare(strict_types=1);

namespace App\Core\Envios\Aplicacion\CasosDeUso;

use App\Core\Envios\Infraestructura\Modelos\ProvinciaModelo;
use App\Core\Envios\Infraestructura\Modelos\ZonaDeEnvioModelo;

/** Todo lo que el checkout necesita en una sola respuesta: provincias, cantones (con su zona) y tarifas por zona. */
final class ConsultarUbicaciones
{
    /** @return array{zones: list<array<string, mixed>>, provinces: list<array<string, mixed>>} */
    public function __invoke(): array
    {
        $zonas = ZonaDeEnvioModelo::query()->orderBy('id')->get();
        $codigoDeZona = $zonas->pluck('code', 'id');

        $provincias = ProvinciaModelo::query()->orderBy('name')
            ->with(['cantones' => fn ($q) => $q->orderBy('is_locality')->orderBy('name')])
            ->get()
            ->map(fn (ProvinciaModelo $p) => [
                'code' => $p->code,
                'name' => $p->name,
                'cantons' => $p->cantones->map(fn ($c) => [
                    'code' => $c->code,
                    'name' => $c->name,
                    'zone' => $codigoDeZona[$c->shipping_zone_id],
                    'is_locality' => $c->is_locality,
                ])->values()->all(),
            ])->values()->all();

        return ['zones' => $zonas->map(fn ($z) => self::zona($z))->all(), 'provinces' => $provincias];
    }

    /** @return array<string, mixed> */
    public static function zona(ZonaDeEnvioModelo $z): array
    {
        return ['code' => $z->code, 'name' => $z->name, 'price_cents' => $z->price_cents, 'extra_days' => $z->extra_days, 'is_available' => $z->is_available];
    }
}
