<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\Repositorios\RepositorioAdministracionCampanas;
use App\Core\Catalogo\Dominio\Servicios\TemporadaDeCampana;
use App\Shared\Dominio\BusinessCalendar;
use Illuminate\Support\Str;

/** Listar, ver, crear, editar y eliminar campañas de temporada desde el panel. */
final class AdministrarCampanas
{
    public function __construct(
        private readonly RepositorioAdministracionCampanas $campanas,
        private readonly TemporadaDeCampana $temporada,
        private readonly BusinessCalendar $calendario,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return array_map($this->conTemporada(...), $this->campanas->listar());
    }

    /** @return array<string, mixed> */
    public function obtener(string $slug): array
    {
        return $this->conTemporada($this->campanas->obtener($slug));
    }

    /**
     * La dirección (slug) se genera del nombre y nunca cambia: la URL de una campaña es permanente y no lleva año.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function crear(array $datos): array
    {
        $datos['slug'] = Str::slug((string) $datos['name']);

        return $this->conTemporada($this->campanas->crear($datos));
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function actualizar(string $slug, array $datos): array
    {
        return $this->conTemporada($this->campanas->actualizar($slug, $datos));
    }

    public function eliminar(string $slug): void
    {
        $this->campanas->eliminar($slug);
    }

    /**
     * Cómo se ve hoy la campaña (la calcula Laravel con la fecha de Guayaquil): en temporada o no, y de qué año.
     *
     * @param  array<string, mixed>  $campana
     * @return array<string, mixed>
     */
    private function conTemporada(array $campana): array
    {
        $t = $this->temporada->calcular($campana['start_md'], $campana['end_md'], $campana['event_md'], $this->calendario->hoy());

        return [...$campana, 'state' => $t['estado'], 'season_year' => $t['anio']];
    }
}
