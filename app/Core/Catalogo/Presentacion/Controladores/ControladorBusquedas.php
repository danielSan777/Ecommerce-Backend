<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Infraestructura\Modelos\BusquedaRegistradaModelo;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Registro de búsquedas (Pasos 1, 19 y 20). La tienda avisa cuando la persona deja de escribir; el panel lista lo que no dio resultados.
 * Solo se guarda el término normalizado y la cantidad de resultados.
 */
final class ControladorBusquedas extends Controller
{
    /** Minúsculas, sin tildes y con espacios simples: «Médico  FISIO» y «medico fisio» son la misma búsqueda. */
    public static function normalizar(string $termino): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', Str::ascii(mb_strtolower(strip_tags($termino)))));
    }

    public function registrar(Request $request): JsonResponse
    {
        $validos = ValidacionFallida::validar($request->all(), [
            'term' => ['required', 'string', 'max:200'],
            'results_count' => ['required', 'integer', 'min:0', 'max:65535'],
        ], ['term.required' => 'Falta el término.', 'results_count.required' => 'Falta la cantidad de resultados.']);

        $termino = mb_substr(self::normalizar($validos['term']), 0, 80);
        if (mb_strlen($termino) >= 2) {
            BusquedaRegistradaModelo::query()->create(['term' => $termino, 'results_count' => (int) $validos['results_count']]);
        }

        return response()->json(['ok' => true], 201);
    }

    /** Búsquedas sin resultados de los últimos `days` días (30 por defecto, hasta 365): término, veces y última vez, de más a menos. */
    public function sinResultados(Request $request): JsonResponse
    {
        $dias = max(1, min(365, (int) $request->query('days', 30)));
        $desde = now('UTC')->subDays($dias);

        $filas = BusquedaRegistradaModelo::query()
            ->where('results_count', 0)->where('created_at', '>=', $desde)
            ->selectRaw('term, COUNT(*) as veces, MAX(created_at) as ultima')
            ->groupBy('term')->orderByDesc('veces')->orderByDesc('ultima')->limit(200)->get();

        return response()->json([
            'data' => $filas->map(fn ($f) => ['term' => $f->term, 'times' => (int) $f->veces, 'last_at' => \Illuminate\Support\Carbon::parse($f->ultima, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z')])->all(),
            'meta' => ['days' => $dias],
        ]);
    }
}
