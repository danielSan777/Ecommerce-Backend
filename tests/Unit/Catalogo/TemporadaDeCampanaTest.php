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
}
