<?php

declare(strict_types=1);

namespace App\Core\Cuentas\Presentacion\Controladores;

use App\Core\Cuentas\Aplicacion\PedidosDeLaCuenta;
use App\Core\Cuentas\Aplicacion\RecuperacionDeCuenta;
use App\Core\Cuentas\Aplicacion\SesionesDeAcceso;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Cuenta única para clientes y administradores: la respuesta incluye el rol y Angular decide a dónde llevar al usuario. */
final class ControladorCuenta extends Controller
{
    public function register(Request $request, SesionesDeAcceso $sesiones, RecuperacionDeCuenta $recuperacion): JsonResponse
    {
        $validador = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)->max(255), 'confirmed'],
            'device' => ['nullable', 'string', 'max:120'],
        ], [
            'name.required' => 'Escribe tu nombre.',
            'name.min' => 'Escribe tu nombre completo.',
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'Escribe un correo válido.',
            'email.unique' => 'Ya existe una cuenta con ese correo. Inicia sesión.',
            'password.required' => 'Escribe una contraseña.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        if ($validador->fails()) {
            return $this->invalido($validador->errors()->toArray());
        }

        $usuario = $sesiones->registrar($request->string('name')->trim()->toString(), $request->string('email')->toString(), $request->string('password')->toString());

        defer(fn () => $recuperacion->pedirConfirmacion($usuario));   // el correo de confirmación sale después de responder

        return $this->conSesion($sesiones->abrir($usuario, $request->input('device')), 201);
    }

    /** Siempre responde igual, exista o no la cuenta: no se puede usar para averiguar qué correos están registrados. */
    public function olvide(Request $request, RecuperacionDeCuenta $recuperacion): JsonResponse
    {
        $validador = Validator::make($request->all(), ['email' => ['required', 'string', 'email', 'max:255']], ['email.required' => 'Escribe tu correo.', 'email.email' => 'Escribe un correo válido.']);
        if ($validador->fails()) {
            return $this->invalido($validador->errors()->toArray());
        }
        $correo = $request->string('email')->toString();
        defer(fn () => $recuperacion->pedirRestablecer($correo));

        return response()->json(['message' => 'Si ese correo tiene una cuenta, te enviamos un enlace para elegir una nueva contraseña. Revisa también la carpeta de spam.']);
    }

    public function restablecer(Request $request, RecuperacionDeCuenta $recuperacion): JsonResponse
    {
        $validador = Validator::make($request->all(), [
            'token' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', Password::min(8)->max(255), 'confirmed'],
        ], [
            'password.required' => 'Escribe una contraseña.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);
        if ($validador->fails()) {
            return $this->invalido($validador->errors()->toArray());
        }
        if (! $recuperacion->restablecer($request->string('token')->toString(), $request->string('password')->toString())) {
            return response()->json(['code' => 'invalid_token', 'message' => 'Este enlace ya no sirve. Pide uno nuevo.'], 422);
        }

        return response()->json(['message' => 'Listo, cambiaste tu contraseña. Ya puedes iniciar sesión.']);
    }

    public function confirmarCorreo(Request $request, RecuperacionDeCuenta $recuperacion): JsonResponse
    {
        $token = (string) $request->input('token', '');
        if ($token === '' || mb_strlen($token) > 100 || ! $recuperacion->confirmar($token)) {
            return response()->json(['code' => 'invalid_token', 'message' => 'Este enlace ya no sirve. Pide uno nuevo desde Mi cuenta.'], 422);
        }

        return response()->json(['message' => 'Correo confirmado.']);
    }

    public function reenviarConfirmacion(Request $request, RecuperacionDeCuenta $recuperacion): Response
    {
        $usuario = $request->user('api');
        defer(fn () => $recuperacion->pedirConfirmacion($usuario));

        return response()->noContent();
    }

    public function pedidos(Request $request, PedidosDeLaCuenta $pedidos): JsonResponse
    {
        return response()->json(['data' => $pedidos->lista($request->user('api'))]);
    }

    public function datosDelCheckout(Request $request, PedidosDeLaCuenta $pedidos): JsonResponse
    {
        return response()->json($pedidos->datosParaElCheckout($request->user('api')));
    }

    public function login(Request $request, SesionesDeAcceso $sesiones): JsonResponse
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
            return $this->invalido($validador->errors()->toArray());
        }

        $sesion = $sesiones->iniciar($request->string('email')->toString(), $request->string('password')->toString(), $request->input('device'));

        if ($sesion === null) {
            return response()->json(['code' => 'invalid_credentials', 'message' => 'Correo o contraseña incorrectos.'], 401);
        }

        return $this->conSesion($sesion);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => self::usuario($request->user('api'))]);
    }

    public function logout(Request $request, SesionesDeAcceso $sesiones): Response
    {
        $sesiones->cerrar((string) $request->bearerToken());

        return response()->noContent();
    }

    public function logoutAll(Request $request, SesionesDeAcceso $sesiones): Response
    {
        $sesiones->cerrarTodas($request->user('api'));

        return response()->noContent();
    }

    /** @param array{token: string, expires_at: CarbonInterface|null, user: User} $sesion */
    private function conSesion(array $sesion, int $estado = 200): JsonResponse
    {
        return response()->json([
            'token' => $sesion['token'],
            'token_type' => 'Bearer',
            // Administrador: vence a las 2 horas. Cliente: no vence (null) hasta que cierre su sesión.
            'expires_at' => $sesion['expires_at']?->toIso8601String(),
            // Segundos de vida: Angular calcula el vencimiento con su propio reloj y no depende de que el del servidor coincida.
            'expires_in' => $sesion['expires_at'] === null ? null : max(0, (int) now()->diffInSeconds($sesion['expires_at'], false)),
            'user' => self::usuario($sesion['user']),
        ], $estado);
    }

    /** @param array<string, list<string>> $errores */
    private function invalido(array $errores): JsonResponse
    {
        return response()->json(['code' => 'validation_error', 'message' => 'Revisa los datos ingresados.', 'errors' => $errores], 422);
    }

    /** @return array{name: string, email: string, role: string, email_verified: bool} */
    private static function usuario(User $u): array
    {
        return ['name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'email_verified' => $u->email_verified_at !== null];
    }
}
