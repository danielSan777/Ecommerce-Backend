<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Presentacion\ArchivoSubido;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Lo que pesa como máximo un archivo en este servidor, para que el panel avise al elegirlo, sin esperar a guardar. */
final class ControladorLimitesDeSubida extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['max_file_bytes' => ArchivoSubido::limiteEnBytes()]);
    }
}
