<?php

declare(strict_types=1);

namespace App\Core\Configurador\Presentacion;

/** Reglas y mensajes de los valores de posición, tamaño y giro (% del visor y grados). */
final class ReglasDeGeometria
{
    /** @return array<string, list<string>> */
    public static function reglas(string $prefijo, bool $obligatoria): array
    {
        $r = $obligatoria ? 'required' : 'sometimes';

        return [
            "$prefijo.x" => [$r, 'numeric', 'between:0,100'],
            "$prefijo.y" => [$r, 'numeric', 'between:0,100'],
            "$prefijo.width" => [$r, 'numeric', 'between:1,100'],
            "$prefijo.height" => [$r, 'numeric', 'between:1,100'],
            "$prefijo.rot_x" => ['sometimes', 'numeric', 'between:-90,90'],
            "$prefijo.rot_y" => ['sometimes', 'numeric', 'between:-180,180'],
            "$prefijo.rot_z" => ['sometimes', 'numeric', 'between:-180,180'],
            "$prefijo.mirror" => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function mensajes(string $prefijo): array
    {
        $m = [
            'x.required' => 'Falta la posición horizontal (x).',
            'x.between' => 'La posición horizontal debe estar entre 0 y 100.',
            'y.required' => 'Falta la posición vertical (y).',
            'y.between' => 'La posición vertical debe estar entre 0 y 100.',
            'width.required' => 'Falta el ancho.',
            'width.between' => 'El ancho debe estar entre 1 y 100.',
            'height.required' => 'Falta el alto.',
            'height.between' => 'El alto debe estar entre 1 y 100.',
            'rot_x.between' => 'La inclinación debe estar entre -90 y 90 grados.',
            'rot_y.between' => 'El giro izquierda/derecha debe estar entre -180 y 180 grados.',
            'rot_z.between' => 'El giro en el plano debe estar entre -180 y 180 grados.',
            'mirror.boolean' => 'Indica si el accesorio va en espejo o no.',
        ];
        foreach (['x', 'y', 'width', 'height', 'rot_x', 'rot_y', 'rot_z'] as $campo) {
            $m["$campo.numeric"] = 'Escribe un número.';
        }

        $con = [];
        foreach ($m as $clave => $texto) {
            $con["$prefijo.$clave"] = $texto;
        }

        return $con;
    }
}
