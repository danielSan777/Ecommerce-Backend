<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Aplicacion\CasosDeUso;

use App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion;

/** Título y subtítulo del encabezado de la página /categorias. Vacío = la tienda usa sus textos de siempre. */
final class AdministrarTextoCategorias
{
    private const CLAVE = 'categorias_texto';

    public function __construct(private readonly RepositorioConfiguracion $configuracion) {}

    /** @return array{title: ?string, subtitle: ?string} */
    public function obtener(): array
    {
        $t = $this->configuracion->obtener(self::CLAVE);
        $t = is_array($t) ? $t : [];

        return ['title' => $this->limpio($t['title'] ?? null), 'subtitle' => $this->limpio($t['subtitle'] ?? null)];
    }

    /** @return array{title: ?string, subtitle: ?string} */
    public function guardar(?string $titulo, ?string $subtitulo): array
    {
        $nuevo = ['title' => $this->limpio($titulo), 'subtitle' => $this->limpio($subtitulo)];
        $this->configuracion->guardar(self::CLAVE, $nuevo);

        return $nuevo;
    }

    private function limpio(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return $v === '' ? null : $v;
    }
}
