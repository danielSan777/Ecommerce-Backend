<?php

declare(strict_types=1);

namespace App\Core\Cuentas\Aplicacion;

use App\Core\Cuentas\Infraestructura\Modelos\TokenDeAcceso;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Registra, inicia y cierra sesiones de clientes y administradores con tokens opacos (solo se guarda su hash). */
final class SesionesDeAcceso
{
    private static ?string $hashRelleno = null;

    public function registrar(string $nombre, string $correo, string $clave): User
    {
        // El rol nunca viene de la petición: toda cuenta pública nace como cliente.
        $usuario = new User(['name' => $nombre, 'email' => mb_strtolower($correo), 'password' => $clave]);
        $usuario->forceFill(['role' => User::ROL_CLIENTE])->save();

        return $usuario;
    }

    /** @return array{token: string, expires_at: CarbonInterface|null, user: User}|null */
    public function iniciar(string $correo, string $clave, ?string $dispositivo): ?array
    {
        $usuario = User::query()->where('email', mb_strtolower($correo))->first();
        // Sin usuario se compara contra un hash de relleno: la respuesta tarda igual exista o no el correo.
        $valida = Hash::check($clave, $usuario->password ?? (self::$hashRelleno ??= Hash::make(Str::random(32))));

        if ($usuario === null || ! $valida) {
            return null;
        }

        return $this->abrir($usuario, $dispositivo);
    }

    /** @return array{token: string, expires_at: CarbonInterface|null, user: User} */
    public function abrir(User $usuario, ?string $dispositivo): array
    {
        $token = Str::random(64);
        $minutos = $this->minutosDeVida($usuario);
        $expira = $minutos === null ? null : now()->addMinutes($minutos);

        TokenDeAcceso::query()->create([
            'user_id' => $usuario->id,
            'token_hash' => self::hash($token),
            'device' => $dispositivo !== null ? Str::limit($dispositivo, 120, '') : null,
            'expires_at' => $expira,
        ]);

        return ['token' => $token, 'expires_at' => $expira, 'user' => $usuario];
    }

    /**
     * Minutos que dura la sesión desde el ingreso (no se renueva con el uso). El administrador, por seguridad, 2 horas
     * (si la configuración falta o vale 0 se usan 2 horas: nunca un token ya vencido). El cliente no vence: `null`;
     * su sesión termina al cerrarla él, en un dispositivo o en todos.
     */
    private function minutosDeVida(User $usuario): ?int
    {
        if (! $usuario->esAdministrador()) {
            return null;
        }

        $minutos = (int) config('auth.admin_token_minutes', 120);

        return $minutos >= 1 ? $minutos : 120;
    }

    public function usuarioDelToken(string $token): ?User
    {
        $registro = TokenDeAcceso::query()->with('usuario')->where('token_hash', self::hash($token))->first();

        if ($registro === null) {
            return null;
        }
        if ($registro->expires_at?->isPast()) {
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
        TokenDeAcceso::query()->where('token_hash', self::hash($token))->delete();
    }

    public function cerrarTodas(User $usuario): void
    {
        TokenDeAcceso::query()->where('user_id', $usuario->id)->delete();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
