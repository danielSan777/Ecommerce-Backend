<?php

declare(strict_types=1);

namespace App\Core\Cuentas\Aplicacion;

use RuntimeException;

/** La contraseña era correcta, pero el cliente todavía no confirmó su correo: la cuenta no abre sesión hasta que lo haga. */
final class CorreoSinConfirmar extends RuntimeException {}
