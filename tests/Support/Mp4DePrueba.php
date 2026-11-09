<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/** Arma un MP4 mínimo (solo cajas ftyp y moov con mvhd, tkhd y avc1) para probar la validación sin FFmpeg. */
final class Mp4DePrueba
{
    private static function caja(string $tipo, string $datos): string
    {
        return pack('N', 8 + strlen($datos)).$tipo.$datos;
    }

    public static function bytes(float $segundos = 5.0, int $ancho = 720, int $alto = 1280, string $codec = 'avc1', int $relleno = 0, bool $conMoov = true, string $marca = 'isom'): string
    {
        $escala = 1000;
        $mvhd = self::caja('mvhd', pack('N', 0).pack('N', 0).pack('N', 0).pack('N', $escala).pack('N', (int) round($segundos * $escala)).str_repeat("\0", 80));
        $tkhd = self::caja('tkhd', pack('N', 0).str_repeat("\0", 72).pack('N', $ancho << 16).pack('N', $alto << 16));
        $tkhdAudio = self::caja('tkhd', pack('N', 0).str_repeat("\0", 72).pack('N', 0).pack('N', 0));
        $stsd = self::caja('stsd', pack('N', 0).pack('N', 1).self::caja($codec, str_repeat("\0", 78)));
        $trakVideo = self::caja('trak', $tkhd.self::caja('mdia', self::caja('minf', self::caja('stbl', $stsd))));
        $trakAudio = self::caja('trak', $tkhdAudio.self::caja('mdia', self::caja('minf', self::caja('stbl', self::caja('stsd', pack('N', 0).pack('N', 1).self::caja('mp4a', str_repeat("\0", 28)))))));
        $moov = self::caja('moov', $mvhd.$trakAudio.$trakVideo);
        $ftyp = self::caja('ftyp', $marca.pack('N', 0).$marca.$marca);

        return $ftyp.($conMoov ? $moov : '').self::caja('mdat', str_repeat('x', $relleno));
    }

    public static function archivo(float $segundos = 5.0, int $ancho = 720, int $alto = 1280, string $codec = 'avc1', int $relleno = 0, string $nombre = 'video.mp4', bool $conMoov = true, string $marca = 'isom'): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'mp4');
        file_put_contents($ruta, self::bytes($segundos, $ancho, $alto, $codec, $relleno, $conMoov, $marca));

        return new UploadedFile($ruta, $nombre, null, null, true);
    }
}
