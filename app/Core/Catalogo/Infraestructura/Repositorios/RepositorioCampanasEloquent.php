<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Infraestructura\Repositorios;

use App\Core\Catalogo\Dominio\Entidades\Campana;
use App\Core\Catalogo\Dominio\Entidades\Medio;
use App\Core\Catalogo\Dominio\Excepciones\CatalogoNoEncontrado;
use App\Core\Catalogo\Dominio\Repositorios\RepositorioCampanas;
use App\Core\Catalogo\Dominio\Repositorios\RepositorioCatalogo;
use App\Core\Catalogo\Dominio\Servicios\TemporadaDeCampana;
use App\Core\Catalogo\Infraestructura\Modelos\CampanaModelo;
use App\Core\Catalogo\Infraestructura\Modelos\MedioModelo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class RepositorioCampanasEloquent implements RepositorioCampanas
{
    public function __construct(private readonly RepositorioCatalogo $catalogo, private readonly TemporadaDeCampana $temporada) {}

    public function campana(string $slug, string $hoy): Campana
    {
        $modelo = $this->buscar($slug, soloActivas: true) ?? throw CatalogoNoEncontrado::campana($slug);

        return $this->armar($modelo, $hoy);
    }

    /** Por su dirección actual o, si tuvo otra antes, por la anterior (la respuesta trae la actual y Angular redirige). */
    private function buscar(string $slug, bool $soloActivas): ?CampanaModelo
    {
        $consulta = fn () => CampanaModelo::query()->when($soloActivas, fn ($q) => $q->where('is_active', true))->with(['medios']);

        return $consulta()->where('slug', $slug)->first()
            ?? $consulta()->whereIn('id', DB::table('campaign_slug_redirects')->where('slug', $slug)->select('campaign_id'))->first();
    }

    public function vistaPrevia(string $slug, string $hoy): Campana
    {
        $modelo = $this->buscar($slug, soloActivas: false) ?? throw CatalogoNoEncontrado::campana($slug);

        return $this->armar($modelo, $hoy, enTemporada: true);
    }

    public function vigente(string $hoy): ?Campana
    {
        $elegida = null;
        $fecha = null;
        $despidiendose = null;
        foreach (CampanaModelo::query()->where('is_active', true)->get() as $m) {
            $t = $this->temporada->calcular($m->start_md, $m->end_md, $m->event_md, $hoy, $m->closing_until_md);
            if ($t['estado'] === 'active' && ($fecha === null || $t['fechaEvento'] < $fecha)) {
                $elegida = $m;
                $fecha = $t['fechaEvento'];
            }
            if ($t['estado'] === 'closing' && $m->closing_message) {
                $despidiendose ??= $m;
            }
        }
        // Una campaña en temporada siempre manda; si no hay ninguna, se muestra el mensaje de despedida de la que acaba de terminar
        $elegida ??= $despidiendose;

        return $elegida ? $this->armar($elegida->load(['medios']), $hoy) : null;
    }

    private function armar(CampanaModelo $modelo, string $hoy, bool $enTemporada = false): Campana
    {
        $t = $this->temporada->calcular($modelo->start_md, $modelo->end_md, $modelo->event_md, $hoy, $modelo->closing_until_md);
        if ($enTemporada) {
            $t['estado'] = 'active'; // vista previa del administrador: se ve como en temporada
        }

        // En temporada: los productos que eligió el administrador, en su orden. Fuera de temporada no se muestran productos.
        $productos = $t['estado'] === 'active'
            ? $modelo->productos()->where('is_active', true)->pluck('slug')->map(fn (string $s) => $this->catalogo->producto($s))->all()
            : [];

        return new Campana(
            slug: $modelo->slug,
            nombre: $modelo->name,
            estado: $t['estado'],
            anioTemporada: $t['anio'],
            fechaEvento: $t['fechaEvento'],
            lema: $modelo->tagline,
            eslogan: $modelo->slogan,
            productos: array_values($productos),
            seoTitulo: $modelo->seo_title,
            seoDescripcion: $modelo->seo_description,
            colorTema: $modelo->theme_color,
            emoji: $modelo->emoji,
            portada: ($m = $modelo->medios->firstWhere('type', 'image')) ? $this->medio($m) : null,
            video: ($v = $modelo->medios->firstWhere('type', 'video')) ? $this->medio($v) : null,
            mensajeDeCierre: $modelo->closing_message, // la página de la campaña lo muestra siempre que no esté en temporada
        );
    }

    private function medio(MedioModelo $m): Medio
    {
        $disco = Storage::disk($m->disk);

        return new Medio($m->type, $m->role, array_map(fn (string $ruta) => $disco->url($ruta), $m->variants ?? []), $m->alt, $m->width, $m->height, $m->duration_s);
    }
}
