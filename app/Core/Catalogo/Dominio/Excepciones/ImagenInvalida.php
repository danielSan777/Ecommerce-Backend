<?php

declare(strict_types=1);

namespace App\Core\Catalogo\Dominio\Excepciones;

use RuntimeException;

/** El archivo subido no es una imagen que podamos aceptar (se responde 422 con el motivo en español). */
final class ImagenInvalida extends RuntimeException {}
