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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fotos de productos, categorías y campañas. Las versiones WebP van al disco público `media` (solo la ruta relativa en la base
 * de datos) y el archivo original al disco privado, que nunca se sirve directo.
 */
final class AdministrarMedios
{
    public const ROLES = ['front', 'angle', 'detail', 'scale', 'social', 'hero'];

    public const MAX_POR_DUENO = 12;

    public function __construct(private readonly ProcesadorDeImagenes $procesador) {}

    /** @return list<array<string, mixed>> */
    public function listar(string $tipo, string $clave): array
    {
        return $this->dueno($tipo, $clave)->medios()->get()->map(fn (MedioModelo $m) => $this->formato($m))->all();
    }

    /** @return array<string, mixed> */
    public function subir(string $tipo, string $clave, string $contenido, string $rol, ?string $alt): array
    {
        $dueno = $this->dueno($tipo, $clave);
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
            'width' => $m->width, 'height' => $m->height,
            'urls' => array_map(fn (string $ruta) => $disco->url($ruta), $m->variants ?? []),
        ];
    }
}
