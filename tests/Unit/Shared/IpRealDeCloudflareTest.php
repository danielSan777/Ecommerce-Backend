<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Infraestructura\Http\Middleware\IpRealDeCloudflare;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class IpRealDeCloudflareTest extends TestCase
{
    private function ipVista(string $origen, ?string $cabecera): string
    {
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => $origen]);
        if ($cabecera !== null) {
            $request->headers->set('CF-Connecting-IP', $cabecera);
        }

        (new IpRealDeCloudflare)->handle($request, fn () => new Response);

        return $request->ip();
    }

    public function test_desde_cloudflare_usa_la_ip_real_del_visitante(): void
    {
        $this->assertSame('190.15.20.30', $this->ipVista('104.16.1.1', '190.15.20.30'));
    }

    public function test_desde_cloudflare_por_ipv6_tambien_funciona(): void
    {
        $this->assertSame('190.15.20.30', $this->ipVista('2606:4700::1', '190.15.20.30'));
    }

    public function test_si_no_viene_de_cloudflare_ignora_el_encabezado_falsificado(): void
    {
        $this->assertSame('203.0.113.9', $this->ipVista('203.0.113.9', '1.2.3.4'));
    }

    public function test_un_encabezado_que_no_es_una_ip_se_ignora(): void
    {
        $this->assertSame('104.16.1.1', $this->ipVista('104.16.1.1', 'no-es-una-ip'));
    }
}
