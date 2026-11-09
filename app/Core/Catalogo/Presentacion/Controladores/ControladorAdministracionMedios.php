<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Aplicacion\CasosDeUso\AdministrarMedios;
use App\Core\Catalogo\Dominio\Excepciones\ImagenInvalida;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class ControladorAdministracionMedios extends Controller
{
    private const MENSAJES = [
        'image.required' => 'Elige una imagen.',
        'image.file' => 'Elige una imagen.',
        'image.uploaded' => 'No se pudo subir la imagen. Revisa que pese menos de 2 MB o pide aumentar el límite del servidor.',
        'role.in' => 'El tipo de imagen no es válido.',
        'alt.required' => 'Escribe la descripción de la imagen.',
        'alt.max' => 'La descripción de la imagen no puede pasar de 200 caracteres.',
        'sort_order.integer' => 'El orden no es válido.',
    ];

    public function indexProducto(string $sku, AdministrarMedios $medios): JsonResponse
    {
        return response()->json(['data' => $medios->listar('producto', $sku)]);
    }

    public function indexCategoria(string $slug, AdministrarMedios $medios): JsonResponse
    {
        return response()->json(['data' => $medios->listar('categoria', $slug)]);
    }

    public function indexCampana(string $slug, AdministrarMedios $medios): JsonResponse
    {
        return response()->json(['data' => $medios->listar('campana', $slug)]);
    }

    public function storeCampana(string $slug, Request $request, AdministrarMedios $medios): JsonResponse
    {
        return $this->subir('campana', $slug, $request, $medios);
    }

    public function storeProducto(string $sku, Request $request, AdministrarMedios $medios): JsonResponse
    {
        return $this->subir('producto', $sku, $request, $medios);
    }

    public function storeCategoria(string $slug, Request $request, AdministrarMedios $medios): JsonResponse
    {
        return $this->subir('categoria', $slug, $request, $medios);
    }

    public function update(int $id, Request $request, AdministrarMedios $medios): JsonResponse
    {
        $validos = ValidacionFallida::validar($request->all(), [
            'alt' => ['sometimes', 'required', 'string', 'max:200'],
            'role' => ['sometimes', Rule::in(AdministrarMedios::ROLES)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65000'],
        ], self::MENSAJES);

        try {
            return response()->json($medios->actualizar($id, $validos));
        } catch (ImagenInvalida $e) {
            throw new ValidacionFallida([$e->campo => [$e->getMessage()]]);
        }
    }

    public function destroy(int $id, AdministrarMedios $medios): Response
    {
        $medios->eliminar($id);

        return response()->noContent();
    }

    private function subir(string $tipo, string $clave, Request $request, AdministrarMedios $medios): JsonResponse
    {
        // La validación real (tipo de archivo, tamaño, píxeles) la hace el procesador mirando el contenido
        $validos = ValidacionFallida::validar($request->all(), [
            'image' => ['required', 'file'],
            'role' => ['sometimes', Rule::in(AdministrarMedios::ROLES)],
            'alt' => ['required', 'string', 'max:200'],
        ], self::MENSAJES);

        $contenido = (string) file_get_contents($request->file('image')->getRealPath());

        try {
            return response()->json($medios->subir($tipo, $clave, $contenido, $validos['role'] ?? 'front', $validos['alt']), 201);
        } catch (ImagenInvalida $e) {
            throw new ValidacionFallida([$e->campo => [$e->getMessage()]]);
        }
    }
}
