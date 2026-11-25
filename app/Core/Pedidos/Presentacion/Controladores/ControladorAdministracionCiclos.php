<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Presentacion\Controladores;

use App\Core\Pedidos\Aplicacion\CasosDeUso\GestionarCiclos;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ControladorAdministracionCiclos extends Controller
{
    public function __construct(private readonly GestionarCiclos $ciclos) {}

    public function index(): JsonResponse
    {
        return response()->json($this->ciclos->listar());
    }

    public function cerrar(string $fecha): JsonResponse
    {
        $this->ciclos->cerrar($this->fecha($fecha));

        return response()->json($this->ciclos->listar());
    }

    public function reabrir(string $fecha): JsonResponse
    {
        $this->ciclos->reabrir($this->fecha($fecha));

        return response()->json($this->ciclos->listar());
    }

    /** `capacity: null` devuelve la capacidad nominal. */
    public function capacidad(Request $request, string $fecha): JsonResponse
    {
        $datos = ValidacionFallida::validar($request->all(), [
            'capacity' => ['present', 'nullable', 'integer', 'min:1', 'max:'.GestionarCiclos::CAPACIDAD_MAXIMA],
        ], [
            'capacity.present' => 'Escribe la capacidad del ciclo.',
            'capacity.integer' => 'La capacidad debe ser un número entero.',
            'capacity.min' => 'La capacidad mínima es 1. Para no recibir más pedidos usa «Pasar las ventas al siguiente ciclo».',
            'capacity.max' => 'La capacidad máxima es '.GestionarCiclos::CAPACIDAD_MAXIMA.'.',
        ]);
        $this->ciclos->cambiarCapacidad($this->fecha($fecha), $datos['capacity'] === null ? null : (int) $datos['capacity']);

        return response()->json($this->ciclos->listar());
    }

    private function fecha(string $fecha): string
    {
        ValidacionFallida::validar(['fecha' => $fecha], ['fecha' => ['required', 'date_format:Y-m-d']], ['fecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.']);

        return $fecha;
    }
}
