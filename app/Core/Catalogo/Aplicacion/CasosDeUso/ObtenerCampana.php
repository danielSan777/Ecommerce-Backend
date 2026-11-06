<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\Entidades\Campana;
use App\Core\Catalogo\Dominio\Repositorios\RepositorioCampanas;
use App\Shared\Dominio\BusinessCalendar;

final class ObtenerCampana
{
    public function __construct(private readonly RepositorioCampanas $campanas, private readonly BusinessCalendar $calendario) {}

    public function __invoke(string $slug): Campana
    {
        return $this->campanas->campana($slug, $this->calendario->hoy());
    }
}
