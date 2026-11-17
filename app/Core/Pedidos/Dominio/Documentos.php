<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Dominio;

/** Cédula (10 dígitos con verificador), RUC (13) y normalización de teléfono y correo del Ecuador. */
final class Documentos
{
    public static function cedulaValida(string $cedula): bool
    {
        if (! preg_match('/^\d{10}$/', $cedula)) {
            return false;
        }
        $provincia = (int) substr($cedula, 0, 2);
        if (($provincia < 1 || $provincia > 24) && $provincia !== 30) {
            return false;
        }
        if ((int) $cedula[2] > 5) {
            return false;
        }
        $suma = 0;
        for ($i = 0; $i < 9; $i++) {
            $n = (int) $cedula[$i] * ($i % 2 === 0 ? 2 : 1);
            $suma += $n > 9 ? $n - 9 : $n;
        }

        return (10 - $suma % 10) % 10 === (int) $cedula[9];
    }

    public static function rucValido(string $ruc): bool
    {
        if (! preg_match('/^\d{13}$/', $ruc)) {
            return false;
        }
        $provincia = (int) substr($ruc, 0, 2);
        if (($provincia < 1 || $provincia > 24) && $provincia !== 30) {
            return false;
        }
        $tercero = (int) $ruc[2];

        return match (true) {
            $tercero <= 5 => self::cedulaValida(substr($ruc, 0, 10)) && substr($ruc, 10) !== '000',
            $tercero === 6 => self::modulo11($ruc, [3, 2, 7, 6, 5, 4, 3, 2], 8) && substr($ruc, 9) !== '0000',
            $tercero === 9 => self::modulo11($ruc, [4, 3, 2, 7, 6, 5, 4, 3, 2], 9) && substr($ruc, 10) !== '000',
            default => false,
        };
    }

    public static function documentoValido(string $tipo, string $numero): bool
    {
        return $tipo === 'ruc' ? self::rucValido($numero) : self::cedulaValida($numero);
    }

    /** "099 123 4567", "+593991234567" o "0991234567" → "+593991234567"; null si no es un celular válido. */
    public static function telefono(string $texto): ?string
    {
        $digitos = preg_replace('/\D/', '', $texto) ?? '';
        if (preg_match('/^09\d{8}$/', $digitos)) {
            return '+593'.substr($digitos, 1);
        }
        if (preg_match('/^5939\d{8}$/', $digitos)) {
            return '+'.$digitos;
        }

        return null;
    }

    public static function correo(string $texto): ?string
    {
        $correo = mb_strtolower(preg_replace('/\s+/', '', $texto) ?? '');

        return filter_var($correo, FILTER_VALIDATE_EMAIL) ? $correo : null;
    }

    /** @param list<int> $coeficientes */
    private static function modulo11(string $ruc, array $coeficientes, int $posicionVerificador): bool
    {
        $suma = 0;
        foreach ($coeficientes as $i => $c) {
            $suma += (int) $ruc[$i] * $c;
        }
        $resto = $suma % 11;
        $verificador = $resto === 0 ? 0 : 11 - $resto;

        return $verificador === (int) $ruc[$posicionVerificador];
    }
}
