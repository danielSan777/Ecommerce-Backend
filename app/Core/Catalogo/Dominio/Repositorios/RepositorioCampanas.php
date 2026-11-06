<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Repositorios;

use App\Core\Catalogo\Dominio\Entidades\Campana;
use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;

interface RepositorioCampanas
{
    /** @throws CatalogoNoEncontrado */
    public function campana(string $slug, string $hoy): Campana;
}
