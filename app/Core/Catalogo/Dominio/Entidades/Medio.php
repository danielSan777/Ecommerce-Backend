<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Entidades;

/** Imagen o video ya procesado; las URLs vienen armadas desde la ruta relativa guardada. */
final readonly class Medio
{
    /** @param array<string, string> $urls  imagen: ancho => url ("600", "1080"); video: "url" y "poster" */
    public function __construct(
        public string $tipo,
        public string $rol,
        public array $urls,
        public ?string $alt = null,
        public ?int $ancho = null,
        public ?int $alto = null,
        public ?int $duracionSegundos = null,
    ) {}
}
