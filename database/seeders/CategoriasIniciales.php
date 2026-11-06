<?php

namespace Database\Seeders;

use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\SubcategoriaModelo;
use Illuminate\Database\Seeder;

/**
 * Categorías y subcategorías iniciales del documento maestro (Paso 5). Idempotente: no pisa lo que Josué ya editó.
 */
class CategoriasIniciales extends Seeder
{
    private const CATEGORIAS = [
        'profesiones-y-hobbies' => ['Profesiones y Hobbies', ['salud' => 'Salud', 'administrativos' => 'Administrativos', 'fuerza-publica' => 'Fuerza Pública', 'cocina-y-hobbies' => 'Cocina y Hobbies']],
        'amor' => ['Amor', []],
        'amistad' => ['Amistad', []],
        'familia' => ['Familia', []],
    ];

    public function run(): void
    {
        $orden = 0;
        foreach (self::CATEGORIAS as $slug => [$nombre, $subcategorias]) {
            $categoria = CategoriaModelo::query()->firstOrCreate(['slug' => $slug], ['name' => $nombre, 'sort_order' => ++$orden]);

            $ordenSub = 0;
            foreach ($subcategorias as $subSlug => $subNombre) {
                SubcategoriaModelo::query()->firstOrCreate(
                    ['category_id' => $categoria->id, 'slug' => $subSlug],
                    ['name' => $subNombre, 'sort_order' => ++$ordenSub],
                );
            }
        }
    }
}
