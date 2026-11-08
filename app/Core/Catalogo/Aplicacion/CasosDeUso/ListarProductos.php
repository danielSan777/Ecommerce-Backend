<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\Entidades\Producto;
use App\Core\Catalogo\Dominio\Repositorios\RepositorioCatalogo;

final class ListarProductos
{
    public function __construct(private readonly RepositorioCatalogo $catalogo) {}

    /** @return list<Producto> */
    public function __invoke(?int $limite = null): array
    {
        return $this->catalogo->productos($limite);
    }
}
