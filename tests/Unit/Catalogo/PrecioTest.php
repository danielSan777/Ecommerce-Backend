<?php

declare(strict_types=1);

namespace Tests\Unit\Catalogo;

use App\Core\Catalogo\Dominio\ValueObjects\Precio;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PrecioTest extends TestCase
{
    public function test_sin_descuento_el_final_es_el_base(): void
    {
        $precio = Precio::sinDescuento(2500);

        $this->assertSame(0, $precio->descuentoCents());
        $this->assertSame(0, $precio->descuentoPorcentaje());
    }

    public function test_calcula_el_porcentaje_de_descuento(): void
    {
        $precio = new Precio(2500, 2250);

        $this->assertSame(250, $precio->descuentoCents());
        $this->assertSame(10, $precio->descuentoPorcentaje());
    }

    public function test_el_final_no_puede_superar_al_base(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Precio(2000, 2500);
    }
}
