<?php

namespace Database\Seeders;

use App\Core\Configuracion\Infraestructura\Modelos\Ajuste;
use Illuminate\Database\Seeder;

/**
 * Parámetros iniciales del negocio, tomados del documento maestro y de los términos y condiciones.
 * Idempotente: no pisa un valor que el administrador ya cambió desde el panel.
 */
class AjustesIniciales extends Seeder
{
    /** @var array<string, array{0: mixed, 1: string}> */
    private const VALORES = [
        'card_surcharge_percent' => [6, 'Recargo por pago con tarjeta (%)'],
        'transfer_window_hours' => [12, 'Horas para pagar por transferencia'],
        'free_shipping_min_planters' => [2, 'Macetas mínimas para envío gratis'],
        'shipping_rates_cents' => [['A' => 350, 'B' => 625, 'C' => 500], 'Tarifa de envío por zona, en centavos'],
        'cutoff_weekday' => ['20:00', 'Cierre del ciclo de producción de lunes a viernes (hora de Guayaquil)'],
        'cutoff_saturday' => ['16:00', 'Cierre del ciclo de producción del sábado (hora de Guayaquil)'],
        'whatsapp_phone' => ['593999283765', 'WhatsApp de la tienda (solo dígitos, con código de país)'],
        'contact_email' => ['ayuda@jardindesele.com', 'Correo de contacto'],
    ];

    public function run(): void
    {
        foreach (self::VALORES as $clave => [$valor, $descripcion]) {
            Ajuste::query()->firstOrCreate(['key' => $clave], ['value' => $valor, 'description' => $descripcion]);
        }
    }
}
