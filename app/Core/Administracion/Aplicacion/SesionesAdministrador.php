<?php

declare(strict_types=1);

namespace App\Core\Administracion\Aplicacion;

use App\Core\Administracion\Infraestructura\Modelos\TokenAdministrador;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Inicia y cierra sesiones del administrador con tokens opacos (solo se guarda su hash). */
final class SesionesAdministrador
{
    private static ?string $hashRelleno = null;

    /** @return array{token: string, expires_at: CarbonInterface, user: User}|null */
    public function iniciar(string $correo, string $clave, ?string $dispositivo): ?array
    {
        $usuario = User::query()->where('email', mb_strtolower($correo))->first();
        // Sin usuario se compara contra un hash de relleno: la respuesta tarda igual exista o no el correo.
        $valida = Hash::check($clave, $usuario->password ?? (self::$hashRelleno ??= Hash::make(Str::random(32))));

        if ($usuario === null || ! $valida) {
            return null;
        }

        $token = Str::random(64);
        $expira = now()->addHours((int) config('auth.admin_token_hours'));

        TokenAdministrador::query()->create([
            'user_id' => $usuario->id,
            'token_hash' => self::hash($token),
            'device' => $dispositivo !== null ? Str::limit($dispositivo, 120, '') : null,
            'expires_at' => $expira,
        ]);

        return ['token' => $token, 'expires_at' => $expira, 'user' => $usuario];
    }

    public function usuarioDelToken(string $token): ?User
    {
        $registro = TokenAdministrador::query()->with('usuario')->where('token_hash', self::hash($token))->first();

        if ($registro === null) {
            return null;
        }
        if ($registro->expires_at->isPast()) {
            $registro->delete();

            return null;
        }
        if ($registro->last_used_at === null || $registro->last_used_at->diffInMinutes(now()) >= 5) {
            $registro->forceFill(['last_used_at' => now()])->save();
        }

        return $registro->usuario;
    }

    public function cerrar(string $token): void
    {
        TokenAdministrador::query()->where('token_hash', self::hash($token))->delete();
    }

    public function cerrarTodas(User $usuario): void
    {
        TokenAdministrador::query()->where('user_id', $usuario->id)->delete();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
