<?php

declare(strict_types=1);

namespace App\Core\Configurador\Dominio\Repositorios;

/**
 * Todo lo del configurador sale de la base de datos. Los datos viajan como arreglos con los nombres de la API
 * (en inglés); Laravel valida las reglas y Angular solo representa.
 */
interface RepositorioConfigurador
{
    /** @return list<array<string, mixed>> */
    public function categoriasDeAccesorios(): array;

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function crearCategoriaDeAccesorio(array $datos): array;

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function actualizarCategoriaDeAccesorio(int $id, array $datos): array;

    public function eliminarCategoriaDeAccesorio(int $id): void;

    /** @return list<array<string, mixed>> */
    public function accesorios(): array;

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function crearAccesorio(array $datos): array;

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function actualizarAccesorio(int $id, array $datos): array;

    /** Rutas de imagen actuales del accesorio (para borrar los archivos al reemplazarlos o eliminarlo). @return list<string> */
    public function rutasDeImagenDelAccesorio(int $id): array;

    /**
     * @param  array<int|string, string>  $variantes  ancho => ruta relativa
     * @return array<string, mixed>
     */
    public function guardarImagenDelAccesorio(int $id, array $variantes, string $alt): array;

    public function eliminarAccesorio(int $id): void;

    /** @return list<array<string, mixed>> */
    public function ubicaciones(): array;

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function crearUbicacion(array $datos): array;

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function actualizarUbicacion(int $id, array $datos): array;

    public function eliminarUbicacion(int $id): void;

    /** @return array<string, mixed> */
    public function configuracionDeCategoria(string $slug): array;

    /** @return array<string, mixed> */
    public function configuracionDeProducto(string $sku): array;

    /**
     * @param  array<string, mixed>  $configuracion
     * @return array<string, mixed>
     */
    public function guardarConfiguracionDeCategoria(string $slug, array $configuracion): array;

    /**
     * @param  array<string, mixed>  $configuracion
     * @return array<string, mixed>
     */
    public function guardarConfiguracionDeProducto(string $sku, array $configuracion): array;

    /** @return array<string, mixed> */
    public function cambiarModoDelProducto(string $sku, bool $propia, bool $copiarDeLaCategoria): array;

    /** Categoría y producto que el administrador estaba configurando (null si no hay o ya no existen). @return array{category_slug: ?string, product_sku: ?string} */
    public function ultimaEdicion(int $idAdministrador): array;

    /** Recuerda lo que el administrador está configurando; el producto debe ser de esa categoría. */
    public function recordarUltimaEdicion(int $idAdministrador, ?string $slugDeCategoria, ?string $sku): void;

    /** Lo que ve el cliente: la configuración propia del producto o la de su categoría. @return array<string, mixed> */
    public function paraElCliente(string $slugDelProducto): array;

    /** Rutas de la imagen para personalizar del producto (también comprueba que exista). @return list<string> */
    public function rutasDeImagenParaPersonalizar(string $sku): array;

    /**
     * Guarda (o quita, con null) la imagen para personalizar del producto.
     *
     * @param  array<string, string>|null  $variantes
     * @return array<string, mixed>
     */
    public function guardarImagenParaPersonalizar(string $sku, ?array $variantes): array;

    /**
     * Ajusta el encuadre de la imagen para personalizar del producto: escala (%) y desplazamiento (% del visor).
     *
     * @return array<string, mixed> la configuración del producto
     */
    public function guardarAjusteDeImagen(string $sku, float $escala, float $x, float $y): array;

    /**
     * Elige (o quita, con null) el accesorio propio del producto en una ubicación de la configuración de su categoría.
     *
     * @return array<string, mixed> la configuración del producto
     */
    public function guardarAccesorioFijoDelProducto(string $sku, int $idUbicacion, ?int $idAccesorio): array;
}
