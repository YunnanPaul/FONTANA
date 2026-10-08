<?php
/** OPCIONAL: copiar a pagos.php SOLO al trabajar con una base duplicada *_demo.
 * Los valores omitidos se completan desde pagos.example.php.
 * La conexión se configura por separado en conexion.php / FONTANA_DB_NAME.
 */
return [
    'checkout_flow' => 'integrado',
    'mode' => 'demo', // o mercadopago_sandbox, con llaves TEST-
    'academic_record_sales' => true,
    // Declara el nombre exacto SOLO si esta base es exclusivamente académica.
    // Vacío conserva la comprobación por sufijo _demo, _academico o _pruebas.
    'academic_database' => '',
    'base_url' => '', // Ejemplo: http://192.168.1.20/fontana_mejorado
    'mercadopago' => ['public_key'=>'', 'access_token'=>'', 'webhook_secret'=>''],
];
