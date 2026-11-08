<?php

return [
    /*
    | Código (SKU) automático de los productos: PREFIJO-NNN (ejemplo JDS-001).
    | Para pasar a más dígitos basta cambiar CATALOGO_SKU_DIGITOS en el .env (ejemplo 4 → JDS-0001).
    | Los códigos ya creados no cambian, y si el número crece más que los dígitos (JDS-1000) simplemente se alarga.
    */
    'sku_prefijo' => env('CATALOGO_SKU_PREFIJO', 'JDS'),
    'sku_digitos' => max(1, (int) env('CATALOGO_SKU_DIGITOS', 3)),

    // Descuento máximo que se puede poner a un producto (porcentaje).
    'descuento_maximo' => 90,
];
