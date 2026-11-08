<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Entidades;

final readonly class Campana
{
    /** @param list<Producto> $productos */
    public function __construct(
        public string $slug,
        public string $nombre,
        public string $estado,
        public int $anioTemporada,
        public string $fechaEvento,
        public string $categoriaRelacionadaSlug,
        public string $categoriaRelacionadaNombre,
        public ?string $lema,
        public ?string $eslogan,
        public array $productos,
        public ?string $seoTitulo,
        public ?string $seoDescripcion,
    ) {}
}
