<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Presentacion\Controladores;

use App\Core\Pedidos\Aplicacion\CasosDeUso\ConsultarPedido;
use App\Core\Pedidos\Aplicacion\CasosDeUso\GestionarPedido;
use App\Core\Pedidos\Dominio\PedidoRechazado;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/** Todo se hace con el token largo del pedido (enlace privado). */
final class ControladorPedidos extends Controller
{
    public function show(string $token, ConsultarPedido $consultar): JsonResponse
    {
        $pedido = $consultar->porToken($token);

        // Privado: nunca en la caché del navegador ni de Cloudflare (Paso 16)
        return response()->json($consultar->vista($pedido))->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function comprobante(string $token, Request $request, ConsultarPedido $consultar, GestionarPedido $gestionar): JsonResponse
    {
        $pedido = $consultar->porToken($token);
        ValidacionFallida::validar($request->all(), [
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ], [
            'receipt.required' => 'Elige el comprobante.',
            'receipt.file' => 'El comprobante debe ser una imagen JPG o PNG, o un PDF.',
            'receipt.mimes' => 'El comprobante debe ser una imagen JPG o PNG, o un PDF.',
            'receipt.max' => 'El comprobante no puede pesar más de 5 MB.',
        ]);

        $pedido = $gestionar->subirComprobante($pedido, $request->file('receipt'));

        return response()->json($consultar->vista($pedido->load('items')));
    }

    /** Registra el clic y abre WhatsApp con el mensaje corto; el clic no es requisito para validar el pago. */
    public function whatsapp(string $token, ConsultarPedido $consultar, GestionarPedido $gestionar): RedirectResponse
    {
        $pedido = $consultar->porToken($token);
        $gestionar->registrarClicDeWhatsapp($pedido);

        return redirect()->away($consultar->enlaceDeWhatsapp($pedido))->header('Cache-Control', 'no-store');
    }

    /** Foto de la primera maceta personalizada: pública solo con el token largo y sin datos personales (og:image). */
    public function vistaPrevia(string $token, ConsultarPedido $consultar): Response
    {
        $item = $consultar->porToken($token)->items->first(fn ($i) => $i->type === 'personalizable' && $i->preview_path !== null);
        if ($item === null || ! Storage::disk('private')->exists($item->preview_path)) {
            throw new PedidoRechazado('not_found', 'No encontramos esa imagen.', 404);
        }

        return response(Storage::disk('private')->get($item->preview_path), 200, [
            'Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=3600', 'X-Robots-Tag' => 'noindex',
        ]);
    }
}
