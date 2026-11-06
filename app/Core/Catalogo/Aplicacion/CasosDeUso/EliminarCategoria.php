<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\Excepciones\CategoriaConProductos;
use App\Core\Catalogo\Dominio\Repositorios\RepositorioAdministracionCatalogo;

/** Elimina una categoría solo si no tiene productos; si los tiene, informa cuáles para que Angular los muestre. */
final class EliminarCategoria
{
    public function __construct(private readonly RepositorioAdministracionCatalogo $catalogo) {}

    public function __invoke(string $slug): void
    {
        $productos = $this->catalogo->productosDeCategoria($slug);

        if ($productos !== []) {
            throw new CategoriaConProductos($slug, count($productos), array_slice($productos, 0, 50));
        }

        $this->catalogo->eliminarCategoria($slug);
    }
}
