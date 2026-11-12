<?php

declare(strict_types=1);

namespace App\Core\Configurador\Presentacion\Controladores;

use App\Core\Catalogo\Dominio\Excepciones\ImagenInvalida;
use App\Core\Catalogo\Presentacion\ArchivoSubido;
use App\Core\Configurador\Aplicacion\CasosDeUso\AdministrarConfiguracion;
use App\Core\Configurador\Aplicacion\CasosDeUso\AdministrarImagenParaPersonalizar;
use App\Core\Configurador\Dominio\ReglasDeConfiguracion;
use App\Core\Configurador\Presentacion\ReglasDeGeometria;
use App\Http\Controllers\Controller;
use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Configuración del configurador del lado del administrador: por categoría de producto (todos sus productos la
 * comparten) o por producto (cuando se marca «configuración propia»).
 */
final class ControladorAdministracionConfigurador extends Controller
{
    public function categoria(string $slug, AdministrarConfiguracion $configuracion): JsonResponse
    {
        return response()->json($configuracion->deCategoria($slug));
    }

    public function guardarCategoria(string $slug, Request $request, AdministrarConfiguracion $configuracion): JsonResponse
    {
        return response()->json($configuracion->guardarDeCategoria($slug, $this->validar($request)));
    }

    public function producto(string $sku, AdministrarConfiguracion $configuracion): JsonResponse
    {
        return response()->json($configuracion->deProducto($sku));
    }

    public function guardarProducto(string $sku, Request $request, AdministrarConfiguracion $configuracion): JsonResponse
    {
        return response()->json($configuracion->guardarDeProducto($sku, $this->validar($request)));
    }

    /** Cambia entre «usa la configuración de su categoría» y «configuración propia». */
    public function modo(string $sku, Request $request, AdministrarConfiguracion $configuracion): JsonResponse
    {
        $validos = ValidacionFallida::validar($request->all(), [
            'uses_own_configurator' => ['required', 'boolean'],
            'copy_from_category' => ['sometimes', 'boolean'],
        ], [
            'uses_own_configurator.required' => 'Indica si el producto usa la configuración de su categoría o la suya propia.',
            'uses_own_configurator.boolean' => 'Indica si el producto usa la configuración de su categoría o la suya propia.',
        ]);

        return response()->json($configuracion->cambiarModo($sku, (bool) $validos['uses_own_configurator'], (bool) ($validos['copy_from_category'] ?? false)));
    }

    /** Imagen para personalizar del producto (multipart, campo `image`). Reemplaza la anterior. Es obligatoria y se sube desde el producto. */
    public function subirImagenBase(string $sku, Request $request, AdministrarImagenParaPersonalizar $imagen): JsonResponse
    {
        ArchivoSubido::verificar($request, ['image']);
        ValidacionFallida::validar($request->all(), ['image' => ['required', 'file']], [
            'image.required' => 'Elige la imagen para personalizar.',
            'image.file' => 'Elige la imagen para personalizar.',
        ]);

        try {
            $guardada = $imagen->subir($sku, (string) file_get_contents($request->file('image')->getRealPath()));

            return response()->json(['personalization_image' => $guardada['base_image']], 201);
        } catch (ImagenInvalida $e) {
            throw new ValidacionFallida([$e->campo => [$e->getMessage()]]);
        }
    }

    /** Encuadre de la imagen para personalizar del producto: escala (%) y desplazamiento (% del visor). */
    public function ajustarImagenBase(string $sku, Request $request, AdministrarConfiguracion $configuracion): JsonResponse
    {
        $validos = ValidacionFallida::validar($request->all(), [
            'scale' => ['required', 'numeric', 'between:50,200'],
            'x' => ['required', 'numeric', 'between:-50,50'],
            'y' => ['required', 'numeric', 'between:-50,50'],
        ], [
            'scale.required' => 'Indica la escala de la imagen.',
            'scale.numeric' => 'La escala debe ser un número.',
            'scale.between' => 'La escala debe estar entre 50 % y 200 %.',
            'x.required' => 'Indica el desplazamiento horizontal.',
            'x.numeric' => 'El desplazamiento horizontal debe ser un número.',
            'x.between' => 'El desplazamiento horizontal debe estar entre -50 y 50.',
            'y.required' => 'Indica el desplazamiento vertical.',
            'y.numeric' => 'El desplazamiento vertical debe ser un número.',
            'y.between' => 'El desplazamiento vertical debe estar entre -50 y 50.',
        ]);

        return response()->json($configuracion->ajustarImagen($sku, (float) $validos['scale'], (float) $validos['x'], (float) $validos['y']));
    }

    /** @return array{locations: list<array<string, mixed>>, accessory_ids: list<int>, exceptions: list<array<string, mixed>>} */
    private function validar(Request $request): array
    {
        $reglas = [
            'locations' => ['present', 'array'],
            'locations.*.location_id' => ['required', 'integer', 'distinct', Rule::exists('locations', 'id')],
            'locations.*.state' => ['required', Rule::in(ReglasDeConfiguracion::ESTADOS)],
            'locations.*.priority' => ['required', 'integer', 'between:1,99'],
            'locations.*.layer' => ['required', 'integer', 'between:0,99'],
            'locations.*.fixed_accessory_id' => ['nullable', 'integer', Rule::exists('accessories', 'id')],
            'locations.*.preset' => ['nullable', 'array'],
            'accessory_ids' => ['present', 'array'],
            'accessory_ids.*' => ['integer', 'distinct', Rule::exists('accessories', 'id')],
            'exceptions' => ['present', 'array'],
            'exceptions.*.accessory_id' => ['required', 'integer', Rule::exists('accessories', 'id')],
            'exceptions.*.location_id' => ['required', 'integer', Rule::exists('locations', 'id')],
        ] + ReglasDeGeometria::reglas('locations.*.preset', true) + ReglasDeGeometria::reglas('exceptions.*', true);

        // Las reglas de geometría de `preset` solo aplican si viene un preset
        foreach (array_keys($reglas) as $campo) {
            if (str_starts_with($campo, 'locations.*.preset.')) {
                $reglas[$campo][0] = 'required_with:'.'locations.*.preset';
            }
        }

        /** @var array{locations: list<array<string, mixed>>, accessory_ids: list<int>, exceptions: list<array<string, mixed>>} $validos */
        $validos = ValidacionFallida::validar($request->all(), $reglas, [
            'locations.present' => 'Faltan las ubicaciones.',
            'accessory_ids.present' => 'Falta la lista de accesorios marcados.',
            'exceptions.present' => 'Falta la lista de excepciones.',
            'locations.*.location_id.exists' => 'Una de las ubicaciones no existe.',
            'locations.*.location_id.distinct' => 'Una ubicación está repetida.',
            'locations.*.state.in' => 'El estado debe ser libre, fija o deshabilitada.',
            'locations.*.priority.between' => 'La prioridad debe estar entre 1 y 99.',
            'locations.*.layer.between' => 'La capa debe estar entre 0 y 99.',
            'locations.*.fixed_accessory_id.exists' => 'El elemento fijo elegido no existe.',
            'accessory_ids.*.exists' => 'Uno de los accesorios marcados no existe.',
            'accessory_ids.*.distinct' => 'Un accesorio está marcado dos veces.',
            'exceptions.*.accessory_id.exists' => 'El accesorio de una excepción no existe.',
            'exceptions.*.location_id.exists' => 'La ubicación de una excepción no existe.',
        ] + ReglasDeGeometria::mensajes('locations.*.preset') + ReglasDeGeometria::mensajes('exceptions.*'));

        $validos['locations'] = array_map(fn (array $u): array => $u + ['fixed_accessory_id' => null, 'preset' => null], $validos['locations']);

        return $validos;
    }
}
