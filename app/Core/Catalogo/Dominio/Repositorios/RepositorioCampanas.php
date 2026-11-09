<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Repositorios;

use App\Core\Catalogo\Dominio\Entidades\Campana;
use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;

interface RepositorioCampanas
{
    /** @throws CatalogoNoEncontrado */
    public function campana(string $slug, string $hoy): Campana;

    /** La campaña activa que está en temporada hoy (si hay varias, la del evento más próximo), o null. */
    public function vigente(string $hoy): ?Campana;
}
