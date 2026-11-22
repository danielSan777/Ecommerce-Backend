<?php

declare(strict_types=1);

namespace App\Core\Pagos\Presentacion\Controladores;

use App\Core\Pagos\Aplicacion\CasosDeUso\ConfirmarPagoConTarjeta;
use App\Core\Pagos\Aplicacion\CasosDeUso\IniciarPagoConTarjeta;
use App\Core\Pagos\Infraestructura\Modelos\IntentoDePagoModelo;
use App\Core\Pedidos\Aplicacion\CasosDeUso\ConsultarPedido;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ControladorPagos extends Controller
{
    /**
     * Datos de la Cajita para un pedido con tarjeta. Mismo intento mientras no venza, salvo `renew`:
     * PayPhone rechaza reutilizar un clientTransactionId ya mostrado, así que al abrir o recargar la pantalla se pide uno nuevo.
     */
    public function iniciar(Request $request, string $token, IniciarPagoConTarjeta $iniciar): JsonResponse
    {
        return response()->json($iniciar($token, null, $request->boolean('renew')));
    }

    /** Ruta de respuesta de PayPhone: recibe `id` y `clientTransactionId`; Laravel confirma y responde el resultado. */
    public function confirmar(Request $request, ConfirmarPagoConTarjeta $confirmar, ConsultarPedido $consultar): JsonResponse
    {
        $d = ValidacionFallida::validar($request->all(), [
            'id' => ['required', 'integer', 'min:1'],
            'client_transaction_id' => ['required', 'string', 'max:50'],
        ], ['id.required' => 'Falta el identificador del pago.', 'client_transaction_id.required' => 'Falta la referencia del pago.']);

        $r = $confirmar((int) $d['id'], $d['client_transaction_id']);
        $aprobado = $r['result'] === ConfirmarPagoConTarjeta::APROBADO;

        return response()->json([
            'result' => $r['result'],
            'message' => $r['message'],
            'simulated' => (bool) IntentoDePagoModelo::query()->where('client_transaction_id', $d['client_transaction_id'])->value('simulated'),
            'order_number' => $r['order']->number,
            // Solo un pago aprobado entrega el enlace privado del pedido y su vista
            'token' => $aprobado ? $r['order']->token : null,
            'event_id' => $aprobado ? $r['order']->event_id : null,
            'order' => $aprobado ? $consultar->vista($r['order']) : null,
        ]);
    }
}
