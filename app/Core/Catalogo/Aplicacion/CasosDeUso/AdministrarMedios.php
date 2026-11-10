<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;
use App\Core\Catalogo\Dominio\Excepciones\ImagenInvalida;
use App\Core\Catalogo\Infraestructura\Modelos\CampanaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\MedioModelo;
use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use App\Core\Catalogo\Infraestructura\Servicios\ProcesadorDeImagenes;
use App\Core\Catalogo\Infraestructura\Servicios\ProcesadorDeVideos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fotos de productos, categorías y campañas. Las versiones WebP van al disco público `media` (solo la ruta relativa en la base
 * de datos) y el archivo original al disco privado, que nunca se sirve directo.
 */
final class AdministrarMedios
{
    public const ROLES = ['front', 'angle', 'detail', 'scale', 'social', 'hero', 'video'];

    public const MAX_POR_DUENO = 12;

    public function __construct(private readonly ProcesadorDeImagenes $procesador, private readonly ProcesadorDeVideos $videos) {}

    /** @return list<array<string, mixed>> */
    public function listar(string $tipo, string $clave): array
    {
        return $this->dueno($tipo, $clave)->medios()->get()->map(fn (MedioModelo $m) => $this->formato($m))->all();
    }

    /** @return array<string, mixed> */
    public function subir(string $tipo, string $clave, string $contenido, string $rol, ?string $alt): array
    {
        $dueno = $this->dueno($tipo, $clave);
        if ($rol === 'video') {
            throw new ImagenInvalida('Un video se sube con "Subir video", no como imagen.', 'role');
        }
        if ($dueno->medios()->count() >= self::MAX_POR_DUENO) {
            throw new ImagenInvalida('Ya hay '.self::MAX_POR_DUENO.' imágenes. Elimina alguna antes de subir otra.');
        }

        // Antes de procesar o guardar nada: un rechazo no debe dejar archivos sueltos
        $this->verificarPrincipal($dueno, $rol, null);

        $resultado = $this->procesador->procesar($contenido);

        // Nombre propio, nunca el del archivo subido
        $carpeta = match ($tipo) {
            'producto' => 'products/'.$dueno->sku,
            'campana' => 'campaigns/'.$dueno->slug,
            default => 'categories/'.$dueno->slug,
        };
        $nombre = Str::lower(Str::random(16));
        $variantes = [];
        foreach ($resultado['versiones'] as $ancho => $webp) {
            $ruta = "{$carpeta}/{$nombre}-{$ancho}.webp";
            Storage::disk('media')->put($ruta, $webp);
            $variantes[(string) $ancho] = $ruta;
        }
        $original = "originals/{$carpeta}/{$nombre}.{$resultado['extension']}";
        Storage::disk('private')->put($original, $contenido);

        $medio = $dueno->medios()->create([
            'type' => 'image', 'role' => $rol, 'disk' => 'media', 'variants' => $variantes, 'original_path' => $original,
            'width' => $resultado['ancho'], 'height' => $resultado['alto'], 'alt' => $alt,
            'sort_order' => ((int) $dueno->medios()->max('sort_order')) + 1,
        ]);

        return $this->formato($medio);
    }

    /**
     * @param  array<string, mixed>  $datos  alt, role, sort_order
     * @return array<string, mixed>
     */
    public function actualizar(int $id, array $datos): array
    {
        $medio = $this->medio($id);
        $dueno = $medio->product_id ? $medio->producto : ($medio->campaign_id ? $medio->campana : $medio->categoria);
        if (isset($datos['role']) && ($medio->type === 'video' ? $datos['role'] !== 'video' : $datos['role'] === 'video')) {
            throw new ImagenInvalida('El tipo de un video no se puede cambiar, ni una imagen puede pasar a ser video.', 'role');
        }
        if (isset($datos['role'])) {
            $this->verificarPrincipal($dueno, $datos['role'], $medio->id);
        }
        $medio->update($datos);

        return $this->formato($medio->refresh());
    }

    public function eliminar(int $id): void
    {
        $medio = $this->medio($id);
        foreach ($medio->variants ?? [] as $ruta) {
            Storage::disk($medio->disk)->delete($ruta);
        }
        if ($medio->original_path) {
            Storage::disk('private')->delete($medio->original_path);
        }
        $medio->delete();
    }

    /**
     * Sube el video (MP4 H.264) de un producto o del banner de una categoría (máx. 1 minuto cada uno). Se guarda tal cual, sin
     * transcodificar; la portada (imagen del primer cuadro) es opcional y se convierte a WebP. Solo un video por producto o categoría.
     *
     * @return array<string, mixed>
     */
    public function subirVideo(string $tipo, string $clave, string $video, ?string $portada, ?string $alt): array
    {
        $dueno = $this->dueno($tipo, $clave);
        if (! in_array($tipo, ['producto', 'categoria'], true)) {
            throw new ImagenInvalida('Este elemento no admite video.', 'video');
        }
        if ($dueno->medios()->where('type', 'video')->exists()) {
            throw new ImagenInvalida('Ya hay un video. Elimínalo antes de subir otro.', 'video');
        }
        if ($dueno->medios()->count() >= self::MAX_POR_DUENO) {
            throw new ImagenInvalida('Ya hay '.self::MAX_POR_DUENO.' archivos. Elimina alguno antes de subir otro.', 'video');
        }

        // Todo se valida antes de guardar: un rechazo no deja archivos sueltos
        $datos = $this->videos->procesar($video, $tipo === 'producto' ? ProcesadorDeVideos::PERFIL_PRODUCTO : ProcesadorDeVideos::PERFIL_CATEGORIA);
        $imagen = $portada !== null && $portada !== '' ? $this->procesador->procesar($portada) : null;

        $carpeta = $tipo === 'producto' ? 'products/'.$dueno->sku : 'categories/'.$dueno->slug;
        $nombre = Str::lower(Str::random(16));
        $rutaVideo = "{$carpeta}/{$nombre}.mp4";
        Storage::disk('media')->put($rutaVideo, $video);
        $variantes = ['url' => $rutaVideo];
        if ($imagen !== null) {
            $rutaPortada = "{$carpeta}/{$nombre}-portada.webp";
            Storage::disk('media')->put($rutaPortada, $imagen['versiones'][max(array_keys($imagen['versiones']))]);
            $variantes['poster'] = $rutaPortada;
        }

        $medio = $dueno->medios()->create([
            'type' => 'video', 'role' => 'video', 'disk' => 'media', 'variants' => $variantes, 'original_path' => null,
            'width' => $datos['ancho'], 'height' => $datos['alto'], 'duration_s' => (int) round($datos['duracion']), 'alt' => $alt,
            'sort_order' => ((int) $dueno->medios()->max('sort_order')) + 1,
        ]);

        return $this->formato($medio);
    }

    /** Solo una imagen puede ser la principal: si ya hay una, hay que cambiarle el tipo antes de marcar otra. */
    private function verificarPrincipal(Model $dueno, string $rol, ?int $excepto): void
    {
        if ($rol !== 'front') {
            return;
        }
        $hayOtra = $dueno->medios()->where('role', 'front')->when($excepto, fn ($q) => $q->where('id', '!=', $excepto))->exists();
        if ($hayOtra) {
            throw new ImagenInvalida('Ya hay una imagen principal. Cambia primero su tipo (por ejemplo a "Otro ángulo") para marcar otra.', 'role');
        }
    }

    private function medio(int $id): MedioModelo
    {
        return MedioModelo::query()->find($id) ?? throw new CatalogoNoEncontrado("La imagen {$id} no existe.");
    }

    private function dueno(string $tipo, string $clave): Model
    {
        if ($tipo === 'producto') {
            return ProductoModelo::query()->where('sku', $clave)->first() ?? throw CatalogoNoEncontrado::producto($clave);
        }

        if ($tipo === 'campana') {
            return CampanaModelo::query()->where('slug', $clave)->first() ?? throw CatalogoNoEncontrado::campana($clave);
        }

        return CategoriaModelo::query()->where('slug', $clave)->first() ?? throw CatalogoNoEncontrado::categoria($clave);
    }

    /** @return array<string, mixed> */
    private function formato(MedioModelo $m): array
    {
        $disco = Storage::disk($m->disk);

        return [
            'id' => $m->id, 'type' => $m->type, 'role' => $m->role, 'alt' => $m->alt, 'sort_order' => $m->sort_order,
            'width' => $m->width, 'height' => $m->height, 'duration_s' => $m->duration_s,
            'urls' => array_map(fn (string $ruta) => $disco->url($ruta), $m->variants ?? []),
        ];
    }
}
