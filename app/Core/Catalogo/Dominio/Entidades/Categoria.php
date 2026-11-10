<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Entidades;

final readonly class Categoria
{
    /**
     * @param  list<Subcategoria>  $subcategorias
     * @param  list<Producto>  $productos
     */
    public function __construct(
        public string $slug,
        public string $nombre,
        public ?string $titular,
        public ?string $introHtml,
        public ?Medio $portada,
        public array $subcategorias,
        public ?string $subcategoriaActiva,
        public array $productos,
        public ?int $precioDesdeCents,
        public ?string $seoTitulo,
        public ?string $seoDescripcion,
        /** @var list<Medio> */
        public array $videos = [],
    ) {}
}
