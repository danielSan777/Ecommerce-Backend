<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Infraestructura\Repositorios;

use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;
use App\Core\Catalogo\Dominio\Repositorios\RepositorioAdministracionSubcategorias;
use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use App\Core\Catalogo\Infraestructura\Modelos\SubcategoriaModelo;
use Illuminate\Support\Facades\DB;

final class RepositorioAdministracionSubcategoriasEloquent implements RepositorioAdministracionSubcategorias
{
    public function listar(string $categoria): array
    {
        $cat = $this->categoria($categoria);

        return SubcategoriaModelo::query()->where('category_id', $cat->id)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (SubcategoriaModelo $s) => $this->formato($s, $cat))->all();
    }

    public function crear(string $categoria, array $datos): array
    {
        $cat = $this->categoria($categoria);
        $sub = SubcategoriaModelo::query()->create([...$datos, 'category_id' => $cat->id])->refresh();

        return $this->formato($sub, $cat);
    }

    public function actualizar(string $categoria, string $slug, array $datos): array
    {
        $cat = $this->categoria($categoria);
        $sub = $this->subcategoria($cat, $slug);
        $sub->update($datos);

        return $this->formato($sub, $cat);
    }

    public function productos(string $categoria, string $slug): array
    {
        $sub = $this->subcategoria($this->categoria($categoria), $slug);

        return ProductoModelo::query()->where('subcategory_id', $sub->id)->orderBy('id')->get(['sku', 'slug', 'name'])
            ->map(fn (ProductoModelo $p) => ['sku' => $p->sku, 'slug' => $p->slug, 'name' => $p->name])->all();
    }

    public function eliminar(string $categoria, string $slug): void
    {
        $sub = $this->subcategoria($this->categoria($categoria), $slug);

        DB::transaction(function () use ($sub): void {
            // Los productos ya eliminados (borrado lógico) no cuentan, pero su llave foránea impediría borrar la fila.
            ProductoModelo::onlyTrashed()->where('subcategory_id', $sub->id)->update(['subcategory_id' => null]);
            $sub->delete();
        });
    }

    private function categoria(string $slug): CategoriaModelo
    {
        return CategoriaModelo::query()->where('slug', $slug)->first() ?? throw CatalogoNoEncontrado::categoria($slug);
    }

    private function subcategoria(CategoriaModelo $categoria, string $slug): SubcategoriaModelo
    {
        return SubcategoriaModelo::query()->where('category_id', $categoria->id)->where('slug', $slug)->first()
            ?? throw CatalogoNoEncontrado::subcategoria($slug);
    }

    /** @return array<string, mixed> */
    private function formato(SubcategoriaModelo $s, CategoriaModelo $c): array
    {
        return [
            'slug' => $s->slug,
            'name' => $s->name,
            'category_slug' => $c->slug,
            'sort_order' => (int) $s->sort_order,
            'is_active' => (bool) $s->is_active,
            'products_count' => ProductoModelo::query()->where('subcategory_id', $s->id)->count(),
        ];
    }
}
