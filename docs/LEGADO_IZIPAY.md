DOCUMENTO HISTÓRICO DE LA ENTREGA ANTERIOR.
La revisión actual, sus cambios y resultados están en ../LEEME_PRIMERO.md e ../INFORME_REVISION.md.
Las instrucciones y cifras de este documento pueden no corresponder a la versión corregida.

# Fontana · Pagos con monto y confirmación

Versión de entrega: 6 de septiembre de 2026.
Base de trabajo: `fontana_mejorado(4).zip`.

## Qué cambia

El flujo ahora es **productos → orden de cobro → confirmación → venta e inventario**.
Se mantiene el sistema PHP/MySQL, sus colores y navegación, sus métodos Efectivo/Yape/Plin/Tarjeta y la consulta DNI/RUC. Se añade «Pagos y cobros» para recuperar órdenes, ver su estado y revisar incidencias.

El total se muestra mientras se seleccionan productos, pero el importe válido se vuelve a calcular en el servidor con los precios de la base. Una orden congela sus precios y cantidades. No existe una venta ni una salida de stock hasta confirmar el pago. Mientras tanto se reservan existencias para evitar venderlas a otra caja.

**Estado de esta entrega:** integración escrita y pruebas locales realizadas; no activada con un comercio real ni homologada. No se realizó ningún cobro, afiliación, cambio de cuenta bancaria o despliegue en tu servidor. En el entorno de construcción no estaban disponibles mysqli, cURL ni MySQL: la migración y las transacciones deben probarse en tu entorno antes de producción.

## QR con importe: cómo funciona realmente

Se integra **Izipay Punto Web / SDK JavaScript**, con una sesión creada por el servidor para el importe de cada orden. Yape y Plin se encaminan al **QR interoperable** de la pasarela. No se modifica un PNG estático ni se genera un QR genérico con texto, porque eso no constituye una orden bancaria con importe.

Ejemplo: dos productos de S/ 5.00 y tres de S/ 2.50 producen una orden de **S/ 17.50**. Ese importe es enviado a la sesión del checkout. Cuando Izipay presenta su QR y la billetera compatible lo lee, el importe procede de esa orden. La disponibilidad de billeteras, límites y comisiones dependen del servicio contratado y deben confirmarse con Izipay.

El código usa el canal `QR`, no la modalidad de código de compra de Yape ni las notificaciones push de Plin Interbank. Son modalidades diferentes. Si Izipay devuelve únicamente `QR`, el sistema conserva Yape/Plin como método elegido por el cajero, **sin afirmar que identificó la billetera realmente utilizada**.

Las tarjetas se cobran en el **formulario web de Izipay**. No se programó una conexión a un terminal POS físico ni se leen tarjetas con hardware local. Fontana no captura números de tarjeta, fecha de vencimiento ni CVV. La pasarela puede requerir datos reales del pagador y autenticación adicional. Los datos del pagador no reemplazan los del comprobante.

## Instalación sobre tu sistema actual

1. **Detén temporalmente las operaciones y respalda tanto los archivos como la base MySQL.** Haz primero todo el procedimiento en una copia de pruebas. Resuelve los cobros que tu versión anterior ya registró antes de confirmar el dinero; el nuevo código no puede determinar retrospectivamente si se pagaron.
2. Descomprime el paquete en una carpeta de prueba. Conserva tu configuración de conexión y `config/api.php`. Esta entrega mantiene el archivo de la API documental que venía en el ZIP; el paquete debe tratarse como privado. No lo publiques en un repositorio ni en una carpeta de descargas del sitio.
3. Configura la conexión MySQL. Los valores locales siguen siendo `127.0.0.1`, usuario `root`, contraseña vacía y base `fontana_db`. En producción debes reemplazarlos por las variables descritas abajo y una cuenta propia con contraseña. Inicia sesión como administrador y abre **Pagos y cobros → Configuración → Instalar / verificar ampliación**. Marca que tienes respaldo y ejecuta la ampliación.
4. Prueba primero el efectivo y el cobro manual. Después configura Izipay en `sandbox`, comprueba las notificaciones y completa la lista de aceptación de `docs/PRUEBAS_Y_DESPLIEGUE.md`. Solo después cambia a `production` con las credenciales reales y los métodos contratados habilitados.

**No importes `fontana_db.sql` ni `sql/fontana_db.sql` para actualizar una instalación existente.** Se conservan como archivos originales y contienen `DROP TABLE`. El archivo correcto de esta mejora es `sql/migracion_pagos_api.sql`; usa preferentemente el instalador, que también añade condicionalmente `cierres_caja.total_efectivo`.

La ampliación crea `pagos_ordenes`, `pagos_lineas` y `pagos_eventos`, y añade una columna nullable a caja. No elimina ni reescribe ventas históricas. Las operaciones de esquema MySQL no son una única transacción reversible; ante un error revisa el log y los permisos y repite el instalador en la copia. No restaures sobre cobros nuevos sin conciliarlos previamente.

## Configurar Izipay sin exponer secretos

Copia `config/pagos.example.php` como `config/pagos.php`. La configuración no se edita desde un formulario web. También puedes usar variables de entorno, sin crear otro archivo.

Elige el **nuevo botón de pagos Punto Web**. No uses las credenciales de otro producto de Izipay/Lyra. La documentación de Izipay distingue el código de comercio, la clave de API del nuevo botón, la clave Hash y la clave pública RSA.

| Campo del archivo | Qué debes colocar |
|---|---|
| `mode` | `manual`, `sandbox` o `production` |
| `base_url` | URL pública HTTPS de la carpeta del sistema, sin `/` final ni parámetros |
| `environments.sandbox.merchant_code` | Código de comercio de pruebas |
| `environments.sandbox.api_key` | Clave de API del nuevo botón de pagos de pruebas |
| `environments.sandbox.hash_key` | Clave Hash de pruebas para verificar notificaciones |
| `environments.sandbox.rsa_public_key` | Clave pública RSA de Izipay de pruebas |
| `environments.production.*` | Los mismos cuatro datos, pero del comercio real |

**No envíes claves privadas, claves Hash ni contraseñas por chat.** La clave de API y la clave Hash permanecen en el servidor. Solo se devuelve al navegador la sesión de pago y la clave pública RSA que usa el SDK. No mezcles llaves sandbox con production. Conserva las llaves del entorno anterior mientras existan operaciones pendientes de notificar.

Variables de entorno equivalentes:

```text
FONTANA_PAYMENTS_MODE=manual
FONTANA_PUBLIC_URL=https://tu-dominio.example/fontana
IZIPAY_SANDBOX_MERCHANT_CODE=
IZIPAY_SANDBOX_API_KEY=
IZIPAY_SANDBOX_HASH_KEY=
IZIPAY_SANDBOX_RSA_PUBLIC_KEY=
IZIPAY_PRODUCTION_MERCHANT_CODE=
IZIPAY_PRODUCTION_API_KEY=
IZIPAY_PRODUCTION_HASH_KEY=
IZIPAY_PRODUCTION_RSA_PUBLIC_KEY=
```

Una variable no se carga automáticamente por colocar un archivo `.env`: este proyecto no incorpora un lector de `.env`. Configúrala en PHP-FPM/Apache/hosting, o utiliza el archivo PHP de configuración.

Conexión a la base:

```text
FONTANA_DB_HOST=127.0.0.1
FONTANA_DB_PORT=3306
FONTANA_DB_NAME=fontana_db
FONTANA_DB_USER=usuario_fontana
FONTANA_DB_PASSWORD=contraseña_segura
```

El SDK solicita la sesión mediante el backend y abre el formulario del proveedor. El adaptador está en `includes/izipay.php`. Antes de activar producción, contrasta las credenciales y el contrato del servicio de tokens con el panel/manual entregado para tu comercio. No se dispone de una respuesta real de ese comercio para dar por superada esa validación.

## Notificación del pago

La URL calculada es:

```text
https://tu-dominio.example/fontana/webhook_izipay.php
```

Se envía al SDK como `urlIPN`. El servicio admite POST JSON, comprueba HMAC-SHA256 sobre los bytes exactos de `payloadHttp` y contrasta la autorización con la orden: comercio, transacción, número de orden, moneda, importe y método. Un `code=00` no firmado, una captura de pantalla o un número de operación escrito por el usuario **no aprueban un pago de la pasarela**.

El callback del navegador envía la misma respuesta al servidor para validarla. La notificación IPN permite registrar el resultado sin depender de que siga abierta la pantalla. La recepción correcta y los reintentos deben verificarse con Izipay en el entorno de pruebas. Un servidor en `localhost` no recibe notificaciones públicas. No se desactiva TLS ni se aceptan orígenes arbitrarios para generar sesiones.

**No se implementó una consulta REST periódica de conciliación contra el banco, ni reembolsos automáticos.** «Consultar estado» consulta el estado guardado por Fontana. Si no llegó una notificación y el cliente afirma haber pagado, revisa el panel de Izipay y solicita el reenvío de la notificación cuando corresponda. No inventes una aprobación ni cobres de nuevo por un timeout.

## Modos y operación diaria

**Manual (valor inicial).** El efectivo se confirma indicando recibido y vuelto. Yape/Plin conservan el QR estático disponible y exigen referencia más confirmación del cajero; ese QR no incluye el importe. Tarjeta usa el POS externo y confirmación manual del voucher. Se mantiene así una ruta operativa sin fingir una integración activa.

**Sandbox.** Las órdenes de Izipay pueden probar el flujo de interfaz y firma, pero sus aprobaciones terminan como `prueba`: no crean ventas ni descuentan stock real. El efectivo sigue siendo real; para no mezclar pruebas con operaciones de negocio, usa una base de datos de pruebas independiente.

**Production.** Una autorización compatible y verificada genera la venta, detalle, movimiento de inventario y referencia una sola vez por orden. Las respuestas duplicadas se reconocen mediante sus huellas; los eventos quedan registrados. Una autorización distinta adicional sobre una orden ya pagada genera una alerta de posible doble cobro: se conserva una sola venta y el administrador debe revisar y, de ser necesario, devolver desde el proveedor.

**Reservas.** Antes de abrir la pasarela, duran 15 minutos por defecto. Una sesión iniciada mantiene su reserva hasta resolverse. El vencimiento local no equivale a que el QR haya sido anulado por Izipay. Por seguridad no se abre automáticamente una sesión nueva ante recargas, rechazos o cortes de red.

**Cancelar.** Antes de iniciar el cobro se permite cancelar la orden. Después de iniciar la pasarela, un administrador debe comprobar que no hubo cobro y que la operación ya no puede pagarse, y dejar una nota. La cancelación local no ejecuta una anulación bancaria. Una autorización tardía auténtica se procesa o se envía a revisión; nunca se ignora solo porque alguien cerró la pantalla.

**Revisión.** Cuando hay un pago verificado pero caja/stock no permiten registrar la venta, queda como `revision` con una alerta. Corrige la situación y utiliza la conciliación de administrador para registrarlo en una caja abierta del día, dejando trazabilidad de la caja original. No vuelve a cobrar al cliente. Los importes, métodos o firmas incompatibles no tienen botón para forzar una aprobación bancaria.

**Caja.** El efectivo esperado es monto inicial + ventas en efectivo. Yape, Plin y tarjeta se muestran separados. Los cierres históricos sin la nueva columna conservan su cálculo anterior, no se reinterpretan. El cierre se bloquea si hay órdenes activas o en revisión vinculadas a esa caja.

## Requisitos de despliegue

PHP 8.0 o superior de 64 bits, `mysqli` con `mysqlnd`, cURL, JSON/OpenSSL y MySQL o MariaDB con InnoDB. La aplicación sigue usando `get_result()` como la versión original. PHP y MySQL se fijan a hora peruana para alinear reserva y caja. El servidor debe poder conectarse por HTTPS a los dominios de Izipay y recibir POST públicos en el webhook.

Los `.htaccess` incluidos bloquean accesos web a configuración, includes, SQL, pruebas y documentos en Apache 2.4 cuando el hosting permite esas directivas. En Nginx/IIS no se aplican: configura reglas equivalentes. Si Apache responde 500 por `AllowOverride`, pide al administrador aplicar las reglas en el virtual host; no expongas respaldos o configuraciones para resolverlo. No bloquees `api_pago.php`, `api_consulta_documento.php` ni `webhook_izipay.php` con esas reglas.

En producción configura cookies `HttpOnly`, `Secure` y `SameSite=Lax`, sesiones estrictas, `display_errors=Off`, logs privados y límites de solicitudes en login/webhook. Restringe permisos del usuario MySQL después de la migración, conserva copias de seguridad y revisa pagos pendientes diariamente. Estos controles de hosting no se pueden activar desde el ZIP en un servidor al que no se ha accedido.

## Qué se conservó y qué no abarca

`config/api.php`, `includes/api_documentos.php`, `api_consulta_documento.php`, los reportes, el dashboard, los recursos originales y los SQL base se conservaron sin alterar su contenido. Cambiaron las escrituras de ventas/inventario/caja y algunos controles de formularios para corregir los problemas identificados. No se sustituyó el framework ni se rediseñó la aplicación.

Esta mejora no agrega emisión electrónica CPE, facturación electrónica SUNAT, devoluciones, pagos divididos, cuotas configurables, integración hardware POS ni una auditoría exhaustiva de todos los riesgos posibles. Consultar RENIEC/SUNAT y guardar un comprobante no equivale a emitirlo electrónicamente.

## Fuentes técnicas del proveedor

Consultadas el 6 de septiembre de 2026. Son referencias de implementación, no una certificación del comercio:

- QR: https://developers.izipay.pe/products/pay-with-qr/
- SDK: https://developers.izipay.pe/web-core/quickstart/
- Credenciales: https://developers.izipay.pe/credentials/
- Métodos: https://developers.izipay.pe/value-table/
- Respuestas: https://developers.izipay.pe/web-core/use-cases/pay/
- Firma: https://developers.izipay.pe/web-core/modalidades/firma-validation/
- Notificaciones: https://developers.izipay.pe/web-core/notifications/
- API del proveedor: https://developers.izipay.pe/api/
