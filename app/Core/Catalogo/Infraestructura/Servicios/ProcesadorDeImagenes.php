<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Infraestructura\Servicios;

use App\Core\Catalogo\Dominio\Excepciones\ImagenInvalida;

/**
 * Valida una imagen subida por su contenido real (no por la extensión ni por lo que dice el navegador) y genera
 * las versiones WebP. Nunca confía en el nombre del archivo.
 */
final class ProcesadorDeImagenes
{
    public const ANCHOS = [600, 1080];

    private const TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const MAX_BYTES = 8 * 1024 * 1024;

    private const MAX_PIXELES = 40_000_000; // evita imágenes que al abrirse consumen toda la memoria

    /**
     * @return array{extension: string, ancho: int, alto: int, versiones: array<int, string>} versiones: ancho => contenido WebP
     *
     * @throws ImagenInvalida
     */
    public function procesar(string $contenido): array
    {
        // PHP necesita la extensión GD con soporte WebP; si falta, se explica en lugar de fallar con un error genérico
        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            throw new ImagenInvalida('Este servidor no tiene activada la extensión GD de PHP con soporte WebP. Actívala en php.ini (extension=gd) y reinicia el servidor.');
        }

        if ($contenido === '' || strlen($contenido) > self::MAX_BYTES) {
            throw new ImagenInvalida('La imagen debe pesar menos de 8 MB.');
        }

        $info = @getimagesizefromstring($contenido);
        $mime = $info['mime'] ?? null;
        if ($info === false || ! isset(self::TIPOS[$mime])) {
            throw new ImagenInvalida('El archivo no es una imagen válida. Usa JPG, PNG o WebP.');
        }
        [$ancho, $alto] = $info;
        if ($ancho < 1 || $alto < 1 || $ancho * $alto > self::MAX_PIXELES) {
            throw new ImagenInvalida('La imagen es demasiado grande en píxeles. Redúcela a menos de 40 megapíxeles.');
        }
        if ($ancho < 300 || $alto < 300) {
            throw new ImagenInvalida('La imagen es muy pequeña. Debe medir al menos 300 × 300 píxeles.');
        }

        $imagen = @imagecreatefromstring($contenido);
        if ($imagen === false) {
            throw new ImagenInvalida('No pudimos leer la imagen. Prueba guardándola de nuevo o con otro programa.');
        }

        try {
            $imagen = $this->enderezar($imagen, $mime, $contenido);
            $ancho = imagesx($imagen);
            $alto = imagesy($imagen);

            $versiones = [];
            foreach (self::ANCHOS as $objetivo) {
                $versiones[$objetivo] = $this->aWebp($imagen, $ancho, $alto, min($objetivo, $ancho));
            }
        } finally {
            imagedestroy($imagen);
        }

        return ['extension' => self::TIPOS[$mime], 'ancho' => $ancho, 'alto' => $alto, 'versiones' => $versiones];
    }

    /** Las fotos de celular traen la orientación en los datos EXIF: se aplica y así no salen giradas. */
    private function enderezar(\GdImage $imagen, string $mime, string $contenido): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $imagen;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($contenido));
        $angulo = match ($exif['Orientation'] ?? 1) {
            3 => 180, 6 => -90, 8 => 90, default => 0,
        };
        if ($angulo === 0) {
            return $imagen;
        }
        $girada = imagerotate($imagen, $angulo, 0);

        return $girada === false ? $imagen : $girada;
    }

    public function aWebp(\GdImage $origen, int $ancho, int $alto, int $anchoFinal): string
    {
        $altoFinal = max(1, (int) round($alto * $anchoFinal / $ancho));
        $copia = imagecreatetruecolor($anchoFinal, $altoFinal);
        imagealphablending($copia, false);
        imagesavealpha($copia, true);
        imagefill($copia, 0, 0, imagecolorallocatealpha($copia, 0, 0, 0, 127));
        imagecopyresampled($copia, $origen, 0, 0, 0, 0, $anchoFinal, $altoFinal, $ancho, $alto);

        ob_start();
        imagewebp($copia, null, 82);
        $webp = (string) ob_get_clean();
        imagedestroy($copia);

        return $webp;
    }
}
