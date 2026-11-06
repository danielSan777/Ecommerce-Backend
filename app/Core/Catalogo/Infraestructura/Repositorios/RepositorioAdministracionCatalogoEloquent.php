<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Infraestructura\Repositorios;

use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;
use App\Core\Catalogo\Dominio\Repositorios\RepositorioAdministracionCatalogo;
use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use Illuminate\Support\Facades\DB;

final class RepositorioAdministracionCatalogoEloquent implements RepositorioAdministracionCatalogo
{
    public function productosDeCategoria(string $slug): array
    {
        $categoria = $this->categoria($slug);

        return ProductoModelo::query()->where('category_id', $categoria->id)->orderBy('id')->get(['sku', 'slug', 'name'])
            ->map(fn (ProductoModelo $p) => ['sku' => $p->sku, 'slug' => $p->slug, 'name' => $p->name])->all();
    }

    public function eliminarCategoria(string $slug): void
    {
        DB::transaction(fn () => $this->categoria($slug)->delete());
    }

    private function categoria(string $slug): CategoriaModelo
    {
        return CategoriaModelo::query()->where('slug', $slug)->first() ?? throw CatalogoNoEncontrado::categoria($slug);
    }
}
