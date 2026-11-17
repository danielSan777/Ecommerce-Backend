<?php

declare(strict_types=1);

namespace App\Core\Entrega\Aplicacion\CasosDeUso;

use App\Core\Entrega\Dominio\MotorDeEntrega;
use App\Core\Entrega\Dominio\TokenDePromesa;
use Carbon\CarbonImmutable;

/** Promesa de entrega y ventanas para programar (contratos del Paso 0). Todo lo calcula Laravel; Angular solo lo dibuja. */
final class ConsultarEntrega
{
    private const DIAS = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    private const MESES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(private readonly MotorDeEntrega $motor, private readonly TokenDePromesa $tokens) {}

    /** @return array<string, mixed> */
    public function promesa(int $diasAdicionales = 0, ?CarbonImmutable $ahora = null): array
    {
        $ahora ??= CarbonImmutable::now('UTC');
        $ciclo = $this->motor->ciclo($ahora);
        $despacho = $this->motor->despacho($ciclo['cycle_date']);
        $ventana = $this->motor->ventana($despacho, $diasAdicionales);

        return [
            'server_now' => $ahora->format('Y-m-d\TH:i:s\Z'),
            'cycle_date' => $ciclo['cycle_date'],
            'cutoff_at' => $ciclo['cutoff_at']->format('Y-m-d\TH:i:s\Z'),
            'dispatch_date' => $despacho,
            'window' => $ventana,
            'label' => 'Recíbelo entre '.$this->dias($ventana['start'], $ventana['end']),
            'countdown_label' => $this->cuentaRegresiva($ahora, $ciclo['cutoff_at']),
            'promise_token' => $this->tokens->emitir($ciclo['cycle_date'], $ahora),
            'amazon_note' => 'Región Amazónica: considera 1 día adicional.',
        ];
    }

    /** @return array{windows: list<array{start: string, end: string, available: bool}>} */
    public function ventanas(int $diasAdicionales = 0, ?CarbonImmutable $ahora = null): array
    {
        $ahora ??= CarbonImmutable::now('UTC');
        $primera = $this->promesa($diasAdicionales, $ahora)['window']['start'];

        return ['windows' => array_map(fn ($v) => $v + ['available' => true], $this->motor->ventanasDesde($primera, $diasAdicionales))];
    }

    private function cuentaRegresiva(CarbonImmutable $ahora, CarbonImmutable $corte): string
    {
        $minutos = max(0, intdiv($corte->getTimestamp() - $ahora->getTimestamp(), 60));
        if ($minutos >= 24 * 60) {
            $local = $corte->setTimezone('America/Guayaquil');

            return sprintf('Pídelo antes del %s a las %s', self::DIAS[$local->dayOfWeekIso], $local->format('H:i'));
        }

        return 'Pídelo en las próximas '.($minutos >= 60 ? intdiv($minutos, 60).' h '.($minutos % 60).' min' : $minutos.' min');
    }

    /** «jueves 12 y viernes 13 de noviembre»; si cambia el mes, «lunes 30 de noviembre y martes 1 de diciembre». */
    private function dias(string $inicio, string $fin): string
    {
        [, $mi, $di] = array_map('intval', explode('-', $inicio));
        [, $mf, $df] = array_map('intval', explode('-', $fin));
        $semana = fn (string $f) => self::DIAS[CarbonImmutable::parse($f, 'America/Guayaquil')->dayOfWeekIso];

        return $mi === $mf
            ? "{$semana($inicio)} {$di} y {$semana($fin)} {$df} de ".self::MESES[$mf]
            : "{$semana($inicio)} {$di} de ".self::MESES[$mi]." y {$semana($fin)} {$df} de ".self::MESES[$mf];
    }
}
