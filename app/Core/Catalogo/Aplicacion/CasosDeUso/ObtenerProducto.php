<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\Entidades\Producto;
use App\Core\Catalogo\Dominio\Repositorios\RepositorioCatalogo;

final class ObtenerProducto
{
    public function __construct(private readonly RepositorioCatalogo $catalogo) {}

    public function __invoke(string $slug): Producto
    {
        return $this->catalogo->producto($slug);
    }
}
