<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\Entidades\Medio;
use App\Core\Catalogo\Dominio\Excepciones\ImagenInvalida;
use App\Core\Catalogo\Infraestructura\Servicios\ProcesadorDeImagenes;
use App\Core\Catalogo\Infraestructura\Servicios\ProcesadorDeVideos;
use App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Imagen o video de la portada de la página principal (reemplaza a la foto de ejemplo). Se guarda en `settings` (clave
 * `home_hero`) y los archivos en el disco público `media`, carpeta `home/`. Solo hay una portada: al subir otra, la anterior se borra.
 * Mientras no haya portada, la tienda sigue mostrando su imagen de siempre.
 */
final class AdministrarPortadaInicio
{
    public const CLAVE = 'home_hero';

    public function __construct(
        private readonly RepositorioConfiguracion $configuracion,
        private readonly ProcesadorDeImagenes $imagenes,
        private readonly ProcesadorDeVideos $videos,
    ) {}

    /** La portada tal como la ve la tienda (mismo formato que cualquier medio), o null si no hay. */
    public function obtener(): ?Medio
    {
        $h = $this->configuracion->obtener(self::CLAVE);
        if (! is_array($h) || ! isset($h['type'])) {
            return null;
        }
        $disco = Storage::disk('media');

        if ($h['type'] === 'video') {
            $urls = ['url' => $disco->url($h['url'])];
            if (! empty($h['poster'])) {
                $urls['poster'] = $disco->url($h['poster']);
            }

            return new Medio('video', 'hero', $urls, $h['alt'] ?? null, $h['width'] ?? null, $h['height'] ?? null, $h['duration_s'] ?? null);
        }

        return new Medio('image', 'hero', array_map(fn (string $r) => $disco->url($r), $h['paths']), $h['alt'] ?? null, $h['width'] ?? null, $h['height'] ?? null);
    }

    /** @throws ImagenInvalida */
    public function subirImagen(string $contenido, string $alt): Medio
    {
        $resultado = $this->imagenes->procesar($contenido);
        $nombre = Str::lower(Str::random(16));
        $rutas = [];
        foreach ($resultado['versiones'] as $ancho => $webp) {
            $ruta = "home/{$nombre}-{$ancho}.webp";
            Storage::disk('media')->put($ruta, $webp);
            $rutas[(string) $ancho] = $ruta;
        }

        return $this->reemplazar(['type' => 'image', 'alt' => $alt, 'width' => $resultado['ancho'], 'height' => $resultado['alto'], 'paths' => $rutas]);
    }

    /** @throws ImagenInvalida */
    public function subirVideo(string $video, ?string $portada, ?string $alt): Medio
    {
        $datos = $this->videos->procesar($video, ProcesadorDeVideos::PERFIL_INICIO);
        $imagen = $portada !== null && $portada !== '' ? $this->imagenes->procesar($portada) : null;

        $nombre = Str::lower(Str::random(16));
        Storage::disk('media')->put("home/{$nombre}.mp4", $video);
        $nuevo = ['type' => 'video', 'alt' => $alt, 'width' => $datos['ancho'], 'height' => $datos['alto'], 'duration_s' => (int) round($datos['duracion']), 'url' => "home/{$nombre}.mp4"];
        if ($imagen !== null) {
            Storage::disk('media')->put("home/{$nombre}-portada.webp", $imagen['versiones'][max(array_keys($imagen['versiones']))]);
            $nuevo['poster'] = "home/{$nombre}-portada.webp";
        }

        return $this->reemplazar($nuevo);
    }

    public function quitar(): void
    {
        $this->borrarArchivos($this->configuracion->obtener(self::CLAVE));
        $this->configuracion->guardar(self::CLAVE, []); // la columna no admite null: vacío = sin portada
    }

    /** @param array<string, mixed> $nuevo */
    private function reemplazar(array $nuevo): Medio
    {
        $anterior = $this->configuracion->obtener(self::CLAVE);
        $this->configuracion->guardar(self::CLAVE, $nuevo);
        $this->borrarArchivos($anterior);

        return $this->obtener();
    }

    private function borrarArchivos(mixed $h): void
    {
        if (! is_array($h)) {
            return;
        }
        $rutas = $h['type'] === 'video' ? array_filter([$h['url'] ?? null, $h['poster'] ?? null]) : ($h['paths'] ?? []);
        foreach ($rutas as $ruta) {
            Storage::disk('media')->delete($ruta);
        }
    }
}
