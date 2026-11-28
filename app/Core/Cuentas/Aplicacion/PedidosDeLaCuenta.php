<?php

declare(strict_types=1);

namespace App\Core\Cuentas\Aplicacion;

use App\Core\Pedidos\Aplicacion\CasosDeUso\ConsultarPedido;
use App\Core\Pedidos\Infraestructura\Modelos\PedidoModelo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pedidos de un cliente con cuenta. Son suyos: los que hizo con la sesión iniciada y, solo si ya confirmó su correo, los que hizo antes
 * como invitado con ese mismo correo. Sin correo confirmado, registrarse con el correo de otra persona no abre sus pedidos.
 */
final class PedidosDeLaCuenta
{
    public function __construct(private readonly ConsultarPedido $consultar) {}

    /** @return Builder<PedidoModelo> */
    public function consulta(User $usuario): Builder
    {
        return PedidoModelo::query()->where(function (Builder $q) use ($usuario) {
            $q->where('user_id', $usuario->id);
            if ($usuario->email_verified_at !== null) {
                $q->orWhereHas('cliente', fn (Builder $c) => $c->where('email', mb_strtolower($usuario->email)));
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function lista(User $usuario): array
    {
        return $this->consulta($usuario)->with('items')->latest('id')->limit(100)->get()->map(fn (PedidoModelo $p) => $this->consultar->resumen($p))->all();
    }

    /**
     * Datos para llenar el checkout: los del último pedido de la cuenta (envío, facturación y contacto). Sin pedidos, solo nombre y correo.
     *
     * @return array<string, mixed>
     */
    public function datosParaElCheckout(User $usuario): array
    {
        $ultimo = $this->consulta($usuario)->latest('id')->first();
        $base = ['name' => $usuario->name, 'email' => $usuario->email, 'shipping' => null, 'billing' => null, 'contact' => null];
        if ($ultimo === null) {
            return $base;
        }
        // Con `province_name` y `canton_name`: el formulario del checkout elige provincia y cantón por su nombre
        return ['shipping' => (array) $ultimo->shipping, 'billing' => $ultimo->billing, 'contact' => $ultimo->contact] + $base;
    }
}
