<?php

declare(strict_types=1);

namespace App\Core\Entrega\Presentacion\Controladores;

use App\Core\Entrega\Aplicacion\CasosDeUso\ConsultarEntrega;
use App\Core\Envios\Infraestructura\Modelos\CantonModelo;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ControladorEntrega extends Controller
{
    public function promesa(Request $request, ConsultarEntrega $entrega): JsonResponse
    {
        return $this->sinCache(response()->json($entrega->promesa($this->diasAdicionales($request))));
    }

    public function ventanas(Request $request, ConsultarEntrega $entrega): JsonResponse
    {
        return $this->sinCache(response()->json($entrega->ventanas($this->diasAdicionales($request))));
    }

    /** Sin `canton` se promete la ventana base; con un cantón, la de su zona (Amazonía suma 1 día). Zona sin envío: 422. */
    private function diasAdicionales(Request $request): int
    {
        $codigo = $request->query('canton');
        if (! is_string($codigo) || $codigo === '') {
            return 0;
        }
        $zona = CantonModelo::query()->where('code', $codigo)->first()?->zona;
        if ($zona !== null && ! $zona->is_available) {
            throw new ValidacionFallida(['canton' => ['Por ahora no enviamos a esta zona desde la web.']]);
        }

        return $zona?->extra_days ?? 0;
    }

    // La promesa cambia con la hora: nunca debe quedar en la caché del navegador ni de Cloudflare.
    private function sinCache(JsonResponse $respuesta): JsonResponse
    {
        return $respuesta->header('Cache-Control', 'no-store');
    }
}
