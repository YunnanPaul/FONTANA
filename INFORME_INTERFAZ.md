# Fontana — Informe de revisión de interfaz y cobro

Revisión: 6 de septiembre de 2026. Base editada: `fontana_corregido_revision.zip`. Comparación visual y de rutas: `fontana_mejorado(4).zip`.

## Cambios verificados

- La pantalla de ventas no solicita elegir entre registro habitual y pago académico. Muestra un único botón «Proceder con la venta».
- El cobro habitual se abre automáticamente tras preparar el pedido. El QR del comercio se muestra inmediatamente cuando el archivo del método está disponible. No se exige pulsar «Mostrar QR».
- En el cobro integrado, el QR local también se prepara automáticamente. Solo aparece un reintento si falla la preparación; abrir o recuperar el QR no aprueba un pago.
- Se retiraron explicaciones académicas y menciones técnicas a la API de las pantallas de uso diario. En operaciones simuladas se conserva un aviso pequeño de prueba y la aclaración de que el QR local no es bancario.
- El carrito y los datos del cliente se conservan al volver. Una ventana anterior queda invalidada; la preparación conserva su importe y método bajo una clave inmutable.
- La preparación habitual únicamente lee datos y guarda el pedido en la sesión. El registro de venta, detalle y movimiento de stock reutiliza el servicio de la versión anterior al confirmar explícitamente el cobro.
- Antes de registrar se validan nuevamente caja, stock y precios. Los importes no se toman del navegador. La repetición de una confirmación recupera el resultado ya registrado dentro de la misma sesión.
- La apariencia principal y los módulos ajenos a esta corrección se mantienen. Los recursos modificados llevan versión de caché.

## Pruebas ejecutadas sobre el paquete entregado

| Prueba | Resultado | Alcance |
|---|---:|---|
| Lógica de pagos heredada | 56 aprobadas | Validaciones, importes, firma y estados; sin banco ni base real. |
| Lógica del cobro integrado | 89 aprobadas | Estados, QR, vencimiento, payloads y protección del entorno de prueba. |
| Regresión de ventas | 32 aprobadas | Servicio real con un doble explícito de almacenamiento en memoria. |
| Preparación y confirmación del cobro | 55 aprobadas | Precio fijo, cartilla en sesión, cancelación, duplicados, validaciones y errores simulados. |
| Interfaz del recorrido de ventas | 31 aprobadas | HTML PHP renderizado con datos fixture, Chromium, móvil y escritorio. |
| Interfaz heredada | 10 aprobadas | Vistas de pago con respuestas de API y SDK simuladas. |
| Interfaz integrada | 23 aprobadas | QR automático, sincronización móvil, rechazo, vencimiento y recuperación; proveedor simulado. |
| Otras plantillas PHP | 13 renderizadas sin avisos | Datos fixture; no se ejecuta SQL. |
| Sintaxis PHP | 54 archivos sin errores detectados | `php -l`. |
| Sintaxis JavaScript | 6 archivos sin errores detectados | `node --check`. |
| Integración MySQL/MariaDB | **OMITIDA / PENDIENTE** | El entorno no dispone de `mysqli` ni servidor MySQL/MariaDB. Salida 77 de la prueba. |

Totales: **232 comprobaciones de lógica** y **64 comprobaciones de interfaz**, además de las 13 plantillas y las revisiones de sintaxis. Los registros están en `docs/verificacion_interfaz/`.

El QR local generado se decodificó y coincidió con el enlace y token esperados. Las vistas se revisaron en escritorio y anchos móviles de 360 y 390 px. Las capturas de desarrollo contienen nombres y encabezados fixture; no se agregaron esos encabezados de prueba a la pantalla normal del sistema.

## Preservación de archivos y base de datos

Se conservaron las 32 rutas de archivos del proyecto original y todos los archivos de la última revisión. Los 7 archivos SQL existentes son idénticos byte a byte a la revisión anterior. No se agregó ningún SQL ni una migración. `conexion.php`, el instalador, las consultas DNI/RUC y su configuración, caja, productos, inventario, reposición, usuarios, login, dashboard, reportes y el CSS principal no cambiaron. El QR de pago fue sustituido por el QR Básico de Izipay aportado para INDULAC FONTANA SRL; el resto de imágenes permanece sin cambios.

El detalle de archivos nuevos, modificados y protegidos, con hashes SHA-256, está en `docs/MANIFIESTO_INTERFAZ.json`. El manifiesto no contiene contraseñas ni credenciales en texto claro.

## Límites que permanecen

No se probaron operaciones contra MySQL/MariaDB, bloqueos concurrentes del motor, instalación Windows/XAMPP del usuario, tarjetas reales, cuentas externas, llamadas auténticas a Mercado Pago ni notificaciones bancarias. El almacenamiento simulado y el SDK simulado no sustituyen esas verificaciones. Tampoco se prueba aquí la cámara física o la red Wi-Fi de la exposición.

La protección contra repetición del cobro habitual depende de la sesión PHP. No sustituye una idempotencia durable en base de datos ante caída del proceso justo después de un commit. Ante un resultado desconocido, se debe revisar el historial y el pago antes de intentar otra operación.

Yape y Plin muestran el mismo QR Básico de Izipay guardado en `img/qr_izipay.png`. El total aparece automáticamente en Fontana; el QR Básico no garantiza el importe precargado dentro de la billetera ni una confirmación bancaria automática. Esas funciones requieren una orden dinámica y credenciales de Izipay Punto Web.

No se modificó el servidor o la base de datos en funcionamiento del usuario y no se realizó ningún cobro real. Esta entrega no garantiza ausencia universal de errores. Probar inicialmente en una copia con respaldo.

## Reproducir las pruebas

Desde la carpeta del proyecto, en un entorno de desarrollo con PHP, Python, Playwright, Chromium y OpenCV para los ensayos de navegador:

```text
php tests/unit.php
php tests/academico_unit.php
php -n tests/regresion_ventas.php
php -n tests/cobro_unit.php
php -n tests/vistas_modulos.php
python tests/interfaz_regresion.py
python tests/interfaz.py
python tests/interfaz_academica.py
```

`-n` permite cargar el doble de mysqli sin una extensión real; nunca se configura así el servidor de la aplicación. Las dependencias de desarrollo solo son necesarias para repetir las pruebas, no para utilizar Fontana.

La prueba opcional `tests/integracion_mysql.php` tiene instrucciones propias y exige un servidor/base de pruebas separados. No ejecutarla sobre una base con operaciones que deban conservarse.

Para actualizar los archivos sin cambiar la base, consulta `LEEME_INTERFAZ.md`.
