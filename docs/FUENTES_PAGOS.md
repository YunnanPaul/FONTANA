DOCUMENTO HISTÓRICO DE LA ENTREGA ANTERIOR.
La revisión actual, sus cambios y resultados están en ../LEEME_PRIMERO.md e ../INFORME_REVISION.md.
Las instrucciones y cifras de este documento pueden no corresponder a la versión corregida.

# Contratos externos y dependencias

Consultado: 2026-09-06. Solo documentación primaria para contratos de API.

| Recurso | Uso en Fontana |
|---|---|
| https://www.mercadopago.com.pe/developers/es/docs/checkout-api-payments/integration-configuration/yape | `mp.yape({phoneNumber,otp}).create()`, números de prueba, Payments API |
| https://www.mercadopago.com.pe/developers/es/docs/checkout-bricks/payment-brick/default-rendering | Inicialización, amount, Payment Brick, callbacks y unmount |
| https://www.mercadopago.com.pe/developers/es/docs/checkout-bricks/payment-brick/payment-submission/cards | Envío de formData tokenizado al backend |
| https://www.mercadopago.com.pe/developers/es/docs/checkout-bricks/integration-test/test-payment-flow | Pruebas de tarjeta y titular APRO |
| https://www.mercadopago.com.pe/developers/es/docs/checkout-api-payments/additional-content/your-integrations/notifications/webhooks | Firma x-signature y consulta autenticada posterior |
| https://www.mercadopago.com.pe/developers/es/reference/payments/_payments/post | Alta de pago y X-Idempotency-Key |
| https://www.mercadopago.com.pe/developers/es/reference/payments/_payments_id/get | Recuperación de pago |

Endpoint fijo: `https://api.mercadopago.com/v1/payments`. SDK remoto oficial: `https://sdk.mercadopago.com/js/v2`. Solo se admiten claves TEST- de Payments API/Bricks; no se usa Orders API ni sus credenciales. Se comprueba live_mode=false en todas las respuestas aplicadas. No se ha homologado la aplicación ni validado la cuenta del usuario.

## Generador de QR local

`js/vendor/fontana-qr.js` empaqueta el módulo `vendor/QRCode` de `qrcode-terminal` 0.12.0, disponible en el entorno de desarrollo. Algoritmo original QRCode de Kazuhiko Arase y adaptación mantenida por la distribución. Se incluyen las licencias Apache 2.0/MIT en `js/vendor/LICENSE-QR.txt`. No se carga un servicio remoto ni se envían enlaces/importe a un generador de imágenes externo. Node no se necesita para ejecutar el sistema PHP.

El QR es un enlace al simulador propio, no una codificación de billetera ni un formato bancario interoperable. Decodificado en pruebas con OpenCV; no se utilizó OCR.
