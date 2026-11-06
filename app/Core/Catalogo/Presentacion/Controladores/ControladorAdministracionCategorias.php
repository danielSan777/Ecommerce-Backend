<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Aplicacion\CasosDeUso\EliminarCategoria;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

final class ControladorAdministracionCategorias extends Controller
{
    public function destroy(string $slug, EliminarCategoria $eliminar): Response
    {
        $eliminar($slug);

        return response()->noContent();
    }
}
