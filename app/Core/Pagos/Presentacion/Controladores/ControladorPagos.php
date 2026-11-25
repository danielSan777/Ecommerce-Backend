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
use Illuminate\Support\Facades\Log;

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

    /**
     * Notificación Externa de PayPhone: avisa de un pago aprobado aunque el comprador haya cerrado el navegador.
     * PayPhone no firma el aviso, así que NO se confía en su contenido: solo se toma `TransactionId` y `ClientTransactionId`
     * y se llama a Confirm (la misma confirmación de la ruta de respuesta, idempotente). Responde en el formato de PayPhone.
     */
    public function notificacion(Request $request, ConfirmarPagoConTarjeta $confirmar): JsonResponse
    {
        $id = $request->input('TransactionId', $request->input('transactionId'));
        $cx = $request->input('ClientTransactionId', $request->input('clientTransactionId'));
        $tienda = $request->input('StoreId', $request->input('storeId'));

        if (! is_numeric($id) || (int) $id < 1 || ! is_string($cx) || $cx === '' || mb_strlen($cx) > 50) {
            return response()->json(['Response' => false, 'ErrorCode' => '444']);
        }
        $esperada = (string) config('payphone.store_id');
        if ($esperada !== '' && is_string($tienda) && $tienda !== '' && $tienda !== $esperada) {
            Log::warning("PayPhone notificación: StoreId {$tienda} no es el de esta tienda ({$cx})");

            return response()->json(['Response' => false, 'ErrorCode' => '666']);
        }
        if (! IntentoDePagoModelo::query()->where('client_transaction_id', $cx)->exists()) {
            Log::warning("PayPhone notificación de un pago desconocido: {$cx}");

            return response()->json(['Response' => true, 'ErrorCode' => '000']);   // nada que reintentar: no es un intento nuestro
        }

        try {
            $r = $confirmar((int) $id, $cx);
        } catch (\App\Core\Pedidos\Dominio\PedidoRechazado $e) {
            Log::warning("PayPhone notificación {$cx}: {$e->getMessage()}");

            return response()->json(['Response' => false, 'ErrorCode' => '222']);
        }

        // «error» = PayPhone no respondió a Confirm: se pide que reintenten la notificación (corre el límite de 5 minutos)
        return response()->json($r['result'] === ConfirmarPagoConTarjeta::ERROR
            ? ['Response' => false, 'ErrorCode' => '222']
            : ['Response' => true, 'ErrorCode' => '000']);
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
