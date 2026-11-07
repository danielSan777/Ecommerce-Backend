<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Aplicacion\CasosDeUso;

use App\Core\Catalogo\Dominio\Repositorios\RepositorioAdministracionCatalogo;
use Illuminate\Support\Str;

/** Listar, crear y editar categorías desde el panel. (Eliminar tiene su propio caso de uso por su regla.) */
final class AdministrarCategorias
{
    public function __construct(private readonly RepositorioAdministracionCatalogo $catalogo) {}

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return $this->catalogo->listarCategorias();
    }

    /**
     * El identificador (slug) de la URL se genera del nombre (espacios como "_") y nunca cambia después.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function crear(array $datos): array
    {
        $datos['slug'] = $datos['slug'] ?? Str::slug((string) $datos['name'], '_');

        return $this->catalogo->crearCategoria($datos);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function actualizar(string $slug, array $datos): array
    {
        return $this->catalogo->actualizarCategoria($slug, $datos);
    }
}
