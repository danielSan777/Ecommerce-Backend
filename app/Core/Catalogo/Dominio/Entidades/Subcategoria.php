<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Entidades;

final readonly class Subcategoria
{
    public function __construct(public string $slug, public string $nombre) {}
}
