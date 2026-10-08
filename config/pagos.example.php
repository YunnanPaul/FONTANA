<?php
/** Fontana académico: copiar como pagos.php o usar variables de entorno.
 * No publicar llaves privadas. Mercado Pago utiliza credenciales TEST- de Payments API.
 * Referencias y puesta en marcha: README_PAGOS.md.
 */
return [
    // Esta configuración SOLO controla el pago avanzado, no el registro habitual.
    // demo: simulador local, sin cuenta, Internet ni dinero real (valor inicial).
    // mercadopago_sandbox: API externa de pruebas para Yape y tarjetas.
    // Plin y efectivo siguen siendo simulaciones identificadas en este modo.
    // Los modos siguientes se conservan SOLO para compatibilidad:
    // manual: conserva QR estático/POS, con confirmación del cajero.
    // sandbox: pruebas SIN ventas reales ni descuento de stock.
    // production: cobros reales; activar únicamente después de homologar.
    // Interfaz de ventas: normal = QR del comercio/confirmación manual (sin tablas nuevas).
    // integrado = abre automáticamente la orden del proveedor configurado.
    // No cambia el entorno ni autoriza registros de prueba por sí mismo.
    'checkout_flow' => getenv('FONTANA_CHECKOUT_FLOW') ?: 'normal',
    'mode' => getenv('FONTANA_PAYMENTS_MODE') ?: 'demo',
    'base_url' => rtrim(getenv('FONTANA_PUBLIC_URL') ?: '', '/'),
    'reservation_minutes' => 15,
    // false: pruebas separadas, sin tocar ventas/caja/stock del negocio.
    // true: ciclo completo SOLO en una base separada cuyo nombre termina
    // en _demo, _academico o _pruebas; o nombre exacto autorizado en academic_database.
    'academic_record_sales' => false,
    // Para ciclo completo también puedes declarar aquí el nombre EXACTO de tu base
    // académica (nunca una base con operaciones reales). No cambia su nombre ni la crea.
    'academic_database' => '',
    'mercadopago' => [
        // Este adaptador utiliza Payments API /v1/payments, NO Orders API.
        // Solo acepta credenciales TEST- de Checkout API/Bricks. No APP_USR-.
        'public_key' => getenv('MP_TEST_PUBLIC_KEY') ?: '',
        'access_token' => getenv('MP_TEST_ACCESS_TOKEN') ?: '',
        'webhook_secret' => getenv('MP_WEBHOOK_SECRET') ?: '',
        'timeout_seconds' => 18,
    ],
    'environments' => [
        'sandbox' => [
            'merchant_code' => getenv('IZIPAY_SANDBOX_MERCHANT_CODE') ?: '',
            'api_key' => getenv('IZIPAY_SANDBOX_API_KEY') ?: '',
            'hash_key' => getenv('IZIPAY_SANDBOX_HASH_KEY') ?: '',
            'rsa_public_key' => getenv('IZIPAY_SANDBOX_RSA_PUBLIC_KEY') ?: '',
        ],
        'production' => [
            'merchant_code' => getenv('IZIPAY_PRODUCTION_MERCHANT_CODE') ?: '',
            'api_key' => getenv('IZIPAY_PRODUCTION_API_KEY') ?: '',
            'hash_key' => getenv('IZIPAY_PRODUCTION_HASH_KEY') ?: '',
            'rsa_public_key' => getenv('IZIPAY_PRODUCTION_RSA_PUBLIC_KEY') ?: '',
        ],
    ],
];
