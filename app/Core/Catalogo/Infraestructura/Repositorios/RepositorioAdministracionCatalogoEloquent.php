<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Infraestructura\Repositorios;

use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;
use App\Core\Catalogo\Dominio\Repositorios\RepositorioAdministracionCatalogo;
use App\Core\Catalogo\Infraestructura\Modelos\CampanaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use Illuminate\Support\Facades\DB;

final class RepositorioAdministracionCatalogoEloquent implements RepositorioAdministracionCatalogo
{
    public function listarCategorias(): array
    {
        return CategoriaModelo::query()->withCount(['productos', 'subcategorias'])->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (CategoriaModelo $c) => $this->formato($c))->all();
    }

    public function crearCategoria(array $datos): array
    {
        $categoria = CategoriaModelo::query()->create($datos);
        $categoria->refresh(); // trae los valores por defecto de la base (activa, orden)

        return $this->formato($categoria->loadCount(['productos', 'subcategorias']));
    }

    public function actualizarCategoria(string $slug, array $datos): array
    {
        $categoria = $this->categoria($slug);
        $categoria->update($datos);

        return $this->formato($categoria->loadCount(['productos', 'subcategorias']));
    }

    public function productosDeCategoria(string $slug): array
    {
        $categoria = $this->categoria($slug);

        return ProductoModelo::query()->where('category_id', $categoria->id)->orderBy('id')->get(['sku', 'slug', 'name'])
            ->map(fn (ProductoModelo $p) => ['sku' => $p->sku, 'slug' => $p->slug, 'name' => $p->name])->all();
    }

    public function campanasDeCategoria(string $slug): array
    {
        $categoria = $this->categoria($slug);

        return CampanaModelo::query()->where('related_category_id', $categoria->id)->orderBy('id')->get(['slug', 'name'])
            ->map(fn (CampanaModelo $c) => ['slug' => $c->slug, 'name' => $c->name])->all();
    }

    public function eliminarCategoria(string $slug): void
    {
        DB::transaction(fn () => $this->categoria($slug)->delete());
    }

    private function categoria(string $slug): CategoriaModelo
    {
        return CategoriaModelo::query()->where('slug', $slug)->first() ?? throw CatalogoNoEncontrado::categoria($slug);
    }

    /** @return array<string, mixed> */
    private function formato(CategoriaModelo $c): array
    {
        return [
            'slug' => $c->slug,
            'name' => $c->name,
            'headline' => $c->headline,
            'intro_html' => $c->intro_html,
            'sort_order' => (int) $c->sort_order,
            'is_active' => (bool) $c->is_active,
            'seo_title' => $c->seo_title,
            'seo_description' => $c->seo_description,
            'products_count' => (int) $c->productos_count,
            'subcategories_count' => (int) $c->subcategorias_count,
            'updated_at' => $c->updated_at->toIso8601String(),
        ];
    }
}
