<?php

declare(strict_types=1);

namespace App\Shared\Presentacion;

use Illuminate\Support\Facades\Validator;
use RuntimeException;

/** Datos inválidos: se responde 422 con `code: validation_error` y los mensajes en español por campo. */
final class ValidacionFallida extends RuntimeException
{
    /** @param array<string, list<string>> $errores */
    public function __construct(public readonly array $errores)
    {
        parent::__construct('Revisa los datos ingresados.');
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, mixed>  $reglas
     * @param  array<string, string>  $mensajes
     * @return array<string, mixed> solo los campos validados
     *
     * @throws self
     */
    public static function validar(array $datos, array $reglas, array $mensajes = []): array
    {
        $validador = Validator::make($datos, $reglas, $mensajes);

        if ($validador->fails()) {
            throw new self($validador->errors()->toArray());
        }

        return $validador->validated();
    }
}
