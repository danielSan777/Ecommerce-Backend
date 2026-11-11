<?php

declare(strict_types=1);

namespace Tests\Unit\Catalogo;

use App\Core\Catalogo\Dominio\Servicios\TemporadaDeCampana;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemporadaDeCampanaTest extends TestCase
{
    /** @return array<string, array{string, string, string, string, string, int, string}> */
    public static function casos(): array
    {
        // [inicio, fin, evento, hoy, estado, año, fecha del evento]
        return [
            'San Valentín en temporada' => ['01-02', '02-14', '02-14', '2027-01-20', 'active', 2027, '2027-02-14'],
            'el último día de la temporada sigue activa' => ['01-02', '02-14', '02-14', '2027-02-14', 'active', 2027, '2027-02-14'],
            'antes de empezar muestra el año en curso' => ['01-02', '02-14', '02-14', '2027-01-01', 'upcoming', 2027, '2027-02-14'],
            'en noviembre ya se anuncia la del año siguiente' => ['01-02', '02-14', '02-14', '2026-11-06', 'upcoming', 2027, '2027-02-14'],
            'después de terminar muestra el año siguiente (marzo 2027 → 2028)' => ['01-02', '02-14', '02-14', '2027-03-10', 'upcoming', 2028, '2028-02-14'],
            'Navidad en diciembre' => ['11-15', '12-25', '12-25', '2026-12-01', 'active', 2026, '2026-12-25'],
            'Navidad terminada pasa al año siguiente' => ['11-15', '12-25', '12-25', '2026-12-30', 'upcoming', 2027, '2027-12-25'],
            'temporada que cruza el año, en enero' => ['12-01', '01-10', '12-31', '2027-01-05', 'active', 2026, '2026-12-31'],
            'temporada que cruza el año, en diciembre' => ['12-01', '01-10', '12-31', '2026-12-15', 'active', 2026, '2026-12-31'],
        ];
    }

    #[DataProvider('casos')]
    public function test_calcula_estado_ano_y_fecha_del_evento(string $inicio, string $fin, string $evento, string $hoy, string $estado, int $anio, string $fechaEvento): void
    {
        $r = (new TemporadaDeCampana)->calcular($inicio, $fin, $evento, $hoy);

        $this->assertSame($estado, $r['estado']);
        $this->assertSame($anio, $r['anio']);
        $this->assertSame($fechaEvento, $r['fechaEvento']);
    }

    /** @return array<string, array{string, string, string}> temporada 01-02 a 02-14; el mensaje se muestra hasta el 10-03 */
    public static function despedida(): array
    {
        return [
            'el último día de temporada sigue activa' => ['2027-02-14', 'active', ''],
            'el día siguiente empieza la despedida' => ['2027-02-15', 'closing', ''],
            'el último día de la despedida' => ['2027-03-10', 'closing', ''],
            'pasada la fecha ya no se muestra nada' => ['2027-03-11', 'upcoming', ''],
            'en noviembre sigue sin mostrarse' => ['2026-11-06', 'upcoming', ''],
            'otra vez en temporada' => ['2027-01-02', 'active', ''],
        ];
    }

    #[DataProvider('despedida')]
    public function test_la_despedida_va_desde_el_dia_siguiente_al_fin_hasta_la_fecha_elegida(string $hoy, string $estado, string $_): void
    {
        $this->assertSame($estado, (new TemporadaDeCampana)->calcular('01-02', '02-14', '02-14', $hoy, '03-10')['estado']);
    }

    public function test_sin_fecha_de_despedida_nunca_hay_estado_closing(): void
    {
        $this->assertSame('upcoming', (new TemporadaDeCampana)->calcular('01-02', '02-14', '02-14', '2027-02-20')['estado']);
    }

    public function test_la_despedida_puede_cruzar_el_ano(): void
    {
        // Navidad: 11-15 a 12-25; el mensaje se muestra hasta el 01-10
        $t = new TemporadaDeCampana;
        $this->assertSame('closing', $t->calcular('11-15', '12-25', '12-25', '2027-01-05', '01-10')['estado']);
        $this->assertSame('closing', $t->calcular('11-15', '12-25', '12-25', '2026-12-28', '01-10')['estado']);
        $this->assertSame('upcoming', $t->calcular('11-15', '12-25', '12-25', '2027-01-11', '01-10')['estado']);
    }

    public function test_valida_que_la_fecha_limite_caiga_entre_el_fin_y_el_siguiente_inicio(): void
    {
        $t = new TemporadaDeCampana;
        $this->assertTrue($t->despedidaValida('01-02', '02-14', '03-10'));
        $this->assertTrue($t->despedidaValida('01-02', '02-14', '01-01')); // el día antes de empezar la siguiente
        $this->assertFalse($t->despedidaValida('01-02', '02-14', '02-14')); // el mismo día que termina
        $this->assertFalse($t->despedidaValida('01-02', '02-14', '01-20')); // dentro de la temporada
        $this->assertTrue($t->despedidaValida('11-15', '12-25', '01-10')); // cruza el año
        $this->assertTrue($t->despedidaValida('11-10', '11-10', '11-12')); // temporada de un solo día
        $this->assertFalse($t->despedidaValida('11-10', '11-10', '11-10'));
        $this->assertSame('closing', $t->calcular('11-10', '11-10', '11-10', '2026-11-11', '11-12')['estado']);
    }
}
