<?php

declare(strict_types=1);

namespace App\Core\Entrega\Presentacion\Controladores;

use App\Core\Entrega\Infraestructura\Modelos\DiaNoHabilModelo;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class ControladorAdministracionDiasNoHabiles extends Controller
{
    private const MENSAJES = [
        'date.required' => 'Elige la fecha.',
        'date.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
        'date.after_or_equal' => 'No puedes bloquear un día que ya pasó.',
        'type.required' => 'Elige el tipo de día.',
        'type.in' => 'El tipo de día no es válido.',
        'note.max' => 'La nota no puede pasar de 150 caracteres.',
        'end_date.date_format' => 'La fecha final debe tener el formato AAAA-MM-DD.',
        'end_date.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
    ];

    /** Un rango se guarda día por día; más de esto casi seguro es un error de dedo. */
    private const MAXIMO_DIAS_POR_RANGO = 62;

    private function fila(DiaNoHabilModelo $d): array
    {
        return ['date' => $d->date->format('Y-m-d'), 'type' => $d->type, 'affects_production' => $d->affects_production,
            'affects_dispatch' => $d->affects_dispatch, 'affects_delivery' => $d->affects_delivery, 'note' => $d->note];
    }

    public function index(): JsonResponse
    {
        $anioSiguiente = CarbonImmutable::now('America/Guayaquil')->year + 1;

        return response()->json([
            'data' => DiaNoHabilModelo::query()->orderBy('date')->get()->map(fn ($d) => $this->fila($d))->all(),
            // Para el aviso del panel: cuántos feriados nacionales hay cargados para el año que viene
            'meta' => [
                'today' => CarbonImmutable::now('America/Guayaquil')->format('Y-m-d'),   // el formulario no deja elegir días anteriores
                'next_year' => $anioSiguiente,
                'national_holidays_next_year' => DiaNoHabilModelo::query()->where('type', 'national_holiday')
                    ->whereBetween('date', ["$anioSiguiente-01-01", "$anioSiguiente-12-31"])->count(),
            ],
        ]);
    }

    /** Crea o actualiza el día (una fila por fecha). Con `end_date` bloquea todo el rango, de `date` a `end_date`. */
    public function store(Request $request): JsonResponse
    {
        $hoy = CarbonImmutable::now('America/Guayaquil')->format('Y-m-d');
        $datos = ValidacionFallida::validar($request->all(), [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$hoy],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'type' => ['required', Rule::in(['national_holiday', 'local_holiday', 'closure'])],
            'affects_production' => ['sometimes', 'boolean'],
            'affects_dispatch' => ['sometimes', 'boolean'],
            'affects_delivery' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:150'],
        ], self::MENSAJES);

        $fin = $datos['end_date'] ?? null;
        unset($datos['end_date']);

        if ($fin === null || $fin === $datos['date']) {
            $dia = DiaNoHabilModelo::query()->updateOrCreate(['date' => $datos['date']], $datos);

            return response()->json($this->fila($dia->refresh()), 201);
        }

        $desde = CarbonImmutable::parse($datos['date']);
        $dias = (int) $desde->diffInDays(CarbonImmutable::parse($fin)) + 1;
        if ($dias > self::MAXIMO_DIAS_POR_RANGO) {
            throw new ValidacionFallida(['end_date' => ['El rango no puede pasar de '.self::MAXIMO_DIAS_POR_RANGO.' días.']]);
        }

        $creados = [];
        for ($i = 0; $i < $dias; $i++) {
            $fecha = $desde->addDays($i)->format('Y-m-d');
            $creados[] = $this->fila(DiaNoHabilModelo::query()->updateOrCreate(['date' => $fecha], ['date' => $fecha] + $datos)->refresh());
        }

        return response()->json(['data' => $creados], 201);
    }

    public function destroy(string $fecha): Response
    {
        DiaNoHabilModelo::query()->where('date', $fecha)->firstOrFail()->delete();

        return response()->noContent();
    }
}
