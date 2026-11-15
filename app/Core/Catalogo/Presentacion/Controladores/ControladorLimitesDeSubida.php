<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Presentacion\ArchivoSubido;
use App\Core\Configurador\Infraestructura\Servicios\ProcesadorDeAccesorios;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Lo que pesa como máximo un archivo en este servidor, para que el panel avise al elegirlo, sin esperar a guardar. */
final class ControladorLimitesDeSubida extends Controller
{
    public function __invoke(): JsonResponse
    {
        $servidor = ArchivoSubido::limiteEnBytes();

        return response()->json([
            // Peso máximo real: el menor entre lo que deja PHP y lo que acepta la aplicación
            'max_file_bytes' => $servidor > 0 ? min($servidor, ProcesadorDeAccesorios::MAX_BYTES) : ProcesadorDeAccesorios::MAX_BYTES,
            'min_image_side' => ProcesadorDeAccesorios::LADO_MINIMO,
            'max_image_pixels' => ProcesadorDeAccesorios::MAX_PIXELES,
        ]);
    }
}
