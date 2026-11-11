<?php

namespace Database\Seeders;

use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Core\Configurador\Aplicacion\CasosDeUso\AdministrarAccesorios;
use App\Core\Configurador\Aplicacion\CasosDeUso\AdministrarConfiguracion;
use App\Core\Configurador\Infraestructura\Modelos\AccesorioModelo;
use App\Core\Configurador\Infraestructura\Modelos\CategoriaDeAccesorioModelo;
use App\Core\Configurador\Infraestructura\Modelos\ConfiguracionModelo;
use App\Core\Configurador\Infraestructura\Modelos\UbicacionModelo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos de muestra del configurador (los del prototipo VentaPruebaApp): 5 categorías de accesorios, 5 ubicaciones con sus
 * reglas, 11 accesorios con su imagen y, si existe la categoría «profesiones-y-hobbies» y aún no tiene configuración,
 * una configuración de ejemplo. Idempotente: no pisa nada de lo que el administrador ya editó.
 *
 *   php artisan db:seed --class=ConfiguradorDeMuestra
 */
class ConfiguradorDeMuestra extends Seeder
{
    private const CATEGORIAS = ['Moda', 'Personalización', 'Objetos', 'Animales', 'Flores'];

    /** nombre => [permitidas, excluidas] */
    private const UBICACIONES = [
        'Cabeza' => [['Moda'], []],
        'Pie izquierdo' => [[], ['Moda', 'Personalización']],
        'Pie derecho' => [[], ['Moda', 'Personalización']],
        'Entre las piernas' => [[], ['Moda', 'Personalización']],
        'Base' => [['Personalización'], []],
    ];

    /** nombre => [categoría, archivo (o null si es de texto), texto alternativo o texto de ejemplo] */
    private const ACCESORIOS = [
        'Lentes' => ['Moda', 'lentes.webp', 'Lentes'],
        'Nombre' => ['Personalización', null, 'Ingrese su nombre.'],
        'Marco de foto' => ['Objetos', 'marco-foto.webp', 'Marco para una foto'],
        'Marco de frase' => ['Objetos', 'marco-frase.webp', 'Marco para una frase'],
        'Gafas' => ['Moda', 'gafas-sin-fondo.webp', 'Gafas'],
        'Caja de herramientas' => ['Objetos', 'caja_herramientas.webp', 'Caja de herramientas'],
        'Regalo' => ['Objetos', 'regalo.webp', 'Caja de regalo'],
        'Vaso de café' => ['Objetos', 'vaso_cafe.webp', 'Vaso de café'],
        'Gato amarillo' => ['Animales', 'gatito.webp', 'Gatito amarillo'],
        'Perro' => ['Animales', 'perro.webp', 'Perro'],
        'Flor' => ['Flores', 'flores.webp', 'Flores'],
    ];

    /** Ubicación => [estado, prioridad, accesorio fijo, x, y, ancho, alto] */
    private const CONFIGURACION = [
        'Cabeza' => ['libre', 1, null, 34.523, 25.160, 27.5, 9.5],
        'Pie izquierdo' => ['fija', 2, 'Regalo', 31.300, 75.259, 9.4, 9.4],
        'Pie derecho' => ['libre', 3, null, 64.132, 75.103, 11.09, 11.09],
        'Entre las piernas' => ['libre', 4, null, 47.406, 78.483, 9.5, 7.5],
        'Base' => ['libre', 5, null, 32.680, 88.294, 37.5, 5.0],
    ];

    public function run(AdministrarAccesorios $accesorios, AdministrarConfiguracion $configuracion): void
    {
        foreach (self::CATEGORIAS as $i => $nombre) {
            CategoriaDeAccesorioModelo::query()->firstOrCreate(['name' => $nombre], ['sort_order' => $i + 1]);
        }
        $categorias = CategoriaDeAccesorioModelo::query()->pluck('id', 'name');

        $i = 0;
        foreach (self::UBICACIONES as $nombre => [$permitidas, $excluidas]) {
            $ubicacion = UbicacionModelo::query()->firstOrCreate(['name' => $nombre], ['sort_order' => ++$i]);
            if ($ubicacion->wasRecentlyCreated) {
                $filas = [];
                foreach ($permitidas as $c) {
                    $filas[] = ['location_id' => $ubicacion->id, 'accessory_category_id' => $categorias[$c], 'rule' => 'allow'];
                }
                foreach ($excluidas as $c) {
                    $filas[] = ['location_id' => $ubicacion->id, 'accessory_category_id' => $categorias[$c], 'rule' => 'exclude'];
                }
                DB::table('location_category_rules')->insert($filas);
            }
        }

        $i = 0;
        foreach (self::ACCESORIOS as $nombre => [$categoria, $archivo, $texto]) {
            $accesorio = AccesorioModelo::query()->firstOrCreate(['name' => $nombre], [
                'accessory_category_id' => $categorias[$categoria],
                'type' => $archivo === null ? 'texto' : 'imagen',
                'default_text' => $archivo === null ? $texto : null,
                'sort_order' => ++$i,
            ]);
            if ($accesorio->wasRecentlyCreated && $archivo !== null) {
                $accesorios->subirImagen($accesorio->id, (string) file_get_contents(__DIR__.'/accesorios/'.$archivo), $texto);
            }
        }

        $this->configurarCategoriaDeMuestra($configuracion);
    }

    private function configurarCategoriaDeMuestra(AdministrarConfiguracion $configuracion): void
    {
        $categoria = CategoriaModelo::query()->where('slug', 'profesiones-y-hobbies')->first();
        if ($categoria === null || ConfiguracionModelo::query()->where('category_id', $categoria->id)->exists()) {
            return;
        }

        $ubicaciones = UbicacionModelo::query()->pluck('id', 'name');
        $accesorios = AccesorioModelo::query()->pluck('id', 'name');

        $filas = [];
        foreach (self::CONFIGURACION as $nombre => [$estado, $prioridad, $fijo, $x, $y, $ancho, $alto]) {
            $filas[] = [
                'location_id' => $ubicaciones[$nombre], 'state' => $estado, 'priority' => $prioridad, 'layer' => 1,
                'fixed_accessory_id' => $fijo === null ? null : $accesorios[$fijo],
                'preset' => ['x' => $x, 'y' => $y, 'width' => $ancho, 'height' => $alto, 'rot_x' => 0, 'rot_y' => 0, 'rot_z' => 0, 'mirror' => false],
            ];
        }

        $configuracion->guardarDeCategoria($categoria->slug, [
            'locations' => $filas,
            'accessory_ids' => array_values($accesorios->all()),
            // Las gafas van un poco más anchas en la cabeza que el valor de la ubicación
            'exceptions' => [['accessory_id' => $accesorios['Gafas'], 'location_id' => $ubicaciones['Cabeza'], 'x' => 36.975, 'y' => 26.541, 'width' => 23, 'height' => 9.5, 'rot_x' => 0, 'rot_y' => 0, 'rot_z' => 0, 'mirror' => false]],
        ]);
    }
}
