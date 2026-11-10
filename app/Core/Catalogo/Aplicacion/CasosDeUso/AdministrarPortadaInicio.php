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
 * Portada de la página principal: una lista sin límite de imágenes y videos que el administrador agrega y quita; la tienda
 * los muestra uno tras otro. Se guarda en `settings` (clave `home_hero`) y los archivos en el disco público `media`, carpeta `home/`. La portada de la página de categorías es la misma lógica con otra clave y carpeta (subclase).
 * Mientras la lista esté vacía, la tienda sigue mostrando su imagen de siempre.
 */
class AdministrarPortadaInicio
{
    public function __construct(
        private readonly RepositorioConfiguracion $configuracion,
        private readonly ProcesadorDeImagenes $imagenes,
        private readonly ProcesadorDeVideos $videos,
        private readonly string $clave = 'home_hero',
        private readonly string $carpeta = 'home',
    ) {}

    /** @return list<array{id: string, medio: Medio}> en el orden en que se agregaron */
    public function lista(): array
    {
        return array_map(fn (array $i) => ['id' => $i['id'], 'medio' => $this->medio($i)], $this->items());
    }

    /** @return array{id: string, medio: Medio} @throws ImagenInvalida */
    public function agregarImagen(string $contenido, string $alt): array
    {
        $resultado = $this->imagenes->procesar($contenido);
        $nombre = Str::lower(Str::random(16));
        $rutas = [];
        foreach ($resultado['versiones'] as $ancho => $webp) {
            $ruta = "{$this->carpeta}/{$nombre}-{$ancho}.webp";
            Storage::disk('media')->put($ruta, $webp);
            $rutas[(string) $ancho] = $ruta;
        }

        return $this->agregar(['type' => 'image', 'alt' => $alt, 'width' => $resultado['ancho'], 'height' => $resultado['alto'], 'paths' => $rutas]);
    }

    /** @return array{id: string, medio: Medio} @throws ImagenInvalida */
    public function agregarVideo(string $video, ?string $portada, ?string $alt): array
    {
        $datos = $this->videos->procesar($video, ProcesadorDeVideos::PERFIL_INICIO);
        $imagen = $portada !== null && $portada !== '' ? $this->imagenes->procesar($portada) : null;

        $nombre = Str::lower(Str::random(16));
        Storage::disk('media')->put("{$this->carpeta}/{$nombre}.mp4", $video);
        $nuevo = ['type' => 'video', 'alt' => $alt, 'width' => $datos['ancho'], 'height' => $datos['alto'], 'duration_s' => (int) round($datos['duracion']), 'url' => "{$this->carpeta}/{$nombre}.mp4"];
        if ($imagen !== null) {
            Storage::disk('media')->put("{$this->carpeta}/{$nombre}-portada.webp", $imagen['versiones'][max(array_keys($imagen['versiones']))]);
            $nuevo['poster'] = "{$this->carpeta}/{$nombre}-portada.webp";
        }

        return $this->agregar($nuevo);
    }

    /** Quita una imagen o video de la portada y borra sus archivos. false si no existe. */
    public function quitar(string $id): bool
    {
        $items = $this->items();
        $quedan = array_values(array_filter($items, fn (array $i) => $i['id'] !== $id));
        if (count($quedan) === count($items)) {
            return false;
        }
        foreach ($items as $i) {
            if ($i['id'] === $id) {
                $this->borrarArchivos($i);
            }
        }
        $this->configuracion->guardar($this->clave, $quedan);

        return true;
    }

    /** @param array<string, mixed> $nuevo @return array{id: string, medio: Medio} */
    private function agregar(array $nuevo): array
    {
        $nuevo['id'] = Str::lower(Str::random(12));
        $this->configuracion->guardar($this->clave, [...$this->items(), $nuevo]);

        return ['id' => $nuevo['id'], 'medio' => $this->medio($nuevo)];
    }

    /** @return list<array<string, mixed>> */
    private function items(): array
    {
        $h = $this->configuracion->obtener($this->clave);
        if (! is_array($h) || $h === []) {
            return [];
        }
        // Formato anterior: una sola portada guardada como objeto
        if (isset($h['type'])) {
            return [$h + ['id' => 'principal']];
        }

        return array_values(array_filter($h, fn ($i) => is_array($i) && isset($i['type'], $i['id'])));
    }

    /** @param array<string, mixed> $h */
    private function medio(array $h): Medio
    {
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

    /** @param array<string, mixed> $h */
    private function borrarArchivos(array $h): void
    {
        $rutas = $h['type'] === 'video' ? array_filter([$h['url'] ?? null, $h['poster'] ?? null]) : ($h['paths'] ?? []);
        foreach ($rutas as $ruta) {
            Storage::disk('media')->delete($ruta);
        }
    }
}
