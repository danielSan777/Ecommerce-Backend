<?php

declare(strict_types=1);

namespace App\Core\Configurador\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\Excepciones\ImagenInvalida;
use App\Core\Configurador\Dominio\Repositorios\RepositorioConfigurador;
use App\Core\Configurador\Infraestructura\Servicios\ProcesadorDeAccesorios;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Categorías de accesorios y accesorios (con su imagen, que es un archivo: en la base solo va la ruta relativa). */
final class AdministrarAccesorios
{
    public function __construct(
        private readonly RepositorioConfigurador $configurador,
        private readonly ProcesadorDeAccesorios $imagenes,
    ) {}

    /** @return list<array<string, mixed>> */
    public function categorias(): array
    {
        return $this->configurador->categoriasDeAccesorios();
    }

    /** @param array<string, mixed> $datos @return array<string, mixed> */
    public function crearCategoria(array $datos): array
    {
        return $this->configurador->crearCategoriaDeAccesorio($datos);
    }

    /** @param array<string, mixed> $datos @return array<string, mixed> */
    public function actualizarCategoria(int $id, array $datos): array
    {
        return $this->configurador->actualizarCategoriaDeAccesorio($id, $datos);
    }

    public function eliminarCategoria(int $id): void
    {
        $this->configurador->eliminarCategoriaDeAccesorio($id);
    }

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return $this->configurador->accesorios();
    }

    /** @param array<string, mixed> $datos @return array<string, mixed> */
    public function crear(array $datos): array
    {
        return $this->configurador->crearAccesorio($this->limpiar($datos));
    }

    /** @param array<string, mixed> $datos @return array<string, mixed> */
    public function actualizar(int $id, array $datos): array
    {
        return $this->configurador->actualizarAccesorio($id, $this->limpiar($datos));
    }

    /** @throws ImagenInvalida */
    public function subirImagen(int $id, string $contenido, string $alt): array
    {
        $anteriores = $this->configurador->rutasDeImagenDelAccesorio($id); // también comprueba que el accesorio exista
        $versiones = $this->imagenes->procesar($contenido);

        $base = 'accessories/'.$id.'-'.Str::lower(Str::random(6));
        $rutas = [];
        foreach ($versiones as $ancho => $webp) {
            $ruta = "{$base}-{$ancho}.webp";
            Storage::disk('media')->put($ruta, $webp);
            $rutas[(string) $ancho] = $ruta;
        }

        $accesorio = $this->configurador->guardarImagenDelAccesorio($id, $rutas, $alt);
        Storage::disk('media')->delete($anteriores);

        return $accesorio;
    }

    public function eliminar(int $id): void
    {
        $rutas = $this->configurador->rutasDeImagenDelAccesorio($id);
        $this->configurador->eliminarAccesorio($id);
        Storage::disk('media')->delete($rutas);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function limpiar(array $datos): array
    {
        // El texto por defecto solo tiene sentido en un accesorio de tipo texto
        if (($datos['type'] ?? null) === 'imagen') {
            $datos['default_text'] = null;
        }

        return $datos;
    }
}
