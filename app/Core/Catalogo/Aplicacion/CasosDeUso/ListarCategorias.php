<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\Entidades\Categoria;
use App\Core\Catalogo\Dominio\Repositorios\RepositorioCatalogo;

final class ListarCategorias
{
    public function __construct(private readonly RepositorioCatalogo $catalogo) {}

    /** @return list<Categoria> */
    public function __invoke(): array
    {
        return $this->catalogo->categorias();
    }
}
