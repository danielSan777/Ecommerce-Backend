<?php

namespace Database\Seeders;

use App\Core\Catalogo\Infraestructura\Modelos\CampanaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use Illuminate\Database\Seeder;

/** San Valentín, con su URL permanente. Las fechas anuales son editables (valores iniciales de referencia). */
class CampanasIniciales extends Seeder
{
    public function run(): void
    {
        $this->call(CategoriasIniciales::class);

        CampanaModelo::query()->firstOrCreate(['slug' => 'san-valentin'], [
            'name' => 'San Valentín',
            'start_md' => '01-02',
            'end_md' => '02-14',
            'event_md' => '02-14',
            'tagline' => 'Un regalo con su nombre que sigue creciendo',
            'slogan' => 'Las flores duran una semana. Esta maceta, años.',
            'seo_title' => 'San Valentín | Jardín de Sele',
            'seo_description' => 'Macetas personalizadas con su nombre para San Valentín. Un regalo que sigue creciendo.',
        ]);
    }
}
