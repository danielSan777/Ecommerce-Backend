<?php

declare(strict_types=1);

namespace App\Core\Configurador\Presentacion\Controladores;

use App\Core\Configurador\Aplicacion\CasosDeUso\AdministrarUbicaciones;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class ControladorAdministracionUbicaciones extends Controller
{
    private const MENSAJES = [
        'name.required' => 'Escribe el nombre de la ubicación.',
        'name.max' => 'El nombre no puede pasar de 100 caracteres.',
        'name.unique' => 'Ya existe una ubicación con ese nombre.',
        'sort_order.integer' => 'El orden debe ser un número.',
        'allowed_category_ids.*.exists' => 'Una de las categorías permitidas no existe.',
        'excluded_category_ids.*.exists' => 'Una de las categorías excluidas no existe.',
        'allowed_category_ids.*.distinct' => 'Una categoría está repetida en la lista de permitidas.',
        'excluded_category_ids.*.distinct' => 'Una categoría está repetida en la lista de excluidas.',
        'allowed_category_ids.*.different' => 'Una categoría no puede estar a la vez permitida y excluida.',
    ];

    public function index(AdministrarUbicaciones $ubicaciones): JsonResponse
    {
        return response()->json(['data' => $ubicaciones->listar()]);
    }

    public function store(Request $request, AdministrarUbicaciones $ubicaciones): JsonResponse
    {
        return response()->json($ubicaciones->crear($this->validar($request, null)), 201);
    }

    public function update(int $id, Request $request, AdministrarUbicaciones $ubicaciones): JsonResponse
    {
        return response()->json($ubicaciones->actualizar($id, $this->validar($request, $id)));
    }

    public function destroy(int $id, AdministrarUbicaciones $ubicaciones): Response
    {
        $ubicaciones->eliminar($id);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?int $id): array
    {
        $validos = ValidacionFallida::validar($request->all(), [
            'name' => [$id === null ? 'required' : 'sometimes', 'required', 'string', 'max:100', Rule::unique('locations', 'name')->ignore($id)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'allowed_category_ids' => ['sometimes', 'array'],
            'allowed_category_ids.*' => ['integer', 'distinct', Rule::exists('accessory_categories', 'id')],
            'excluded_category_ids' => ['sometimes', 'array'],
            'excluded_category_ids.*' => ['integer', 'distinct', Rule::exists('accessory_categories', 'id')],
        ], self::MENSAJES);

        $repetidas = array_intersect($validos['allowed_category_ids'] ?? [], $validos['excluded_category_ids'] ?? []);
        if ($repetidas !== []) {
            throw new ValidacionFallida(['allowed_category_ids' => ['Una categoría no puede estar a la vez permitida y excluida.']]);
        }

        return $validos;
    }
}
