<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Presentacion\Controladores;

use App\Core\Pedidos\Aplicacion\CasosDeUso\GestionarPedido;
use App\Core\Pedidos\Aplicacion\CasosDeUso\HojasDeCiclo;
use App\Core\Pedidos\Dominio\PedidoRechazado;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Paso 19: hoja de producción y lista de envíos de un ciclo, y «Enviado» por lote. */
final class ControladorAdministracionHojas extends Controller
{
    public function __construct(private readonly HojasDeCiclo $hojas) {}

    public function produccion(string $fecha): JsonResponse
    {
        return response()->json($this->hojas->produccion($this->fecha($fecha)));
    }

    public function envios(string $fecha): JsonResponse
    {
        return response()->json($this->hojas->envios($this->fecha($fecha)));
    }

    /**
     * Marca varios pedidos como enviados con su guía. Cada pedido se procesa por separado: los que fallan no frenan a los demás
     * y la respuesta dice cuál salió bien y cuál no, con el motivo. Cada comprador recibe su correo con su guía.
     */
    public function enviarLote(Request $request, GestionarPedido $gestionar): JsonResponse
    {
        $datos = ValidacionFallida::validar($request->all(), [
            'orders' => ['required', 'array', 'min:1', 'max:50'],
            'orders.*.number' => ['required', 'string', 'max:20', 'distinct'],
            'orders.*.tracking_number' => ['required', 'string', 'max:40'],
        ], [
            'orders.required' => 'Elige al menos un pedido.', 'orders.min' => 'Elige al menos un pedido.', 'orders.max' => 'Máximo 50 pedidos por lote.',
            'orders.*.number.distinct' => 'Hay un pedido repetido en el lote.', 'orders.*.tracking_number.required' => 'Falta la guía de un pedido.',
        ]);

        $resultados = [];
        foreach ($datos['orders'] as $linea) {
            $pedido = PedidoModelo::query()->where('number', $linea['number'])->first();
            if ($pedido === null) {
                $resultados[] = ['number' => $linea['number'], 'ok' => false, 'message' => 'No existe ese pedido.'];

                continue;
            }
            try {
                $gestionar->marcarEnviado($pedido, $linea['tracking_number']);
                $resultados[] = ['number' => $linea['number'], 'ok' => true, 'message' => null];
            } catch (PedidoRechazado $e) {
                $resultados[] = ['number' => $linea['number'], 'ok' => false, 'message' => $e->getMessage()];
            } catch (ValidacionFallida $e) {
                $resultados[] = ['number' => $linea['number'], 'ok' => false, 'message' => collect($e->errores)->flatten()->first()];
            }
        }

        return response()->json(['results' => $resultados, 'shipped' => collect($resultados)->where('ok', true)->count()]);
    }

    private function fecha(string $fecha): string
    {
        ValidacionFallida::validar(['fecha' => $fecha], ['fecha' => ['required', 'date_format:Y-m-d']], ['fecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.']);

        return $fecha;
    }
}
