<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Entrega las fotos públicas de /media por la API, que sí envía la cabecera CORS.
 * Sirve para que el personalizador pueda exportar la foto del carrito aunque el servidor
 * que entrega /media directamente (artisan serve, Apache sin mod_headers...) no la envíe.
 */
final class ControladorMediaPublica extends Controller
{
    private const EXTENSIONES = ['webp', 'png', 'jpg', 'jpeg', 'gif', 'svg'];

    public function __invoke(string $ruta): BinaryFileResponse
    {
        $raiz = realpath(public_path('media'));
        $archivo = $raiz === false ? false : realpath($raiz.DIRECTORY_SEPARATOR.$ruta);

        $permitido = $archivo !== false
            && str_starts_with($archivo, $raiz.DIRECTORY_SEPARATOR)
            && is_file($archivo)
            && in_array(strtolower(pathinfo($archivo, PATHINFO_EXTENSION)), self::EXTENSIONES, true);

        abort_unless($permitido, 404);

        return response()->file($archivo, ['Cache-Control' => 'public, max-age=86400']);
    }
}
