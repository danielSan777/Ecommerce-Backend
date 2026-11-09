<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\Entidades\Campana;
use App\Core\Catalogo\Dominio\Repositorios\RepositorioCampanas;
use App\Shared\Dominio\BusinessCalendar;

/** La campaña que está en temporada hoy (para el banner de la página principal), o null si no hay ninguna. */
final class ObtenerCampanaVigente
{
    public function __construct(private readonly RepositorioCampanas $campanas, private readonly BusinessCalendar $calendario) {}

    public function __invoke(): ?Campana
    {
        return $this->campanas->vigente($this->calendario->hoy());
    }
}
