<?php

declare(strict_types=1);

namespace App\Core\Configurador\Infraestructura\Servicios;

use App\Core\Catalogo\Dominio\Excepciones\ImagenInvalida;
use App\Core\Catalogo\Infraestructura\Servicios\ProcesadorDeImagenes;

/**
 * Imagen de un accesorio: se valida por su contenido y se guardan dos WebP con transparencia
 * (grande, hasta 1080 px, y miniatura de 160 px). Los accesorios pueden ser pequeños (un marco de 100 px).
 */
final class ProcesadorDeAccesorios
{
    public const GRANDE = 1080;

    public const MINIATURA = 160;

    private const TIPOS = ['image/jpeg', 'image/png', 'image/webp'];

    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly ProcesadorDeImagenes $imagenes) {}

    /**
     * @return array<int, string> ancho => contenido WebP
     *
     * @throws ImagenInvalida
     */
    public function procesar(string $contenido): array
    {
        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            throw new ImagenInvalida('Este servidor no tiene activada la extensión GD de PHP con soporte WebP. Actívala en php.ini (extension=gd) y reinicia el servidor.');
        }
        if ($contenido === '' || strlen($contenido) > self::MAX_BYTES) {
            throw new ImagenInvalida('La imagen debe pesar menos de 8 MB.');
        }

        $info = @getimagesizefromstring($contenido);
        if ($info === false || ! in_array($info['mime'] ?? '', self::TIPOS, true)) {
            throw new ImagenInvalida('El archivo no es una imagen válida. Usa PNG, JPG o WebP.');
        }
        [$ancho, $alto] = $info;
        if ($ancho < 64 || $alto < 64) {
            throw new ImagenInvalida('La imagen es muy pequeña. Debe medir al menos 64 × 64 píxeles.');
        }
        if ($ancho * $alto > 40_000_000) {
            throw new ImagenInvalida('La imagen es demasiado grande en píxeles. Redúcela a menos de 40 megapíxeles.');
        }

        $imagen = @imagecreatefromstring($contenido);
        if ($imagen === false) {
            throw new ImagenInvalida('No pudimos leer la imagen. Prueba guardándola de nuevo o con otro programa.');
        }

        try {
            $lado = max($ancho, $alto);
            $versiones = [];
            foreach ([self::GRANDE, self::MINIATURA] as $objetivo) {
                // El lado mayor llega a `$objetivo` como máximo; nunca se agranda.
                $escala = min(1.0, $objetivo / $lado);
                $versiones[$objetivo] = $this->imagenes->aWebp($imagen, $ancho, $alto, max(1, (int) round($ancho * $escala)));
            }
        } finally {
            imagedestroy($imagen);
        }

        return $versiones;
    }
}
