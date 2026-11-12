<?php

declare(strict_types=1);

namespace App\Core\Configurador\Aplicacion\CasosDeUso;

use App\Core\Configurador\Dominio\ReglasDeConfiguracion;
use App\Core\Configurador\Dominio\Repositorios\RepositorioConfigurador;

/**
 * Configuración del configurador por categoría de producto (la usan todos sus productos) o por producto
 * (cuando el administrador marca que ese producto tiene la suya propia).
 */
final class AdministrarConfiguracion
{
    public function __construct(private readonly RepositorioConfigurador $configurador) {}

    /** @return array{category_slug: ?string, product_sku: ?string} */
    public function ultimaEdicion(int $idAdministrador): array
    {
        return $this->configurador->ultimaEdicion($idAdministrador);
    }

    public function recordarUltimaEdicion(int $idAdministrador, ?string $slugDeCategoria, ?string $sku): void
    {
        $this->configurador->recordarUltimaEdicion($idAdministrador, $slugDeCategoria, $sku);
    }

    /** @return array<string, mixed> */
    public function deCategoria(string $slug): array
    {
        return $this->configurador->configuracionDeCategoria($slug);
    }

    /** @return array<string, mixed> */
    public function deProducto(string $sku): array
    {
        return $this->configurador->configuracionDeProducto($sku);
    }

    /** @param array<string, mixed> $configuracion @return array<string, mixed> */
    public function guardarDeCategoria(string $slug, array $configuracion): array
    {
        $this->validar($configuracion);

        return $this->configurador->guardarConfiguracionDeCategoria($slug, $configuracion);
    }

    /** @param array<string, mixed> $configuracion @return array<string, mixed> */
    public function guardarDeProducto(string $sku, array $configuracion): array
    {
        $this->validar($configuracion);

        return $this->configurador->guardarConfiguracionDeProducto($sku, $configuracion);
    }

    /** @return array<string, mixed> */
    public function cambiarModo(string $sku, bool $propia, bool $copiarDeLaCategoria): array
    {
        return $this->configurador->cambiarModoDelProducto($sku, $propia, $copiarDeLaCategoria);
    }

    /** @return array<string, mixed> */
    public function ajustarImagen(string $sku, float $escala, float $x, float $y): array
    {
        return $this->configurador->guardarAjusteDeImagen($sku, $escala, $x, $y);
    }

    /** @param array<string, mixed> $configuracion */
    private function validar(array $configuracion): void
    {
        ReglasDeConfiguracion::validar($configuracion['locations'], $configuracion['accessory_ids'], $configuracion['exceptions']);
    }
}
