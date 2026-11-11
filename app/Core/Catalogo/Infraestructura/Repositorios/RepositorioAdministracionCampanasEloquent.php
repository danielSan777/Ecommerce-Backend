<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Infraestructura\Repositorios;

use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;
use App\Core\Catalogo\Dominio\Repositorios\RepositorioAdministracionCampanas;
use App\Core\Catalogo\Infraestructura\Modelos\CampanaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class RepositorioAdministracionCampanasEloquent implements RepositorioAdministracionCampanas
{
    public function listar(): array
    {
        return CampanaModelo::query()->with(['categoriaRelacionada', 'productos', 'medios'])->orderBy('id')->get()
            ->map(fn (CampanaModelo $c) => $this->formato($c))->all();
    }

    public function obtener(string $slug): array
    {
        return $this->formato($this->campana($slug));
    }

    public function crear(array $datos): array
    {
        return DB::transaction(function () use ($datos) {
            $campana = CampanaModelo::query()->create([...$this->columnas($datos), 'slug' => $datos['slug']]);
            $this->guardarProductos($campana, $datos['products'] ?? []);

            return $this->formato($campana->refresh()->load(['categoriaRelacionada', 'productos', 'medios']));
        });
    }

    public function actualizar(string $slug, array $datos): array
    {
        return DB::transaction(function () use ($slug, $datos) {
            $campana = $this->campana($slug);
            $campana->update($this->columnas($datos));
            if (array_key_exists('products', $datos)) {
                $this->guardarProductos($campana, $datos['products'] ?? []);
            }

            return $this->formato($campana->refresh()->load(['categoriaRelacionada', 'productos', 'medios']));
        });
    }

    public function eliminar(string $slug): void
    {
        $campana = $this->campana($slug);
        // Las fotos y los videos de la campaña se borran del disco (sus filas se van con ella); los productos no se tocan.
        foreach ($campana->medios as $medio) {
            foreach ($medio->variants ?? [] as $ruta) {
                Storage::disk($medio->disk)->delete($ruta);
            }
            if ($medio->original_path) {
                Storage::disk('private')->delete($medio->original_path);
            }
        }
        $campana->delete(); // sus productos elegidos y sus filas de medios se borran con ella
    }

    private function campana(string $slug): CampanaModelo
    {
        return CampanaModelo::query()->with(['categoriaRelacionada', 'productos', 'medios'])->where('slug', $slug)->first()
            ?? throw CatalogoNoEncontrado::campana($slug);
    }

    /**
     * Solo las columnas de la campaña (el slug no se edita).
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function columnas(array $datos): array
    {
        $columnas = array_intersect_key($datos, array_flip(['name', 'start_md', 'end_md', 'event_md', 'tagline', 'slogan', 'theme_color', 'emoji', 'closing_message', 'closing_until_md', 'is_active', 'seo_title', 'seo_description']));
        if (isset($datos['related_category'])) {
            $columnas['related_category_id'] = CategoriaModelo::query()->where('slug', $datos['related_category'])->value('id');
        }

        return $columnas;
    }

    /** @param list<string> $skus en el orden elegido */
    private function guardarProductos(CampanaModelo $campana, array $skus): void
    {
        $ids = ProductoModelo::query()->whereIn('sku', $skus)->pluck('id', 'sku');
        $sync = [];
        foreach (array_values(array_unique($skus)) as $orden => $sku) {
            if ($ids->has($sku)) {
                $sync[$ids[$sku]] = ['sort_order' => $orden];
            }
        }
        $campana->productos()->sync($sync);
    }

    /** @return array<string, mixed> */
    private function formato(CampanaModelo $c): array
    {
        return [
            'slug' => $c->slug,
            'name' => $c->name,
            'related_category' => ['slug' => $c->categoriaRelacionada->slug, 'name' => $c->categoriaRelacionada->name],
            'start_md' => $c->start_md,
            'end_md' => $c->end_md,
            'event_md' => $c->event_md,
            'tagline' => $c->tagline,
            'slogan' => $c->slogan,
            'theme_color' => $c->theme_color,
            'emoji' => $c->emoji,
            'closing_message' => $c->closing_message,
            'closing_until_md' => $c->closing_until_md,
            'cover_url' => ($m = $c->medios->firstWhere('type', 'image')) ? ($m->variants['600'] ?? null ? Storage::disk($m->disk)->url($m->variants['600']) : null) : null,
            'video_url' => ($v = $c->medios->firstWhere('type', 'video')) ? Storage::disk($v->disk)->url($v->variants['url']) : null,
            'is_active' => $c->is_active,
            'seo_title' => $c->seo_title,
            'seo_description' => $c->seo_description,
            'products' => $c->productos->map(fn (ProductoModelo $p) => ['sku' => $p->sku, 'slug' => $p->slug, 'name' => $p->name])->all(),
            'updated_at' => $c->updated_at?->toIso8601String() ?? '',
        ];
    }
}
