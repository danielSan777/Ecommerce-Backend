<?php

declare(strict_types=1);

namespace App\Core\Seguimiento\Aplicacion;

use App\Core\Configuracion\Dominio\Repositorios\RepositorioConfiguracion;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;

/**
 * Interruptor «Mostrar aviso de cookies» del panel (ajuste `show_cookie_notice`, apagado por defecto).
 * Apagado: la web carga GTM desde la primera visita, no muestra el aviso y, para el servidor, todos los compradores cuentan como
 * permiso de marketing dado. Encendido: GTM espera al «Aceptar» y el servidor respeta lo que eligió cada comprador.
 */
final class AvisoDeCookies
{
    public const AJUSTE = 'show_cookie_notice';

    public function __construct(private readonly RepositorioConfiguracion $ajustes) {}

    public function encendido(): bool
    {
        return filter_var($this->ajustes->obtener(self::AJUSTE, false), FILTER_VALIDATE_BOOLEAN);
    }

    public function cambiar(bool $encendido): void
    {
        $this->ajustes->guardar(self::AJUSTE, $encendido);
    }

    /** ¿Puede el servidor mandar este pedido a Meta y TikTok? Con el aviso apagado, siempre; encendido, solo si la persona aceptó. */
    public function permiteMarketing(PedidoModelo $pedido): bool
    {
        return ! $this->encendido() || ($pedido->tracking['cookie_consent']['marketing'] ?? false) === true;
    }
}
