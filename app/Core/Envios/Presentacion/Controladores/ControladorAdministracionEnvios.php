<?php

declare(strict_types=1);

namespace App\Core\Envios\Presentacion\Controladores;

use App\Core\Envios\Aplicacion\CasosDeUso\AdministrarEnvios;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ControladorAdministracionEnvios extends Controller
{
    private const ZONAS = ['A', 'B', 'C', 'no_disponible'];

    private const MENSAJES = [
        'price_cents.integer' => 'El precio debe ser un número entero de centavos.',
        'price_cents.min' => 'El precio no puede ser negativo.',
        'price_cents.max' => 'El precio es demasiado alto.',
        'extra_days.integer' => 'Los días adicionales deben ser un número.',
        'extra_days.max' => 'Los días adicionales no pueden pasar de 30.',
        'name.max' => 'El nombre no puede pasar de 60 caracteres.',
        'zone.required' => 'Elige la zona.',
        'zone.in' => 'La zona elegida no existe.',
        'cantons.required' => 'Elige al menos un cantón.',
        'cantons.*.exists' => 'Uno de los cantones no existe.',
    ];

    public function zonas(AdministrarEnvios $envios): JsonResponse
    {
        return response()->json(['data' => $envios->zonas()]);
    }

    public function actualizarZona(string $codigo, Request $request, AdministrarEnvios $envios): JsonResponse
    {
        abort_unless(in_array($codigo, self::ZONAS, true), 404);
        $datos = ValidacionFallida::validar($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'price_cents' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'extra_days' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'is_available' => ['sometimes', 'boolean'],
        ], self::MENSAJES);

        return response()->json($envios->actualizarZona($codigo, $datos));
    }

    public function cantones(Request $request, AdministrarEnvios $envios): JsonResponse
    {
        return response()->json(['data' => $envios->cantones(
            $request->query('province') ?: null,
            $request->query('zone') ?: null,
            $request->query('q') ?: null,
        )]);
    }

    public function asignarZona(Request $request, AdministrarEnvios $envios): JsonResponse
    {
        $datos = ValidacionFallida::validar($request->all(), [
            'zone' => ['required', Rule::in(self::ZONAS)],
            'cantons' => ['required', 'array', 'min:1', 'max:500'],
            'cantons.*' => ['string', Rule::exists('cantons', 'code')],
        ], self::MENSAJES);

        return response()->json(['updated' => $envios->asignarZona($datos['zone'], array_values(array_unique($datos['cantons'])))]);
    }
}
