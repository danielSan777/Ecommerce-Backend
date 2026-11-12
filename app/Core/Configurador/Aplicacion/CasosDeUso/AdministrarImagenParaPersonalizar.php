<?php

declare(strict_types=1);

namespace App\Core\Configurador\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\Excepciones\ImagenInvalida;
use App\Core\Configurador\Dominio\Repositorios\RepositorioConfigurador;
use App\Core\Configurador\Infraestructura\Servicios\ProcesadorDeAccesorios;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Imagen para personalizar de cada producto (la foto base del configurador). Es un archivo: en la base solo va la ruta. */
final class AdministrarImagenParaPersonalizar
{
    public function __construct(
        private readonly RepositorioConfigurador $configurador,
        private readonly ProcesadorDeAccesorios $imagenes,
    ) {}

    /**
     * @return array<string, mixed> la configuración del producto, con su `base_image`
     *
     * @throws ImagenInvalida
     */
    public function subir(string $sku, string $contenido): array
    {
        $anteriores = $this->configurador->rutasDeImagenParaPersonalizar($sku); // también comprueba que el producto exista
        $versiones = $this->imagenes->procesar($contenido);

        $base = 'personalization/'.Str::slug($sku).'-'.Str::lower(Str::random(6));
        $rutas = [];
        foreach ($versiones as $ancho => $webp) {
            $ruta = "{$base}-{$ancho}.webp";
            Storage::disk('media')->put($ruta, $webp);
            $rutas[(string) $ancho] = $ruta;
        }

        $resultado = $this->configurador->guardarImagenParaPersonalizar($sku, $rutas);
        Storage::disk('media')->delete($anteriores);

        return $resultado;
    }

    /** @return array<string, mixed> */
    public function quitar(string $sku): array
    {
        $anteriores = $this->configurador->rutasDeImagenParaPersonalizar($sku);
        $resultado = $this->configurador->guardarImagenParaPersonalizar($sku, null);
        Storage::disk('media')->delete($anteriores);

        return $resultado;
    }
}
