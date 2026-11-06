<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Entidades;

use App\Core\Catalogo\Dominio\ValueObjects\Precio;

final readonly class Producto
{
    /**
     * @param  list<string>  $incluye
     * @param  list<Medio>  $medios
     * @param  list<string>  $skusRelacionados
     */
    public function __construct(
        public string $sku,
        public string $slug,
        public string $tipo,
        public string $nombre,
        public string $categoriaSlug,
        public string $categoriaNombre,
        public ?Subcategoria $subcategoria,
        public bool $disponible,
        public Precio $precio,
        public ?string $tamano,
        public ?string $material,
        public array $incluye,
        public ?string $descripcionHtml,
        public array $medios,
        public array $skusRelacionados,
        public ?string $seoTitulo,
        public ?string $seoDescripcion,
    ) {}

    /** Imagen principal para listados (la de rol "front", o la primera imagen). */
    public function imagenPrincipal(): ?string
    {
        foreach ($this->medios as $medio) {
            if ($medio->tipo === 'image' && $medio->rol === 'front') {
                return $medio->urls[array_key_last($medio->urls)] ?? null;
            }
        }
        foreach ($this->medios as $medio) {
            if ($medio->tipo === 'image') {
                return $medio->urls[array_key_last($medio->urls)] ?? null;
            }
        }

        return null;
    }
}
