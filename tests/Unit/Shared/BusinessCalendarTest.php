<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Dominio\BusinessCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class BusinessCalendarTest extends TestCase
{
    private BusinessCalendar $calendario;

    protected function setUp(): void
    {
        $this->calendario = new BusinessCalendar;
    }

    public function test_un_instante_cerca_de_medianoche_utc_pertenece_al_dia_anterior_en_guayaquil(): void
    {
        // 2026-11-10 03:30 UTC = 2026-11-09 22:30 en Guayaquil (UTC-5)
        $this->assertSame('2026-11-09', $this->calendario->fechaDeNegocio(new CarbonImmutable('2026-11-10 03:30:00', 'UTC')));
    }

    public function test_el_cierre_de_las_20_horas_de_guayaquil_es_la_1_utc_del_dia_siguiente(): void
    {
        $this->assertSame('2026-11-11 01:00', $this->calendario->instanteUtc('2026-11-10', '20:00')->format('Y-m-d H:i'));
    }

    public function test_dias_de_la_semana_y_domingo(): void
    {
        $this->assertSame(2, $this->calendario->diaDeLaSemana('2026-11-10'));   // martes
        $this->assertTrue($this->calendario->esDomingo('2026-11-15'));
        $this->assertFalse($this->calendario->esDomingo('2026-11-14'));
    }

    public function test_sumar_dias_cruza_fin_de_mes_y_de_anio(): void
    {
        $this->assertSame('2026-12-01', $this->calendario->sumarDias('2026-11-30', 1));
        $this->assertSame('2027-01-01', $this->calendario->sumarDias('2026-12-31', 1));
    }
}
