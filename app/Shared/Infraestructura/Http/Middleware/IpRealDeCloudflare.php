<?php

declare(strict_types=1);

namespace App\Shared\Infraestructura\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel confía en el proxy de Cloudflare y lee la IP real del visitante de `CF-Connecting-IP`
 * (Paso 4). Solo se acepta ese encabezado cuando la petición llega desde un rango de Cloudflare;
 * si llega de otro origen, se ignora para que nadie pueda falsificar su IP.
 *
 * Rangos oficiales: https://www.cloudflare.com/ips/ (revisar cada cierto tiempo).
 */
final class IpRealDeCloudflare
{
    public const RANGOS = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $origen = $request->server->get('REMOTE_ADDR');
        $ipReal = $request->headers->get('CF-Connecting-IP');

        if (is_string($origen) && is_string($ipReal) && filter_var($ipReal, FILTER_VALIDATE_IP) && IpUtils::checkIp($origen, self::RANGOS)) {
            $request->server->set('REMOTE_ADDR', $ipReal);
        }

        return $next($request);
    }
}
