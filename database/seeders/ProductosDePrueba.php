<?php

namespace Database\Seeders;

use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\ProductoModelo;
use Illuminate\Database\Seeder;

/**
 * SOLO PARA DESARROLLO Y PRUEBAS. Productos de ejemplo (los mismos que usa hoy Angular). Los productos reales
 * los carga Josué desde el panel. No ejecutar en producción.
 */
class ProductosDePrueba extends Seeder
{
    private const INCLUYE = ['Plantita natural', 'Dedicatoria personalizada', 'Funda kraft JDS', 'Guía de cuidados', 'Piedritas de repuesto'];

    /** slug => [nombre, precio en centavos, categoría, subcategoría] */
    private const PRODUCTOS = [
        'amigo-guitarrista' => ['Amigo guitarrista', 2000, 'amistad', null],
        'bob-corazon-hombre' => ['Bob corazón Hombre', 2000, 'amor', null],
        'baby-garfield' => ['Baby Garfield', 2200, 'amistad', null],
        'bellota-chicas-super-poderosas' => ['Bellota Chicas super poderosas', 2000, 'amistad', null],
        'abogada' => ['Abogada', 2100, 'profesiones-y-hobbies', 'administrativos'],
        'abogado' => ['Abogado', 2100, 'profesiones-y-hobbies', 'administrativos'],
        'doctor' => ['Doctor', 2100, 'profesiones-y-hobbies', 'salud'],
        'enfermera' => ['Enfermera', 2100, 'profesiones-y-hobbies', 'salud'],
        'homero' => ['Homero', 2200, 'familia', null],
        'lucas-con-pantalon-personalizado' => ['Lucas con pantalón, personalizado', 2200, 'amistad', null],
    ];

    public function run(): void
    {
        $this->call(CategoriasIniciales::class);

        foreach (self::PRODUCTOS as $slug => [$nombre, $centavos, $categoriaSlug, $subSlug]) {
            $categoria = CategoriaModelo::query()->where('slug', $categoriaSlug)->firstOrFail();
            $subcategoria = $subSlug ? $categoria->subcategorias()->where('slug', $subSlug)->first() : null;

            ProductoModelo::query()->firstOrCreate(['slug' => $slug], [
                'sku' => 'PRO-'.strtoupper($slug),
                'type' => 'personalizable',
                'name' => $nombre,
                'category_id' => $categoria->id,
                'subcategory_id' => $subcategoria?->id,
                'base_price_cents' => $centavos,
                'size_text' => 'Base 14 × 7 cm',
                'material_text' => 'Impresa en 3D con PLA Plus',
                'includes' => self::INCLUYE,
            ]);
        }
    }
}
