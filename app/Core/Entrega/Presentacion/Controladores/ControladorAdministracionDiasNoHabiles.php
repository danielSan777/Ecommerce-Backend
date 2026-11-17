<?php

declare(strict_types=1);

namespace App\Core\Entrega\Presentacion\Controladores;

use App\Core\Entrega\Infraestructura\Modelos\DiaNoHabilModelo;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class ControladorAdministracionDiasNoHabiles extends Controller
{
    private const MENSAJES = [
        'date.required' => 'Elige la fecha.',
        'date.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
        'type.required' => 'Elige el tipo de día.',
        'type.in' => 'El tipo de día no es válido.',
        'note.max' => 'La nota no puede pasar de 150 caracteres.',
    ];

    private function fila(DiaNoHabilModelo $d): array
    {
        return ['date' => $d->date->format('Y-m-d'), 'type' => $d->type, 'affects_production' => $d->affects_production,
            'affects_dispatch' => $d->affects_dispatch, 'affects_delivery' => $d->affects_delivery, 'note' => $d->note];
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => DiaNoHabilModelo::query()->orderBy('date')->get()->map(fn ($d) => $this->fila($d))->all()]);
    }

    /** Crea o actualiza el día (una fila por fecha). */
    public function store(Request $request): JsonResponse
    {
        $datos = ValidacionFallida::validar($request->all(), [
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(['national_holiday', 'local_holiday', 'closure'])],
            'affects_production' => ['sometimes', 'boolean'],
            'affects_dispatch' => ['sometimes', 'boolean'],
            'affects_delivery' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:150'],
        ], self::MENSAJES);

        $dia = DiaNoHabilModelo::query()->updateOrCreate(['date' => $datos['date']], $datos);

        return response()->json($this->fila($dia->refresh()), 201);
    }

    public function destroy(string $fecha): Response
    {
        DiaNoHabilModelo::query()->where('date', $fecha)->firstOrFail()->delete();

        return response()->noContent();
    }
}
