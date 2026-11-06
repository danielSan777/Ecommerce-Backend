<?php

declare(strict_types=1);

namespace App\Core\Configuracion\Infraestructura\Repositorios;

use App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion;
use App\Core\Configuracion\Infraestructura\Modelos\Ajuste;

final class RepositorioConfiguracionEloquent implements RepositorioConfiguracion
{
    public function obtener(string $clave, mixed $porDefecto = null): mixed
    {
        $ajuste = Ajuste::query()->where('key', $clave)->first();

        return $ajuste === null ? $porDefecto : $ajuste->value;
    }

    public function guardar(string $clave, mixed $valor): void
    {
        Ajuste::query()->updateOrCreate(['key' => $clave], ['value' => $valor]);
    }

    public function todos(): array
    {
        return Ajuste::query()->orderBy('key')->get()->pluck('value', 'key')->all();
    }
}
