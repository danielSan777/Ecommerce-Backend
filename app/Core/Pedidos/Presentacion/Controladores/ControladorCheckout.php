<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Presentacion\Controladores;

use App\Core\Pedidos\Aplicacion\CasosDeUso\ConsultarPedido;
use App\Core\Pedidos\Aplicacion\CasosDeUso\CrearPedido;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ControladorCheckout extends Controller
{
    private const MENSAJES = [
        'items.required' => 'Tu carrito está vacío.',
        'items.min' => 'Tu carrito está vacío.',
        'items.max' => 'El carrito tiene demasiados productos.',
        'terms.accepted.accepted' => 'Debes aceptar los términos y condiciones para continuar.',
        'payment_method.in' => 'El método de pago no es válido.',
    ];

    public function store(Request $request, CrearPedido $crear, ConsultarPedido $consultar): JsonResponse
    {
        $datos = ValidacionFallida::validar($request->all(), [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.slug' => ['required', 'string', 'max:150'],
            'items.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'items.*.personalization' => ['nullable', 'array'],
            'items.*.preview_image' => ['nullable', 'string'],
            'promise_token' => ['nullable', 'string', 'max:500'],
            'expected_total_cents' => ['sometimes', 'integer', 'min:0'],
            'expected_window' => ['sometimes', 'array'],
            'expected_window.start' => ['required_with:expected_window', 'date_format:Y-m-d'],
            'expected_window.end' => ['required_with:expected_window', 'date_format:Y-m-d'],
            'shipping' => ['required', 'array'],
            'shipping.province_code' => ['required', 'string', 'max:2'],
            'shipping.canton_code' => ['required', 'string', 'max:12'],
            'billing' => ['sometimes', 'array'],
            'contact' => ['sometimes', 'array'],
            'payment_method' => ['required', Rule::in(['card', 'transfer'])],
            'window_choice' => ['sometimes', 'array'],
            'window_choice.type' => ['sometimes', Rule::in(['immediate', 'scheduled'])],
            'window_choice.start' => ['required_if:window_choice.type,scheduled', 'nullable', 'date_format:Y-m-d'],
            'terms' => ['required', 'array'],
            'terms.accepted' => ['accepted'],
            'terms.version' => ['required', 'string', 'max:20'],
            'tracking' => ['sometimes', 'nullable', 'array'],
        ], self::MENSAJES);

        // Los campos de envío/facturación se validan uno por uno dentro del caso de uso (mensajes por campo)
        $datos['shipping'] = $request->input('shipping');
        $datos['billing'] = $request->input('billing', ['same_as_shipping' => true]);
        $datos['contact'] = $request->input('contact', ['same_as_shipping' => true]);

        $pedido = $crear($datos, $request->ip(), $request->userAgent());

        return response()->json([
            'order_number' => $pedido->number,
            'token' => $pedido->token,
            'event_id' => $pedido->event_id,
            'order' => $consultar->vista($pedido),
        ], 201);
    }
}
