<?php

declare(strict_types=1);

namespace App\Core\Administracion\Presentacion\Controladores;

use App\Core\Administracion\Aplicacion\SesionesAdministrador;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

final class ControladorSesionAdministrador extends Controller
{
    public function login(Request $request, SesionesAdministrador $sesiones): JsonResponse
    {
        $validador = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device' => ['nullable', 'string', 'max:120'],
        ], [
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'Escribe un correo válido.',
            'password.required' => 'Escribe tu contraseña.',
        ]);

        if ($validador->fails()) {
            return response()->json(['code' => 'validation_error', 'message' => 'Revisa los datos ingresados.', 'errors' => $validador->errors()], 422);
        }

        $sesion = $sesiones->iniciar($request->string('email')->toString(), $request->string('password')->toString(), $request->input('device'));

        if ($sesion === null) {
            return response()->json(['code' => 'invalid_credentials', 'message' => 'Correo o contraseña incorrectos.'], 401);
        }

        return response()->json([
            'token' => $sesion['token'],
            'token_type' => 'Bearer',
            'expires_at' => $sesion['expires_at']->toIso8601String(),
            'user' => self::usuario($sesion['user']),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => self::usuario($request->user('admin'))]);
    }

    public function logout(Request $request, SesionesAdministrador $sesiones): Response
    {
        $sesiones->cerrar((string) $request->bearerToken());

        return response()->noContent();
    }

    public function logoutAll(Request $request, SesionesAdministrador $sesiones): Response
    {
        $sesiones->cerrarTodas($request->user('admin'));

        return response()->noContent();
    }

    /** @return array{name: string, email: string} */
    private static function usuario(User $u): array
    {
        return ['name' => $u->name, 'email' => $u->email];
    }
}
