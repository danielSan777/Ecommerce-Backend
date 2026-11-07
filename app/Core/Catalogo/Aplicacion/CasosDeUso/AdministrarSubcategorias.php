<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\Excepciones\SubcategoriaConProductos;
use App\Core\Catalogo\Dominio\Repositorios\RepositorioAdministracionSubcategorias;

/** Listar, crear, editar y eliminar subcategorías de una categoría. Con productos no se elimina: se informa cuáles son. */
final class AdministrarSubcategorias
{
    public function __construct(private readonly RepositorioAdministracionSubcategorias $subcategorias) {}

    /** @return list<array<string, mixed>> */
    public function listar(string $categoria): array
    {
        return $this->subcategorias->listar($categoria);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function crear(string $categoria, array $datos): array
    {
        return $this->subcategorias->crear($categoria, $datos);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function actualizar(string $categoria, string $slug, array $datos): array
    {
        return $this->subcategorias->actualizar($categoria, $slug, $datos);
    }

    public function eliminar(string $categoria, string $slug): void
    {
        $productos = $this->subcategorias->productos($categoria, $slug);

        if ($productos !== []) {
            throw new SubcategoriaConProductos($slug, count($productos), array_slice($productos, 0, 50));
        }

        $this->subcategorias->eliminar($categoria, $slug);
    }
}
