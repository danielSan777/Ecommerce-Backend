<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Aplicacion\CasosDeUso\AdministrarPortadaInicio;
use App\Core\Catalogo\Dominio\Excepciones\ImagenInvalida;
use App\Core\Catalogo\Presentacion\ArchivoSubido;
use App\Core\Catalogo\Presentacion\Resources\MedioResource;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Panel: imágenes y videos de la portada de la página principal (sin límite de cantidad). Se sube `image` (con `alt`) o `video` (con `poster` opcional). */
final class ControladorAdministracionInicio extends Controller
{
    private const MENSAJES = [
        'alt.required' => 'Escribe la descripción de la imagen.',
        'alt.max' => 'La descripción no puede pasar de 200 caracteres.',
        'image.file' => 'Elige una imagen.',
        'image.uploaded' => 'No se pudo subir la imagen. Pide aumentar el límite de subida del servidor.',
        'video.file' => 'Elige un video.',
        'video.uploaded' => 'No se pudo subir el video. Pide aumentar el límite de subida del servidor (upload_max_filesize y post_max_size).',
        'poster.file' => 'La portada del video debe ser una imagen.',
    ];

    public function index(AdministrarPortadaInicio $portada): JsonResponse
    {
        return response()->json(['data' => array_map($this->formato(...), $portada->lista())]);
    }

    public function store(Request $request, AdministrarPortadaInicio $portada): JsonResponse
    {
        ArchivoSubido::verificar($request, ['video', 'poster', 'image']);
        if (! $request->hasFile('video') && ! $request->hasFile('image')) {
            throw new ValidacionFallida(['image' => ['Elige una imagen o un video.']]);
        }
        if ($request->hasFile('video') && $request->hasFile('image')) {
            throw new ValidacionFallida(['image' => ['Sube una imagen o un video, no los dos a la vez.']]);
        }

        try {
            if ($request->hasFile('video')) {
                $validos = ValidacionFallida::validar($request->all(), [
                    'video' => ['required', 'file'], 'poster' => ['sometimes', 'nullable', 'file'], 'alt' => ['sometimes', 'nullable', 'string', 'max:200'],
                ], self::MENSAJES);
                $poster = $request->hasFile('poster') ? (string) file_get_contents($request->file('poster')->getRealPath()) : null;
                $item = $portada->agregarVideo((string) file_get_contents($request->file('video')->getRealPath()), $poster, $validos['alt'] ?? null);
            } else {
                $validos = ValidacionFallida::validar($request->all(), [
                    'image' => ['required', 'file'], 'alt' => ['required', 'string', 'max:200'],
                ], self::MENSAJES);
                $item = $portada->agregarImagen((string) file_get_contents($request->file('image')->getRealPath()), $validos['alt']);
            }
        } catch (ImagenInvalida $e) {
            throw new ValidacionFallida([$e->campo => [$e->getMessage()]]);
        }

        return response()->json(['data' => $this->formato($item)], 201);
    }

    public function destroy(string $id, AdministrarPortadaInicio $portada): Response|JsonResponse
    {
        if (! $portada->quitar($id)) {
            return response()->json(['code' => 'not_found', 'message' => 'Esa portada no existe.'], 404);
        }

        return response()->noContent();
    }

    /** @param array{id: string, medio: \App\Core\Catalogo\Dominio\Entidades\Medio} $item @return array<string, mixed> */
    private function formato(array $item): array
    {
        return ['id' => $item['id'], ...MedioResource::formato($item['medio'])];
    }
}
