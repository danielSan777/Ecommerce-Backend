<?php

declare(strict_types=1);

namespace App\Core\Configuracion\Presentacion\Controladores;

use App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion;
use App\Core\Envios\Infraestructura\Modelos\ZonaDeEnvioModelo;
use App\Core\Pedidos\Aplicacion\CalculadoraDeTotales;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Modo de venta vigente: lo que Angular muestra (público) y el interruptor del panel (admin). */
final class ControladorModoDeVenta extends Controller
{
    /** Valores efectivos del modo vigente: envío, recargo y descuento que el cliente verá. */
    public function precios(CalculadoraDeTotales $calculadora): JsonResponse
    {
        $disponibles = ZonaDeEnvioModelo::query()->where('is_available', true)->get();
        $todasGratis = $disponibles->isNotEmpty() && $disponibles->every(fn ($z) => $z->price_cents === 0);

        return response()->json($calculadora->parametros($todasGratis));
    }

    public function mostrar(CalculadoraDeTotales $calculadora, RepositorioConfiguracion $ajustes): JsonResponse
    {
        return response()->json($this->actual($calculadora, $ajustes));
    }

    public function guardar(Request $request, CalculadoraDeTotales $calculadora, RepositorioConfiguracion $ajustes): JsonResponse
    {
        $datos = ValidacionFallida::validar($request->all(), [
            'mode' => ['required', 'string', 'in:'.CalculadoraDeTotales::ESTANDAR.','.CalculadoraDeTotales::TODO_GRATIS],
        ], ['mode.required' => 'Elige el modo de venta.', 'mode.in' => 'El modo de venta elegido no existe.']);

        $ajustes->guardar('sales_mode', $datos['mode']);

        return response()->json($this->actual($calculadora, $ajustes));
    }

    /** @return array{mode: string, discount_percent: float, card_surcharge_percent: float, free_shipping_min_planters: int} */
    private function actual(CalculadoraDeTotales $calculadora, RepositorioConfiguracion $ajustes): array
    {
        return [
            'mode' => $calculadora->modo(),
            // Valores propios de cada modo (no los efectivos), para que el panel los explique
            'discount_percent' => (float) $ajustes->obtener('second_planter_discount_percent', 30),
            'card_surcharge_percent' => (float) $ajustes->obtener('card_surcharge_percent', 6),
            'free_shipping_min_planters' => (int) $ajustes->obtener('free_shipping_min_planters', 2),
        ];
    }
}
