<?php

declare(strict_types=1);

namespace App\Core\Cuentas\Aplicacion;

use App\Core\Cuentas\Infraestructura\Correo\CorreoDeCuenta;
use App\Core\Cuentas\Infraestructura\Modelos\TokenDeCuenta;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Confirmar el correo y restablecer la contraseña de un cliente, con enlaces de un solo uso que llegan por correo (solo se guarda su hash).
 * Solo clientes: la contraseña del administrador no se restablece por correo. Nunca se revela si un correo tiene cuenta.
 */
final class RecuperacionDeCuenta
{
    private const MINUTOS_CONFIRMAR = 60 * 24 * 3;

    private const MINUTOS_RESTABLECER = 60;

    public function __construct(private readonly SesionesDeAcceso $sesiones) {}

    public function pedirConfirmacion(User $usuario): void
    {
        if ($usuario->email_verified_at !== null || $usuario->role !== User::ROL_CLIENTE) {
            return;
        }
        $enlace = $this->enlace('confirmar-correo', $this->crear($usuario, TokenDeCuenta::CONFIRMAR_CORREO, self::MINUTOS_CONFIRMAR));
        $this->enviar($usuario, new CorreoDeCuenta('Confirma tu correo · Jardín de Sele', 'Confirma tu correo', 'Gracias por crear tu cuenta. Confirma tu correo para ver aquí también los pedidos que hiciste antes con este mismo correo.', 'Confirmar mi correo', $enlace, 'Si no creaste una cuenta en Jardín de Sele, ignora este mensaje. El enlace vence en 3 días.'));
    }

    /** Reenvío desde la pantalla de ingreso, sin sesión. Solo si el correo es de un cliente que aún no confirmó; nunca revela si existe. */
    public function reenviarConfirmacion(string $correo): void
    {
        $usuario = User::query()->where('email', mb_strtolower(trim($correo)))->first();
        if ($usuario !== null) {
            $this->pedirConfirmacion($usuario);
        }
    }

    public function pedirRestablecer(string $correo): void
    {
        $usuario = User::query()->where('email', mb_strtolower(trim($correo)))->where('role', User::ROL_CLIENTE)->first();
        if ($usuario === null) {
            return;
        }
        $enlace = $this->enlace('restablecer-contrasena', $this->crear($usuario, TokenDeCuenta::RESTABLECER_CLAVE, self::MINUTOS_RESTABLECER));
        $this->enviar($usuario, new CorreoDeCuenta('Restablece tu contraseña · Jardín de Sele', 'Restablece tu contraseña', 'Recibimos una solicitud para cambiar la contraseña de tu cuenta. Toca el botón para elegir una nueva.', 'Elegir nueva contraseña', $enlace, 'Si no lo pediste tú, ignora este mensaje: tu contraseña no cambia. El enlace vence en 1 hora y sirve una sola vez.'));
    }

    /** @return bool true si el enlace era válido y la contraseña cambió; cierra todas las sesiones abiertas de la cuenta. */
    public function restablecer(string $token, string $clave): bool
    {
        $registro = $this->vigente($token, TokenDeCuenta::RESTABLECER_CLAVE);
        if ($registro === null) {
            return false;
        }
        $usuario = $registro->usuario;
        $usuario->forceFill(['password' => $clave, 'email_verified_at' => $usuario->email_verified_at ?? now()])->save();   // llegó a su correo: queda confirmado
        TokenDeCuenta::query()->where('user_id', $usuario->id)->delete();
        $this->sesiones->cerrarTodas($usuario);

        return true;
    }

    public function confirmar(string $token): bool
    {
        $registro = $this->vigente($token, TokenDeCuenta::CONFIRMAR_CORREO);
        if ($registro === null) {
            return false;
        }
        $registro->usuario->forceFill(['email_verified_at' => now()])->save();
        TokenDeCuenta::query()->where('user_id', $registro->user_id)->where('type', TokenDeCuenta::CONFIRMAR_CORREO)->delete();

        return true;
    }

    private function vigente(string $token, string $tipo): ?TokenDeCuenta
    {
        $registro = TokenDeCuenta::query()->with('usuario')->where('token_hash', hash('sha256', $token))->where('type', $tipo)->first();
        if ($registro === null) {
            return null;
        }
        if ($registro->expires_at->isPast()) {
            $registro->delete();

            return null;
        }

        return $registro;
    }

    private function crear(User $usuario, string $tipo, int $minutos): string
    {
        TokenDeCuenta::query()->where('user_id', $usuario->id)->where('type', $tipo)->delete();   // un enlace nuevo anula el anterior
        $token = Str::random(64);
        TokenDeCuenta::query()->create(['user_id' => $usuario->id, 'type' => $tipo, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes($minutos)]);

        return $token;
    }

    private function enlace(string $ruta, string $token): string
    {
        return rtrim(explode(',', (string) config('app.frontend_url'))[0], '/')."/{$ruta}?token={$token}";
    }

    private function enviar(User $usuario, CorreoDeCuenta $correo): void
    {
        try {
            Mail::to($usuario->email)->send($correo);
        } catch (Throwable $e) {
            Log::warning("No se pudo enviar el correo de cuenta a {$usuario->id}: {$e->getMessage()}");   // nunca rompe el registro ni revela nada
        }
    }
}
