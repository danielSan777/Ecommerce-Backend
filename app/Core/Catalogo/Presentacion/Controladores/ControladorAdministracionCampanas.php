<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion\Controladores;

use App\Core\Catalogo\Aplicacion\CasosDeUso\AdministrarCampanas;
use App\Core\Catalogo\Aplicacion\CasosDeUso\PrevisualizarCampana;
use App\Core\Catalogo\Presentacion\Resources\CampanaResource;
use App\Core\Catalogo\Dominio\Servicios\TemporadaDeCampana;
use App\Core\Catalogo\Infraestructura\Modelos\CampanaModelo;
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
        'start_md.required' => 'Elige cuándo empieza la temporada.',
        'end_md.required' => 'Elige cuándo termina la temporada.',
        'event_md.required' => 'Elige la fecha del evento.',
        'tagline.max' => 'El lema no puede pasar de 255 caracteres.',
        'slogan.max' => 'La frase del banner no puede pasar de 255 caracteres.',
        'theme_color.regex' => 'El color debe ser como #C2185B (almohadilla y 6 letras o números).',
        'emoji.max' => 'El emoji no puede pasar de 16 caracteres.',
        'closing_message.max' => 'El mensaje de cierre no puede pasar de 160 caracteres.',
        'closing_until_md.required_with' => 'Elige hasta qué fecha se muestra el mensaje de cierre.',
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

    /** La página completa de la campaña, como se verá en temporada, aunque aún esté inactiva o fuera de fechas. */
    public function preview(string $slug, PrevisualizarCampana $previsualizar): JsonResponse
    {
        return response()->json(CampanaResource::formato($previsualizar($slug)));
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
            'slug' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('campaigns', 'slug'), Rule::unique('campaign_slug_redirects', 'slug')],
            ...$this->reglasComunes(requeridos: true),
        ], self::MENSAJES);

        $this->verificarDespedida($this->limpiar($validos), $validos);

        return response()->json($campanas->crear($this->limpiar($validos)), 201);
    }

    public function update(string $slug, Request $request, AdministrarCampanas $campanas): JsonResponse
    {
        // La dirección sale del nombre: si cambia el nombre, cambia la dirección y la anterior sigue llevando a la nueva.
        $datos = $request->except('slug');
        $id = CampanaModelo::query()->where('slug', $slug)->value('id');
        if (is_string($datos['name'] ?? null) && ($nueva = Str::slug($datos['name'])) !== $slug) {
            $datos['slug'] = $nueva;
        }
        $validos = ValidacionFallida::validar($datos, [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'slug' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('campaigns', 'slug')->ignore($id),
                Rule::unique('campaign_slug_redirects', 'slug')->where(fn ($q) => $q->where('campaign_id', '!=', $id))],
            ...$this->reglasComunes(requeridos: false),
        ], self::MENSAJES);

        $actual = $campanas->obtener($slug);
        $this->verificarDespedida($this->limpiar($validos), $validos + ['start_md' => $actual['start_md'], 'end_md' => $actual['end_md']]);

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
        foreach (['tagline', 'slogan', 'emoji', 'closing_message', 'seo_title', 'seo_description'] as $campo) {
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

    /**
     * La fecha límite del mensaje de cierre debe caer después del fin de la temporada y antes de que empiece la siguiente.
     *
     * @param  array<string, mixed>  $limpios
     * @param  array<string, mixed>  $fechas  trae `start_md` y `end_md` (los nuevos o, al editar, los que ya tenía)
     */
    private function verificarDespedida(array $limpios, array $fechas): void
    {
        $hasta = $limpios['closing_until_md'] ?? null;
        if (! is_string($hasta) || ! isset($fechas['start_md'], $fechas['end_md'])) {
            return;
        }
        if (! (new TemporadaDeCampana)->despedidaValida($fechas['start_md'], $fechas['end_md'], $hasta)) {
            throw new ValidacionFallida(['closing_until_md' => ['La fecha debe ser después de que termina la temporada y antes de que empiece la siguiente.']]);
        }
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
            'start_md' => [$req, $this->fechaAnual()],
            'end_md' => [$req, $this->fechaAnual()],
            'event_md' => [$req, $this->fechaAnual()],
            'tagline' => ['nullable', 'string', 'max:255'],
            'slogan' => ['nullable', 'string', 'max:255'],
            'theme_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'emoji' => ['nullable', 'string', 'max:16'],
            'closing_message' => ['sometimes', 'nullable', 'string', 'max:160'],
            'closing_until_md' => ['nullable', 'required_with:closing_message', $this->fechaAnual()],
            'is_active' => ['sometimes', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'products' => ['sometimes', 'nullable', 'array', 'max:50'],
            'products.*' => ['string', Rule::exists('products', 'sku')->whereNull('deleted_at')],
        ];
    }
}
