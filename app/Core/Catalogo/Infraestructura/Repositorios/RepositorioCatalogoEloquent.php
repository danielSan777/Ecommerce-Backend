<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Infraestructura\Repositorios;

use App\Core\Catalogo\Dominio\Entidades\Categoria;
use App\Core\Catalogo\Dominio\Entidades\Medio;
use App\Core\Catalogo\Dominio\Entidades\Producto;
use App\Core\Catalogo\Dominio\Entidades\Subcategoria;
use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;
use App\Core\Catalogo\Dominio\Repositorios\RepositorioCatalogo;
use App\Core\Catalogo\Dominio\ValueObjects\Precio;
use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\MedioModelo;
use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use Illuminate\Support\Facades\Storage;

final class RepositorioCatalogoEloquent implements RepositorioCatalogo
{
    public function categorias(): array
    {
        return CategoriaModelo::query()->where('is_active', true)->orderBy('sort_order')
            ->with(['subcategorias' => fn ($q) => $q->where('is_active', true), 'medios'])->get()
            ->map(fn (CategoriaModelo $c) => $this->categoria_($c, null, []))->all();
    }

    public function categoria(string $slug, ?string $subcategoria = null): Categoria
    {
        $categoria = CategoriaModelo::query()->where('is_active', true)->where('slug', $slug)
            ->with(['subcategorias' => fn ($q) => $q->where('is_active', true), 'medios'])->first()
            ?? throw CatalogoNoEncontrado::categoria($slug);

        $filtro = null;
        if ($subcategoria !== null) {
            $filtro = $categoria->subcategorias->firstWhere('slug', $subcategoria)
                ?? throw CatalogoNoEncontrado::subcategoria($subcategoria);
        }

        $productos = ProductoModelo::query()->where('is_active', true)->where('category_id', $categoria->id)
            ->when($filtro, fn ($q) => $q->where('subcategory_id', $filtro->id))
            ->with(['categoria', 'subcategoria', 'medios'])->orderBy('id')->get()
            ->map(fn (ProductoModelo $p) => $this->producto_($p))->all();

        return $this->categoria_($categoria, $filtro?->slug, $productos);
    }

    public function productos(?int $limite = null): array
    {
        return ProductoModelo::query()->where('is_active', true)->with(['categoria', 'subcategoria', 'medios'])
            ->orderByDesc('id')->when($limite, fn ($q) => $q->limit($limite))->get()
            ->map(fn (ProductoModelo $p) => $this->producto_($p))->all();
    }

    public function producto(string $slug): Producto
    {
        $modelo = ProductoModelo::query()->where('is_active', true)->where('slug', $slug)
            ->with(['categoria', 'subcategoria', 'medios'])->first()
            ?? throw CatalogoNoEncontrado::producto($slug);

        return $this->producto_($modelo);
    }

    /** @param list<Producto> $productos */
    private function categoria_(CategoriaModelo $c, ?string $activa, array $productos): Categoria
    {
        $desde = ProductoModelo::query()->where('is_active', true)->where('category_id', $c->id)->min('base_price_cents');

        return new Categoria(
            slug: $c->slug,
            nombre: $c->name,
            titular: $c->headline,
            introHtml: $c->intro_html,
            portada: ($m = $c->medios->firstWhere('type', 'image')) ? $this->medio($m) : null,
            subcategorias: $c->subcategorias->map(fn ($s) => new Subcategoria($s->slug, $s->name))->all(),
            subcategoriaActiva: $activa,
            productos: $productos,
            precioDesdeCents: $desde === null ? null : (int) $desde,
            seoTitulo: $c->seo_title,
            seoDescripcion: $c->seo_description,
            videos: $c->medios->where('type', 'video')->map(fn ($v) => $this->medio($v))->values()->all(),
        );
    }

    private function producto_(ProductoModelo $p): Producto
    {
        return new Producto(
            sku: $p->sku,
            slug: $p->slug,
            tipo: $p->type,
            nombre: $p->name,
            categoriaSlug: $p->categoria->slug,
            categoriaNombre: $p->categoria->name,
            subcategoria: $p->subcategoria ? new Subcategoria($p->subcategoria->slug, $p->subcategoria->name) : null,
            disponible: $p->is_available,
            precio: Precio::conDescuentoPorcentaje((int) $p->base_price_cents, $p->discount_percent ?? 0),
            tamano: $p->size_text,
            material: $p->material_text,
            incluye: array_values($p->includes ?? []),
            descripcionHtml: $p->description_html,
            medios: $p->medios->map(fn (MedioModelo $m) => $this->medio($m))->all(),
            skusRelacionados: $this->relacionados($p),
            seoTitulo: $p->seo_title,
            seoDescripcion: $p->seo_description,
            sinonimos: array_values($p->search_synonyms ?? []),
        );
    }

    /** Hasta que Josué elija relacionados a mano: misma subcategoría; si no hay, misma categoría (máx. 4). @return list<string> */
    private function relacionados(ProductoModelo $p): array
    {
        $base = fn () => ProductoModelo::query()->where('is_active', true)->where('id', '!=', $p->id);

        $mismos = $p->subcategory_id ? $base()->where('subcategory_id', $p->subcategory_id)->orderBy('id')->limit(4)->pluck('sku')->all() : [];
        if ($mismos === []) {
            $mismos = $base()->where('category_id', $p->category_id)->orderBy('id')->limit(4)->pluck('sku')->all();
        }

        return array_values($mismos);
    }

    private function medio(MedioModelo $m): Medio
    {
        $disco = Storage::disk($m->disk);
        $urls = array_map(fn (string $ruta) => $disco->url($ruta), $m->variants ?? []);

        return new Medio($m->type, $m->role, $urls, $m->alt, $m->width, $m->height, $m->duration_s);
    }
}
