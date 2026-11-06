<?php

declare(strict_types=1);

namespace App\Core\Administracion\Infraestructura\Consola;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('admin:crear {email : Correo del administrador} {--nombre= : Nombre a mostrar}')]
#[Description('Crea o actualiza un administrador. La contraseña se pide por consola: nunca queda en el repositorio.')]
final class CrearAdministrador extends Command
{
    public function handle(): int
    {
        $correo = mb_strtolower((string) $this->argument('email'));

        if (! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $this->error('El correo no es válido.');

            return self::FAILURE;
        }

        $clave = (string) $this->secret('Contraseña (mínimo 12 caracteres)');

        if (mb_strlen($clave) < 12) {
            $this->error('La contraseña debe tener al menos 12 caracteres.');

            return self::FAILURE;
        }
        if ($clave !== (string) $this->secret('Repite la contraseña')) {
            $this->error('Las contraseñas no coinciden.');

            return self::FAILURE;
        }

        $usuario = User::query()->firstOrNew(['email' => $correo]);
        $usuario->name = $this->option('nombre') ?: ($usuario->name ?: 'Administrador');
        $usuario->password = $clave;
        $usuario->save();

        $this->info("Administrador {$correo} listo.");

        return self::SUCCESS;
    }
}
