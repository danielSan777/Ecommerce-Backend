<?php

declare(strict_types=1);

namespace App\Core\Configurador\Dominio;

use App\Core\Configurador\Dominio\Excepciones\ConfiguracionInvalida;

/**
 * Reglas del configurador que no dependen de Laravel. Se aplican antes de guardar, de modo que la base nunca
 * quede con una ubicación fija sin elemento o con una excepción de un accesorio que no está marcado.
 */
final class ReglasDeConfiguracion
{
    public const ESTADOS = ['libre', 'fija', 'deshabilitada'];

    /**
     * @param  list<array<string, mixed>>  $ubicaciones  location_id, state, fixed_accessory_id, preset
     * @param  list<int>  $accesorios  ids de los accesorios marcados
     * @param  list<array<string, mixed>>  $excepciones  accessory_id, location_id
     *
     * @throws ConfiguracionInvalida
     */
    public static function validar(array $ubicaciones, array $accesorios, array $excepciones): void
    {
        $errores = [];
        $estados = [];

        foreach ($ubicaciones as $i => $u) {
            $estados[$u['location_id']] = $u['state'];

            if ($u['state'] === 'fija') {
                if (($u['fixed_accessory_id'] ?? null) === null) {
                    $errores["locations.$i.fixed_accessory_id"][] = 'Elige el elemento fijo de la ubicación fija.';
                } elseif (! in_array($u['fixed_accessory_id'], $accesorios, true)) {
                    $errores["locations.$i.fixed_accessory_id"][] = 'El elemento fijo debe ser un accesorio marcado.';
                }
                if (($u['preset'] ?? null) === null) {
                    $errores["locations.$i.preset"][] = 'Define los valores de la ubicación fija (posición y tamaño).';
                }
            }
        }

        $vistas = [];
        foreach ($excepciones as $i => $e) {
            $par = $e['accessory_id'].'-'.$e['location_id'];
            if (isset($vistas[$par])) {
                $errores["exceptions.$i.accessory_id"][] = 'Ese accesorio ya tiene una excepción en esa ubicación.';
            }
            $vistas[$par] = true;

            if (! in_array($e['accessory_id'], $accesorios, true)) {
                $errores["exceptions.$i.accessory_id"][] = 'La excepción es de un accesorio que no está marcado.';
            }
            if (($estados[$e['location_id']] ?? null) === 'deshabilitada') {
                $errores["exceptions.$i.location_id"][] = 'No se puede crear una excepción en una ubicación deshabilitada.';
            }
        }

        if ($errores !== []) {
            throw new ConfiguracionInvalida($errores);
        }
    }
}
