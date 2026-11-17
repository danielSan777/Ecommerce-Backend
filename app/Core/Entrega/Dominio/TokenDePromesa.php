<?php

declare(strict_types=1);

namespace App\Core\Entrega\Dominio;

use Carbon\CarbonImmutable;

/**
 * `promise_token`: lleva firmados el ciclo prometido y la hora de emisión (Paso 9). Angular lo guarda al tocar
 * «Personalizar» y lo envía al crear el pedido; un token alterado no da gracia.
 */
final class TokenDePromesa
{
    public function emitir(string $ciclo, CarbonImmutable $emitidoEn): string
    {
        $carga = $this->base64(json_encode(['c' => $ciclo, 'i' => $emitidoEn->getTimestamp()], JSON_THROW_ON_ERROR));

        return $carga.'.'.$this->firma($carga);
    }

    /** @return array{cycle_date: string, issued_at: CarbonImmutable}|null null si el token es inválido o fue alterado */
    public function verificar(string $token): ?array
    {
        $partes = explode('.', $token);
        if (count($partes) !== 2 || ! hash_equals($this->firma($partes[0]), $partes[1])) {
            return null;
        }
        $datos = json_decode((string) base64_decode(strtr($partes[0], '-_', '+/')), true);

        return is_array($datos) && isset($datos['c'], $datos['i'])
            ? ['cycle_date' => (string) $datos['c'], 'issued_at' => CarbonImmutable::createFromTimestampUTC((int) $datos['i'])]
            : null;
    }

    private function firma(string $carga): string
    {
        return $this->base64(hash_hmac('sha256', $carga, (string) config('app.key'), true));
    }

    private function base64(string $texto): string
    {
        return rtrim(strtr(base64_encode($texto), '+/', '-_'), '=');
    }
}
