<?php

declare(strict_types=1);

namespace App\Core\Configurador\Infraestructura\Repositorios;

use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;
use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use App\Core\Configurador\Dominio\Excepciones\ConfiguradorEnUso;
use App\Core\Configurador\Dominio\Excepciones\ConfiguradorNoEncontrado;
use App\Core\Configurador\Dominio\Repositorios\RepositorioConfigurador;
use App\Core\Configurador\Infraestructura\Modelos\AccesorioModelo;
use App\Core\Configurador\Infraestructura\Modelos\CategoriaDeAccesorioModelo;
use App\Core\Configurador\Infraestructura\Modelos\ConfiguracionModelo;
use App\Core\Configurador\Infraestructura\Modelos\UbicacionModelo;
use App\Core\Configurador\Infraestructura\Servicios\ProcesadorDeAccesorios;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class RepositorioConfiguradorEloquent implements RepositorioConfigurador
{
    private const GEOMETRIA = ['x', 'y', 'width', 'height', 'rot_x', 'rot_y', 'rot_z'];

    // ---------------------------------------------------------------- categorías de accesorios

    public function categoriasDeAccesorios(): array
    {
        return CategoriaDeAccesorioModelo::query()->withCount('accesorios')->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (CategoriaDeAccesorioModelo $c): array => $this->formatoCategoriaDeAccesorio($c))->all();
    }

    public function crearCategoriaDeAccesorio(array $datos): array
    {
        $c = CategoriaDeAccesorioModelo::query()->create($datos);

        return $this->formatoCategoriaDeAccesorio($c->loadCount('accesorios'));
    }

    public function actualizarCategoriaDeAccesorio(int $id, array $datos): array
    {
        $c = CategoriaDeAccesorioModelo::query()->find($id) ?? throw ConfiguradorNoEncontrado::categoriaDeAccesorio($id);
        $c->update($datos);

        return $this->formatoCategoriaDeAccesorio($c->loadCount('accesorios'));
    }

    public function eliminarCategoriaDeAccesorio(int $id): void
    {
        $c = CategoriaDeAccesorioModelo::query()->withCount('accesorios')->find($id) ?? throw ConfiguradorNoEncontrado::categoriaDeAccesorio($id);
        if ($c->accesorios_count > 0) {
            throw new ConfiguradorEnUso("No se puede eliminar la categoría '{$c->name}' porque tiene {$c->accesorios_count} accesorio(s). Muévelos a otra categoría o elimínalos primero.");
        }
        $c->delete(); // las reglas de las ubicaciones que la mencionaban se borran en cascada
    }

    /** @return array<string, mixed> */
    private function formatoCategoriaDeAccesorio(CategoriaDeAccesorioModelo $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'sort_order' => (int) $c->sort_order, 'accessories_count' => (int) $c->accesorios_count];
    }

    // ---------------------------------------------------------------- accesorios

    public function accesorios(): array
    {
        return AccesorioModelo::query()->with('categoria')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (AccesorioModelo $a): array => $this->formatoAccesorio($a))->all();
    }

    public function crearAccesorio(array $datos): array
    {
        return $this->formatoAccesorio(AccesorioModelo::query()->create($datos)->load('categoria'));
    }

    public function actualizarAccesorio(int $id, array $datos): array
    {
        $a = AccesorioModelo::query()->find($id) ?? throw ConfiguradorNoEncontrado::accesorio($id);
        $a->update($datos);

        return $this->formatoAccesorio($a->load('categoria'));
    }

    public function rutasDeImagenDelAccesorio(int $id): array
    {
        $a = AccesorioModelo::query()->find($id) ?? throw ConfiguradorNoEncontrado::accesorio($id);

        return array_values($a->image_variants ?? []);
    }

    public function guardarImagenDelAccesorio(int $id, array $variantes, string $alt): array
    {
        $a = AccesorioModelo::query()->find($id) ?? throw ConfiguradorNoEncontrado::accesorio($id);
        $a->update(['image_variants' => $variantes, 'alt' => $alt]);

        return $this->formatoAccesorio($a->load('categoria'));
    }

    public function eliminarAccesorio(int $id): void
    {
        $a = AccesorioModelo::query()->find($id) ?? throw ConfiguradorNoEncontrado::accesorio($id);

        $configuraciones = DB::table('configurator_config_accessories')->where('accessory_id', $id)->pluck('config_id')
            ->merge(DB::table('configurator_config_locations')->where('fixed_accessory_id', $id)->pluck('config_id'))
            ->merge(DB::table('configurator_config_exceptions')->where('accessory_id', $id)->pluck('config_id'))
            ->unique()->count();
        if ($configuraciones > 0) {
            throw new ConfiguradorEnUso("No se puede eliminar el accesorio '{$a->name}' porque está en {$configuraciones} configuración(es). Quítalo de ellas o, si solo quieres ocultarlo, desactívalo.");
        }
        $a->delete();
    }

    /** @return array<string, mixed> */
    private function formatoAccesorio(AccesorioModelo $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'type' => $a->type,
            'behavior' => $a->behavior,
            'text' => $a->default_text,
            'category_id' => $a->accessory_category_id,
            'category' => $a->categoria?->name,
            'image' => $this->url($a->image_variants, (string) ProcesadorDeAccesorios::GRANDE),
            'thumb' => $this->url($a->image_variants, (string) ProcesadorDeAccesorios::MINIATURA),
            'alt' => $a->alt,
            'sort_order' => (int) $a->sort_order,
            'is_active' => (bool) $a->is_active,
        ];
    }

    /** @param array<string, string>|null $variantes */
    private function url(?array $variantes, string $ancho): ?string
    {
        $ruta = $variantes[$ancho] ?? null;

        return $ruta === null ? null : Storage::disk('media')->url($ruta);
    }

    // ---------------------------------------------------------------- ubicaciones

    public function ubicaciones(): array
    {
        return $this->todasLasUbicaciones()->values()->all();
    }

    public function crearUbicacion(array $datos): array
    {
        $u = UbicacionModelo::query()->create(['name' => $datos['name'], 'sort_order' => $datos['sort_order'] ?? 0]);
        $this->sincronizarReglas($u->id, $datos['allowed_category_ids'] ?? [], $datos['excluded_category_ids'] ?? []);

        return $this->ubicacion($u->id);
    }

    public function actualizarUbicacion(int $id, array $datos): array
    {
        $u = UbicacionModelo::query()->find($id) ?? throw ConfiguradorNoEncontrado::ubicacion($id);
        $u->update(array_intersect_key($datos, ['name' => 1, 'sort_order' => 1]));
        if (array_key_exists('allowed_category_ids', $datos) || array_key_exists('excluded_category_ids', $datos)) {
            $actual = $this->ubicacion($id);
            $this->sincronizarReglas(
                $id,
                $datos['allowed_category_ids'] ?? $actual['allowed_category_ids'],
                $datos['excluded_category_ids'] ?? $actual['excluded_category_ids'],
            );
        }

        return $this->ubicacion($id);
    }

    public function eliminarUbicacion(int $id): void
    {
        $u = UbicacionModelo::query()->find($id) ?? throw ConfiguradorNoEncontrado::ubicacion($id);
        $usos = DB::table('configurator_config_locations')->where('location_id', $id)->distinct()->count('config_id');
        if ($usos > 0) {
            throw new ConfiguradorEnUso("No se puede eliminar la ubicación '{$u->name}' porque está en {$usos} configuración(es). Deshabilítala en ellas en lugar de eliminarla.");
        }
        $u->delete();
    }

    /** @param list<int> $permitidas @param list<int> $excluidas */
    private function sincronizarReglas(int $ubicacion, array $permitidas, array $excluidas): void
    {
        DB::table('location_category_rules')->where('location_id', $ubicacion)->delete();
        $filas = [];
        foreach (array_unique($permitidas) as $c) {
            $filas[] = ['location_id' => $ubicacion, 'accessory_category_id' => $c, 'rule' => 'allow'];
        }
        foreach (array_unique($excluidas) as $c) {
            $filas[] = ['location_id' => $ubicacion, 'accessory_category_id' => $c, 'rule' => 'exclude'];
        }
        if ($filas !== []) {
            DB::table('location_category_rules')->insert($filas);
        }
    }

    /** @return array<string, mixed> */
    private function ubicacion(int $id): array
    {
        return $this->todasLasUbicaciones()->get($id) ?? throw ConfiguradorNoEncontrado::ubicacion($id);
    }

    /** @return Collection<int, array<string, mixed>> ubicaciones por id, con sus reglas de categoría */
    private function todasLasUbicaciones(): Collection
    {
        $reglas = DB::table('location_category_rules')
            ->join('accessory_categories', 'accessory_categories.id', '=', 'location_category_rules.accessory_category_id')
            ->orderBy('accessory_categories.sort_order')->orderBy('accessory_categories.name')
            ->get(['location_id', 'accessory_category_id', 'name', 'rule'])->groupBy('location_id');

        return UbicacionModelo::query()->orderBy('sort_order')->orderBy('id')->get()->mapWithKeys(function (UbicacionModelo $u) use ($reglas): array {
            $propias = $reglas->get($u->id, collect());
            $permitidas = $propias->where('rule', 'allow');
            $excluidas = $propias->where('rule', 'exclude');

            return [$u->id => [
                'id' => $u->id,
                'name' => $u->name,
                'sort_order' => (int) $u->sort_order,
                'allowed_category_ids' => $permitidas->pluck('accessory_category_id')->map(fn ($v): int => (int) $v)->values()->all(),
                'allowed_categories' => $permitidas->pluck('name')->values()->all(),
                'excluded_category_ids' => $excluidas->pluck('accessory_category_id')->map(fn ($v): int => (int) $v)->values()->all(),
                'excluded_categories' => $excluidas->pluck('name')->values()->all(),
            ]];
        });
    }

    // ---------------------------------------------------------------- configuraciones

    public function configuracionDeCategoria(string $slug): array
    {
        $categoria = CategoriaModelo::query()->where('slug', $slug)->first() ?? throw CatalogoNoEncontrado::categoria($slug);
        $productos = ProductoModelo::query()->where('category_id', $categoria->id)->where('type', 'personalizable')->orderBy('name')->get(['sku', 'name', 'uses_own_configurator']);

        return [
            'scope' => 'category',
            'slug' => $categoria->slug,
            'name' => $categoria->name,
            'has_config' => $this->idDeConfiguracion('category_id', $categoria->id) !== null,
            'products' => $productos->map(fn ($p): array => ['sku' => $p->sku, 'name' => $p->name, 'uses_own_configurator' => (bool) $p->uses_own_configurator])->all(),
            'config' => $this->armar($this->idDeConfiguracion('category_id', $categoria->id)),
        ];
    }

    public function configuracionDeProducto(string $sku): array
    {
        $producto = ProductoModelo::query()->with('categoria')->where('sku', $sku)->first() ?? throw CatalogoNoEncontrado::producto($sku);
        $propia = (bool) $producto->uses_own_configurator;
        $id = $propia
            ? $this->idDeConfiguracion('product_id', $producto->id)
            : $this->idDeConfiguracion('category_id', $producto->category_id);

        return [
            'scope' => 'product',
            'sku' => $producto->sku,
            'name' => $producto->name,
            'category_slug' => $producto->categoria?->slug,
            'category_name' => $producto->categoria?->name,
            'uses_own_configurator' => $propia,
            'source' => $propia ? 'product' : 'category',
            'has_config' => $id !== null,
            'config' => $this->armar($id),
        ];
    }

    public function guardarConfiguracionDeCategoria(string $slug, array $configuracion): array
    {
        $categoria = CategoriaModelo::query()->where('slug', $slug)->first() ?? throw CatalogoNoEncontrado::categoria($slug);
        DB::transaction(fn () => $this->guardar($this->configuracion('category_id', $categoria->id), $configuracion));

        return $this->configuracionDeCategoria($slug);
    }

    public function guardarConfiguracionDeProducto(string $sku, array $configuracion): array
    {
        $producto = ProductoModelo::query()->where('sku', $sku)->first() ?? throw CatalogoNoEncontrado::producto($sku);
        if (! $producto->uses_own_configurator) {
            throw new ConfiguradorEnUso('Este producto usa la configuración de su categoría. Activa «configuración propia» para editar la de este producto.');
        }
        DB::transaction(fn () => $this->guardar($this->configuracion('product_id', $producto->id), $configuracion));

        return $this->configuracionDeProducto($sku);
    }

    public function cambiarModoDelProducto(string $sku, bool $propia, bool $copiarDeLaCategoria): array
    {
        $producto = ProductoModelo::query()->where('sku', $sku)->first() ?? throw CatalogoNoEncontrado::producto($sku);

        DB::transaction(function () use ($producto, $propia, $copiarDeLaCategoria): void {
            $producto->update(['uses_own_configurator' => $propia]);

            // Al pasar a "propia" por primera vez se puede partir de lo que ya tenía la categoría.
            if ($propia && $copiarDeLaCategoria && $this->idDeConfiguracion('product_id', $producto->id) === null) {
                $deLaCategoria = $this->idDeConfiguracion('category_id', $producto->category_id);
                if ($deLaCategoria !== null) {
                    $this->guardar($this->configuracion('product_id', $producto->id), $this->armar($deLaCategoria));
                }
            }
        });

        return $this->configuracionDeProducto($sku);
    }

    public function paraElCliente(string $slugDelProducto): array
    {
        $producto = ProductoModelo::query()->where('slug', $slugDelProducto)->where('is_active', true)->first() ?? throw CatalogoNoEncontrado::producto($slugDelProducto);
        $propia = (bool) $producto->uses_own_configurator;
        $id = $propia
            ? $this->idDeConfiguracion('product_id', $producto->id)
            : $this->idDeConfiguracion('category_id', $producto->category_id);

        $armada = $this->armar($id);
        $marcados = AccesorioModelo::query()->with('categoria')->whereIn('id', $armada['accessory_ids'])->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get();

        return [
            'source' => $propia ? 'product' : 'category',
            'configured' => $id !== null,
            'locations' => $armada['locations'],
            'accessories' => $marcados->map(function (AccesorioModelo $a): array {
                $f = $this->formatoAccesorio($a);

                return ['id' => $f['id'], 'name' => $f['name'], 'type' => $f['type'], 'behavior' => $f['behavior'], 'text' => $f['text'], 'category' => $f['category'], 'image' => $f['image'], 'thumb' => $f['thumb'], 'alt' => $f['alt']];
            })->values()->all(),
            'exceptions' => $armada['exceptions'],
        ];
    }

    private function idDeConfiguracion(string $columna, int $id): ?int
    {
        $valor = ConfiguracionModelo::query()->where($columna, $id)->value('id');

        return $valor === null ? null : (int) $valor;
    }

    private function configuracion(string $columna, int $id): int
    {
        return $this->idDeConfiguracion($columna, $id) ?? (int) ConfiguracionModelo::query()->create([$columna => $id])->id;
    }

    /**
     * La configuración con todas las ubicaciones del sistema (las que nunca se configuraron salen libres y sin valores),
     * los accesorios marcados y las excepciones.
     *
     * @return array{locations: list<array<string, mixed>>, accessory_ids: list<int>, exceptions: list<array<string, mixed>>}
     */
    private function armar(?int $configId): array
    {
        $propias = $configId === null ? collect() : DB::table('configurator_config_locations')->where('config_id', $configId)->get()->keyBy('location_id');

        $ubicaciones = [];
        $i = 0;
        foreach ($this->todasLasUbicaciones() as $u) {
            $fila = $propias->get($u['id']);
            $i++;
            $ubicaciones[] = [
                'location_id' => $u['id'],
                'name' => $u['name'],
                'allowed_categories' => $u['allowed_categories'],
                'excluded_categories' => $u['excluded_categories'],
                'state' => $fila->state ?? 'libre',
                'priority' => (int) ($fila->priority ?? $i),
                'layer' => (int) ($fila->layer ?? 1),
                'fixed_accessory_id' => isset($fila->fixed_accessory_id) ? (int) $fila->fixed_accessory_id : null,
                'preset' => $fila !== null && $fila->x !== null ? $this->geometria($fila) : null,
            ];
        }

        $accesorios = $configId === null ? [] : DB::table('configurator_config_accessories')->where('config_id', $configId)->orderBy('accessory_id')->pluck('accessory_id')->map(fn ($v): int => (int) $v)->all();
        $excepciones = $configId === null ? [] : DB::table('configurator_config_exceptions')->where('config_id', $configId)->orderBy('id')->get()
            ->map(fn ($e): array => ['accessory_id' => (int) $e->accessory_id, 'location_id' => (int) $e->location_id] + $this->geometria($e))->all();

        return ['locations' => $ubicaciones, 'accessory_ids' => $accesorios, 'exceptions' => $excepciones];
    }

    /** @return array<string, float|bool> */
    private function geometria(object $fila): array
    {
        $g = [];
        foreach (self::GEOMETRIA as $campo) {
            $g[$campo] = (float) $fila->{$campo};
        }
        $g['mirror'] = (bool) $fila->mirror;

        return $g;
    }

    /**
     * Reemplaza todo el contenido de la configuración por lo recibido.
     *
     * @param  array<string, mixed>  $c  locations, accessory_ids, exceptions
     */
    private function guardar(int $configId, array $c): void
    {
        DB::table('configurator_config_locations')->where('config_id', $configId)->delete();
        DB::table('configurator_config_accessories')->where('config_id', $configId)->delete();
        DB::table('configurator_config_exceptions')->where('config_id', $configId)->delete();

        foreach ($c['locations'] as $u) {
            $fila = [
                'config_id' => $configId,
                'location_id' => $u['location_id'],
                'state' => $u['state'],
                'priority' => $u['priority'],
                'layer' => $u['layer'],
                'fixed_accessory_id' => $u['state'] === 'fija' ? ($u['fixed_accessory_id'] ?? null) : null,
            ];
            $fila += ($u['preset'] ?? null) === null
                ? ['x' => null, 'y' => null, 'width' => null, 'height' => null, 'rot_x' => 0, 'rot_y' => 0, 'rot_z' => 0, 'mirror' => false]
                : $this->filaDeGeometria($u['preset']);
            DB::table('configurator_config_locations')->insert($fila);
        }

        $accesorios = array_values(array_unique($c['accessory_ids']));
        if ($accesorios !== []) {
            DB::table('configurator_config_accessories')->insert(array_map(fn (int $id): array => ['config_id' => $configId, 'accessory_id' => $id], $accesorios));
        }

        foreach ($c['exceptions'] as $e) {
            DB::table('configurator_config_exceptions')->insert(
                ['config_id' => $configId, 'accessory_id' => $e['accessory_id'], 'location_id' => $e['location_id']] + $this->filaDeGeometria($e),
            );
        }

        ConfiguracionModelo::query()->whereKey($configId)->update(['updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $g
     * @return array<string, mixed>
     */
    private function filaDeGeometria(array $g): array
    {
        $fila = [];
        foreach (self::GEOMETRIA as $campo) {
            $fila[$campo] = $g[$campo] ?? 0;
        }
        $fila['mirror'] = (bool) ($g['mirror'] ?? false);

        return $fila;
    }
}
