<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Aplicacion\CasosDeUso\AdministrarCampanas;
use App\Core\Catalogo\Infraestructura\Modelos\CategoriaModelo;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ControladorAdministracionCampanas extends Controller
{
    private const MENSAJES = [
        'name.required' => 'Escribe el nombre de la campaña.',
        'name.max' => 'El nombre no puede pasar de 150 caracteres.',
        'slug.required' => 'El nombre debe tener al menos una letra o un número.',
        'slug.unique' => 'Ya existe una campaña con un nombre igual o muy parecido.',
        'related_category.required' => 'Elige la categoría relacionada.',
        'related_category.exists' => 'La categoría elegida no existe.',
        'start_md.required' => 'Elige cuándo empieza la temporada.',
        'end_md.required' => 'Elige cuándo termina la temporada.',
        'event_md.required' => 'Elige la fecha del evento.',
        'tagline.max' => 'El lema no puede pasar de 255 caracteres.',
        'slogan.max' => 'La frase del banner no puede pasar de 255 caracteres.',
        'is_active.boolean' => 'Indica si la campaña está activa o no.',
        'seo_title.max' => 'El título para Google no puede pasar de 255 caracteres.',
        'seo_description.max' => 'La descripción para Google no puede pasar de 320 caracteres.',
        'products.array' => 'Los productos deben ser una lista.',
        'products.max' => 'Puedes elegir hasta 50 productos.',
        'products.*.exists' => 'Uno de los productos elegidos no existe.',
    ];

    public function index(AdministrarCampanas $campanas): JsonResponse
    {
        return response()->json(['data' => $campanas->listar()]);
    }

    public function show(string $slug, AdministrarCampanas $campanas): JsonResponse
    {
        return response()->json($campanas->obtener($slug));
    }

    public function store(Request $request, AdministrarCampanas $campanas): JsonResponse
    {
        // La dirección NO la elige el usuario: siempre sale del nombre.
        $datos = $request->except('slug');
        if (is_string($datos['name'] ?? null)) {
            $datos['slug'] = Str::slug($datos['name']);
        }

        $validos = ValidacionFallida::validar($datos, [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('campaigns', 'slug')],
            ...$this->reglasComunes(requeridos: true),
        ], self::MENSAJES);

        return response()->json($campanas->crear($this->limpiar($validos)), 201);
    }

    public function update(string $slug, Request $request, AdministrarCampanas $campanas): JsonResponse
    {
        // La dirección no se edita: cambiarla rompería los enlaces ya publicados.
        $validos = ValidacionFallida::validar($request->except('slug'), [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            ...$this->reglasComunes(requeridos: false),
        ], self::MENSAJES);

        return response()->json($campanas->actualizar($slug, $this->limpiar($validos)));
    }

    public function destroy(string $slug, AdministrarCampanas $campanas): Response
    {
        $campanas->eliminar($slug);

        return response()->noContent();
    }

    /**
     * Textos simples: sin etiquetas HTML y, si quedan vacíos, sin valor.
     *
     * @param  array<string, mixed>  $validos
     * @return array<string, mixed>
     */
    private function limpiar(array $validos): array
    {
        foreach (['tagline', 'slogan', 'seo_title', 'seo_description'] as $campo) {
            if (isset($validos[$campo]) && is_string($validos[$campo])) {
                $limpio = trim(strip_tags($validos[$campo]));
                $validos[$campo] = $limpio === '' ? null : $limpio;
            }
        }
        if (array_key_exists('products', $validos)) {
            $validos['products'] = array_values(array_unique($validos['products'] ?? []));
        }

        return $validos;
    }

    /** Fecha anual "MM-DD" que existe en el calendario (se acepta 02-29). */
    private function fechaAnual(): \Closure
    {
        return function (string $atributo, mixed $valor, \Closure $falla): void {
            if (! is_string($valor) || ! preg_match('/^(\d{2})-(\d{2})$/', $valor, $m) || ! checkdate((int) $m[1], (int) $m[2], 2028)) {
                $falla('La fecha no es válida. Elige un día y un mes que existan.');
            }
        };
    }

    /** @return array<string, list<mixed>> */
    private function reglasComunes(bool $requeridos): array
    {
        $req = $requeridos ? 'required' : 'sometimes';

        return [
            'related_category' => [$req, 'string', Rule::exists(CategoriaModelo::class, 'slug')],
            'start_md' => [$req, $this->fechaAnual()],
            'end_md' => [$req, $this->fechaAnual()],
            'event_md' => [$req, $this->fechaAnual()],
            'tagline' => ['nullable', 'string', 'max:255'],
            'slogan' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'products' => ['sometimes', 'nullable', 'array', 'max:50'],
            'products.*' => ['string', Rule::exists('products', 'sku')->whereNull('deleted_at')],
        ];
    }
}
