<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Infraestructura\Servicios\ProcesadorDeImagenes;
use App\Core\Catalogo\Infraestructura\Servicios\ProcesadorDeVideos;
use App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion;

/** Portada de la página /categorias: igual que la de inicio (lista sin límite de imágenes y videos), guardada en `categorias_hero` y la carpeta `media/categories-page/`. */
final class AdministrarPortadaCategorias extends AdministrarPortadaInicio
{
    public function __construct(RepositorioConfiguracion $configuracion, ProcesadorDeImagenes $imagenes, ProcesadorDeVideos $videos)
    {
        parent::__construct($configuracion, $imagenes, $videos, 'categorias_hero', 'categories-page');
    }
}
