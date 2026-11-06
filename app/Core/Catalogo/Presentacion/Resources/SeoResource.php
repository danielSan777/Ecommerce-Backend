<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Resources;

final class SeoResource
{
    /** @return array<string, mixed> */
    public static function formato(?string $titulo, ?string $descripcion, string $ruta, ?string $imagenSocial): array
    {
        return [
            'title' => $titulo,
            'description' => $descripcion,
            'canonical' => rtrim((string) config('app.frontend_url'), '/').$ruta,
            'og_image' => $imagenSocial,
        ];
    }
}
