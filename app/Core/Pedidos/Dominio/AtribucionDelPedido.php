<?php

declare(strict_types=1);

namespace App\Core\Pedidos\Dominio;

/**
 * Atribución que manda Angular con el checkout (Pasos 1 y 20). Solo pasan los campos conocidos, como texto corto y sin caracteres de control:
 * lo demás se descarta. El último toque (`last_touch`) es el que se muestra en el panel; si falta, se usa `utm`.
 */
final class AtribucionDelPedido
{
    private const TEXTOS = ['ga_client_id', 'ga_session_id', 'fbp', 'fbc', 'fbclid', 'ttclid', 'ttp', 'landing_url', 'page_url', 'user_agent'];

    private const UTM = ['source', 'medium', 'campaign', 'content', 'term'];

    /** @return array<string, mixed>|null */
    public static function limpiar(mixed $crudo): ?array
    {
        if (! is_array($crudo)) {
            return null;
        }
        $limpio = [];
        foreach (self::TEXTOS as $clave) {
            $limpio[$clave] = self::texto($crudo[$clave] ?? null, 500);
        }
        $limpio['utm'] = self::utm($crudo['utm'] ?? null);
        foreach (['first_touch', 'last_touch'] as $toque) {
            $t = $crudo[$toque] ?? null;
            $limpio[$toque] = is_array($t) ? [
                'utm' => self::utm($t['utm'] ?? null),
                'fbclid' => self::texto($t['fbclid'] ?? null, 500),
                'ttclid' => self::texto($t['ttclid'] ?? null, 500),
                'landing_url' => self::texto($t['landing_url'] ?? null, 500),
                'date' => self::texto($t['date'] ?? null, 40),
            ] : null;
        }
        $consentimiento = $crudo['cookie_consent'] ?? null;
        $limpio['cookie_consent'] = ['marketing' => is_array($consentimiento) && ($consentimiento['marketing'] ?? false) === true];

        return $limpio;
    }

    /**
     * Lo que el panel muestra de un pedido: fuente, medio, campaña, anuncio (`utm_content` lleva el ID del anuncio) y página de entrada.
     *
     * @param  array<string, mixed>|null  $tracking
     * @return array{source: ?string, medium: ?string, campaign: ?string, ad_id: ?string, landing_url: ?string}
     */
    public static function resumen(?array $tracking): array
    {
        $utm = $tracking['last_touch']['utm'] ?? $tracking['utm'] ?? [];
        $landing = $tracking['last_touch']['landing_url'] ?? $tracking['landing_url'] ?? null;

        return ['source' => $utm['source'] ?? null, 'medium' => $utm['medium'] ?? null, 'campaign' => $utm['campaign'] ?? null, 'ad_id' => $utm['content'] ?? null, 'landing_url' => $landing];
    }

    /** @return array<string, ?string> */
    private static function utm(mixed $crudo): array
    {
        $utm = is_array($crudo) ? $crudo : [];

        return array_combine(self::UTM, array_map(fn (string $k) => self::texto($utm[$k] ?? null, 200), self::UTM));
    }

    private static function texto(mixed $valor, int $largo): ?string
    {
        if (! is_string($valor)) {
            return null;
        }
        $t = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', mb_scrub($valor)));

        return $t === '' ? null : mb_substr($t, 0, $largo);
    }
}
