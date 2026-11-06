<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\Entidades\Categoria;
use App\Core\Catalogo\Dominio\Repositorios\RepositorioCatalogo;

final class ObtenerCategoria
{
    public function __construct(private readonly RepositorioCatalogo $catalogo) {}

    public function __invoke(string $slug, ?string $subcategoria = null): Categoria
    {
        return $this->catalogo->categoria($slug, $subcategoria);
    }
}
