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
use Illuminate\Support\Facades\Storage;

final class RepositorioCampanasEloquent implements RepositorioCampanas
{
    public function __construct(private readonly RepositorioCatalogo $catalogo, private readonly TemporadaDeCampana $temporada) {}

    public function campana(string $slug, string $hoy): Campana
    {
        $modelo = CampanaModelo::query()->where('is_active', true)->where('slug', $slug)->with(['categoriaRelacionada', 'medios'])->first()
            ?? throw CatalogoNoEncontrado::campana($slug);

        return $this->armar($modelo, $hoy);
    }

    public function vistaPrevia(string $slug, string $hoy): Campana
    {
        $modelo = CampanaModelo::query()->where('slug', $slug)->with(['categoriaRelacionada', 'medios'])->first()
            ?? throw CatalogoNoEncontrado::campana($slug);

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

        return $elegida ? $this->armar($elegida->load(['categoriaRelacionada', 'medios']), $hoy) : null;
    }

    private function armar(CampanaModelo $modelo, string $hoy, bool $enTemporada = false): Campana
    {
        $t = $this->temporada->calcular($modelo->start_md, $modelo->end_md, $modelo->event_md, $hoy, $modelo->closing_until_md);
        if ($enTemporada) {
            $t['estado'] = 'active'; // vista previa del administrador: se ve como en temporada
        }

        // En temporada: los productos que eligió Josué, en su orden. Fuera de temporada: los de la categoría relacionada.
        // Mientras nadie haya elegido productos, la temporada tampoco queda vacía: se muestran los de la categoría relacionada.
        $elegidos = $t['estado'] === 'active'
            ? $modelo->productos()->where('is_active', true)->pluck('slug')->map(fn (string $s) => $this->catalogo->producto($s))->all()
            : [];
        $productos = $elegidos !== [] ? $elegidos : $this->catalogo->categoria($modelo->categoriaRelacionada->slug)->productos;

        return new Campana(
            slug: $modelo->slug,
            nombre: $modelo->name,
            estado: $t['estado'],
            anioTemporada: $t['anio'],
            fechaEvento: $t['fechaEvento'],
            categoriaRelacionadaSlug: $modelo->categoriaRelacionada->slug,
            categoriaRelacionadaNombre: $modelo->categoriaRelacionada->name,
            lema: $modelo->tagline,
            eslogan: $modelo->slogan,
            productos: array_values($productos),
            seoTitulo: $modelo->seo_title,
            seoDescripcion: $modelo->seo_description,
            colorTema: $modelo->theme_color,
            emoji: $modelo->emoji,
            portada: ($m = $modelo->medios->firstWhere('type', 'image')) ? $this->medio($m) : null,
            video: ($v = $modelo->medios->firstWhere('type', 'video')) ? $this->medio($v) : null,
            mensajeDeCierre: $t['estado'] === 'closing' ? $modelo->closing_message : null,
        );
    }

    private function medio(MedioModelo $m): Medio
    {
        $disco = Storage::disk($m->disk);

        return new Medio($m->type, $m->role, array_map(fn (string $ruta) => $disco->url($ruta), $m->variants ?? []), $m->alt, $m->width, $m->height, $m->duration_s);
    }
}
