<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Presentacion;

use App\Shared\Presentacion\ValidacionFallida;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Si PHP rechaza un archivo por pasar el límite del servidor (`upload_max_filesize` o `post_max_size`), Laravel lo ve como
 * "no se subió nada". Aquí se detecta ese caso para decir el motivo real en vez de "falta el archivo".
 */
final class ArchivoSubido
{
    /** @param list<string> $campos campos de archivo de la petición, por ejemplo ['video', 'poster'] */
    public static function verificar(Request $request, array $campos): void
    {
        $limitePost = self::bytes((string) ini_get('post_max_size'));
        if ($limitePost > 0 && (int) $request->server('CONTENT_LENGTH') > $limitePost) {
            throw new ValidacionFallida([$campos[0] => [self::mensaje('post_max_size', (string) ini_get('post_max_size'))]]);
        }

        foreach ($campos as $campo) {
            $archivo = $request->file($campo);
            if ($archivo instanceof UploadedFile && ! $archivo->isValid()) {
                $porTamano = in_array($archivo->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);

                throw new ValidacionFallida([$campo => [$porTamano
                    ? self::mensaje('upload_max_filesize', (string) ini_get('upload_max_filesize'))
                    : 'No se pudo subir el archivo. Inténtalo de nuevo.']]);
            }
        }
    }

    private static function mensaje(string $ajuste, string $limite): string
    {
        return "El archivo pesa más de lo que permite este servidor (límite actual: {$limite}). Sube un archivo más liviano o pide aumentar {$ajuste} de PHP.";
    }

    private static function bytes(string $valor): int
    {
        $n = (int) $valor;

        return match (strtolower(substr(trim($valor), -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
