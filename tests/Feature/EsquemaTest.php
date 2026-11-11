<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Todas las tablas del negocio tienen `id` como llave primaria y sus relaciones con llave foránea. */
final class EsquemaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, list<string>> tabla => columnas que deben ser llave foránea */
    private function relaciones(): array
    {
        return [
            'subcategories' => ['category_id'],
            'products' => ['category_id', 'subcategory_id'],
            'campaign_products' => ['campaign_id', 'product_id'],
            'media' => ['product_id', 'category_id'],
        ];
    }

    public function test_cada_tabla_del_negocio_tiene_id_como_llave_primaria(): void
    {
        foreach (['settings', 'categories', 'subcategories', 'products', 'media', 'campaigns', 'campaign_products'] as $tabla) {
            $this->assertTrue(Schema::hasColumn($tabla, 'id'), "{$tabla} no tiene id");
            $pk = array_filter(DB::select("PRAGMA table_info('{$tabla}')"), fn ($c) => (int) $c->pk === 1);
            $this->assertSame('id', array_values($pk)[0]->name, "{$tabla}: la llave primaria no es id");
        }
    }

    public function test_las_relaciones_tienen_llave_foranea(): void
    {
        foreach ($this->relaciones() as $tabla => $columnas) {
            $foraneas = array_map(fn ($f) => $f->from, DB::select("PRAGMA foreign_key_list('{$tabla}')"));
            foreach ($columnas as $columna) {
                $this->assertContains($columna, $foraneas, "{$tabla}.{$columna} no tiene llave foránea");
            }
        }
    }

    public function test_no_se_puede_crear_un_producto_con_una_categoria_que_no_existe(): void
    {
        $this->expectException(QueryException::class);
        DB::table('products')->insert(['uuid' => 'x', 'sku' => 'S', 'slug' => 's', 'name' => 'n', 'category_id' => 999, 'base_price_cents' => 1]);
    }
}
