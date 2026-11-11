<?php

declare(strict_types=1);

namespace App\Core\Configurador\Dominio\Excepciones;

use RuntimeException;

/** No se puede eliminar algo del configurador porque otra cosa depende de ello (se responde 409 con el motivo). */
final class ConfiguradorEnUso extends RuntimeException {}
