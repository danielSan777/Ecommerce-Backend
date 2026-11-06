<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Resources;

use App\Core\Catalogo\Dominio\Entidades\Medio;

final class MedioResource
{
    /** @return array<string, mixed> */
    public static function formato(Medio $medio): array
    {
        if ($medio->tipo === 'video') {
            return ['type' => 'video', 'url' => $medio->urls['url'] ?? null, 'poster' => $medio->urls['poster'] ?? null, 'duration_s' => $medio->duracionSegundos];
        }

        return ['type' => 'image', 'role' => $medio->rol, 'alt' => $medio->alt, 'w' => $medio->ancho, 'h' => $medio->alto, 'srcset' => $medio->urls];
    }
}
