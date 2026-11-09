<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Infraestructura\Servicios;

use App\Core\Catalogo\Dominio\Excepciones\ImagenInvalida;

/**
 * Valida un video MP4 subido leyendo su contenido real (no la extensión): formato MP4, códec H.264, duración y peso según
 * el perfil. No transcodifica ni usa FFmpeg: el video se prepara antes de subir y se guarda tal cual.
 * Lee las "cajas" del MP4 (ftyp, moov, mvhd, trak, tkhd) directamente en PHP.
 */
final class ProcesadorDeVideos
{
    public const PERFIL_PRODUCTO = 'producto';

    public const PERFIL_CATEGORIA = 'categoria';

    public const PERFIL_INICIO = 'inicio';

    /**
     * Duración máxima en segundos y peso máximo en bytes por perfil. Pedido de Josué: el video de producto dura 6 s como máximo y
     * los de la página principal y de los banners de categoría, 1 minuto como máximo (por eso su peso permitido es mayor que el
     * de la tabla del Paso 6, pensada para videos de 6–10 s).
     */
    public const PERFILES = [
        self::PERFIL_PRODUCTO => ['segundos' => 6, 'bytes' => 2 * 1024 * 1024, 'nombre' => 'del producto'],
        self::PERFIL_CATEGORIA => ['segundos' => 60, 'bytes' => 15 * 1024 * 1024, 'nombre' => 'del banner de la categoría'],
        self::PERFIL_INICIO => ['segundos' => 60, 'bytes' => 20 * 1024 * 1024, 'nombre' => 'de la página principal'],
    ];

    /** Un video "de 6 s" suele medir 6,02 s: se tolera un cuarto de segundo. */
    private const TOLERANCIA_S = 0.25;

    private const MARCAS_MP4 = ['isom', 'iso2', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'M4V ', 'dash'];

    /**
     * @return array{ancho: int, alto: int, duracion: float}
     *
     * @throws ImagenInvalida
     */
    public function procesar(string $contenido, string $perfil): array
    {
        $reglas = self::PERFILES[$perfil] ?? throw new \InvalidArgumentException("Perfil de video desconocido: {$perfil}");
        $campo = 'video';

        if ($contenido === '') {
            throw new ImagenInvalida('Elige un video.', $campo);
        }
        if (strlen($contenido) > $reglas['bytes']) {
            throw new ImagenInvalida(sprintf('El video %s debe pesar menos de %s MB. Exporta uno más liviano.', $reglas['nombre'], $this->mb($reglas['bytes'])), $campo);
        }

        $cajas = $this->cajas($contenido, 0, strlen($contenido));
        $ftyp = $cajas['ftyp'] ?? null;
        if ($ftyp === null || ! $this->esMp4($contenido, $ftyp)) {
            throw new ImagenInvalida('El archivo no es un video MP4 válido. Exporta el video en formato MP4 (H.264).', $campo);
        }
        $moov = $cajas['moov'] ?? null;
        if ($moov === null) {
            throw new ImagenInvalida('No pudimos leer el video: está incompleto o dañado. Expórtalo de nuevo en MP4.', $campo);
        }

        $duracion = $this->duracion($contenido, $moov);
        if ($duracion === null || $duracion <= 0) {
            throw new ImagenInvalida('No pudimos leer la duración del video. Expórtalo de nuevo en MP4.', $campo);
        }
        if ($duracion > $reglas['segundos'] + self::TOLERANCIA_S) {
            $limite = $reglas['segundos'] >= 60 ? 'un minuto' : $reglas['segundos'].' segundos';
            throw new ImagenInvalida(sprintf('El video %s puede durar como máximo %s y el tuyo dura %s. Recórtalo y súbelo de nuevo.', $reglas['nombre'], $limite, $this->segundos($duracion)), $campo);
        }

        [$ancho, $alto, $trak] = $this->pistaDeVideo($contenido, $moov);
        if ($trak === null) {
            throw new ImagenInvalida('El archivo no tiene imagen de video. Sube un video en MP4 (H.264).', $campo);
        }
        if (! $this->esH264($contenido, $trak)) {
            throw new ImagenInvalida('El video debe estar en H.264 (el formato estándar de MP4). Expórtalo con H.264 para que se vea en todos los celulares.', $campo);
        }

        return ['ancho' => $ancho, 'alto' => $alto, 'duracion' => $duracion];
    }

    private function esMp4(string $d, array $ftyp): bool
    {
        [$ini, $fin] = $ftyp;
        if ($fin - $ini < 8) {
            return false;
        }
        $marcas = [substr($d, $ini, 4)];
        for ($p = $ini + 8; $p + 4 <= $fin; $p += 4) {
            $marcas[] = substr($d, $p, 4);
        }

        return array_intersect($marcas, self::MARCAS_MP4) !== [];
    }

    private function duracion(string $d, array $moov): ?float
    {
        $mvhd = $this->cajas($d, $moov[0], $moov[1])['mvhd'] ?? null;
        if ($mvhd === null || $mvhd[1] - $mvhd[0] < 20) {
            return null;
        }
        $v = ord($d[$mvhd[0]]);
        if ($v === 1) {
            if ($mvhd[1] - $mvhd[0] < 32) {
                return null;
            }
            $escala = $this->u32($d, $mvhd[0] + 20);
            $dur = $this->u64($d, $mvhd[0] + 24);
        } else {
            $escala = $this->u32($d, $mvhd[0] + 12);
            $dur = $this->u32($d, $mvhd[0] + 16);
        }

        return $escala > 0 ? $dur / $escala : null;
    }

    /** @return array{0: int, 1: int, 2: ?array{0: int, 1: int}} ancho, alto y la caja `trak` de la pista de imagen */
    private function pistaDeVideo(string $d, array $moov): array
    {
        foreach ($this->todas($d, $moov[0], $moov[1], 'trak') as $trak) {
            $tkhd = $this->cajas($d, $trak[0], $trak[1])['tkhd'] ?? null;
            if ($tkhd === null) {
                continue;
            }
            $v = ord($d[$tkhd[0]]);
            $pos = $tkhd[0] + ($v === 1 ? 88 : 76);
            if ($pos + 8 > $tkhd[1]) {
                continue;
            }
            $ancho = $this->u32($d, $pos) >> 16;
            $alto = $this->u32($d, $pos + 4) >> 16;
            if ($ancho > 0 && $alto > 0) { // la pista de audio tiene 0 × 0
                return [$ancho, $alto, $trak];
            }
        }

        return [0, 0, null];
    }

    private function esH264(string $d, array $trak): bool
    {
        $texto = substr($d, $trak[0], $trak[1] - $trak[0]);

        return str_contains($texto, 'avc1') || str_contains($texto, 'avc3');
    }

    /** Primera caja de cada tipo dentro del rango. @return array<string, array{0: int, 1: int}> tipo => [inicio de datos, fin] */
    private function cajas(string $d, int $desde, int $hasta): array
    {
        $r = [];
        foreach ($this->recorrer($d, $desde, $hasta) as [$tipo, $ini, $fin]) {
            $r[$tipo] ??= [$ini, $fin];
        }

        return $r;
    }

    /** @return list<array{0: int, 1: int}> todas las cajas de ese tipo */
    private function todas(string $d, int $desde, int $hasta, string $tipoBuscado): array
    {
        $r = [];
        foreach ($this->recorrer($d, $desde, $hasta) as [$tipo, $ini, $fin]) {
            if ($tipo === $tipoBuscado) {
                $r[] = [$ini, $fin];
            }
        }

        return $r;
    }

    /** @return \Generator<array{0: string, 1: int, 2: int}> */
    private function recorrer(string $d, int $desde, int $hasta): \Generator
    {
        $p = $desde;
        while ($p + 8 <= $hasta) {
            $tam = $this->u32($d, $p);
            $tipo = substr($d, $p + 4, 4);
            $cabecera = 8;
            if ($tam === 1) {
                if ($p + 16 > $hasta) {
                    return;
                }
                $tam = $this->u64($d, $p + 8);
                $cabecera = 16;
            } elseif ($tam === 0) {
                $tam = $hasta - $p;
            }
            if ($tam < $cabecera || $p + $tam > $hasta) {
                return;
            }
            yield [$tipo, $p + $cabecera, $p + $tam];
            $p += $tam;
        }
    }

    private function u32(string $d, int $p): int
    {
        return (int) unpack('N', substr($d, $p, 4))[1];
    }

    private function u64(string $d, int $p): int
    {
        return ($this->u32($d, $p) << 32) | $this->u32($d, $p + 4);
    }

    private function mb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1, ',', ''), '0'), ',');
    }

    private function segundos(float $s): string
    {
        return rtrim(rtrim(number_format($s, 1, ',', ''), '0'), ',').' s';
    }
}
