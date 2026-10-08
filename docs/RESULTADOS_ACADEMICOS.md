DOCUMENTO HISTÓRICO DE LA ENTREGA ANTERIOR.
La revisión actual, sus cambios y resultados están en ../LEEME_PRIMERO.md e ../INFORME_REVISION.md.
Las instrucciones y cifras de este documento pueden no corresponder a la versión corregida.

# Validación de la entrega académica

Fecha: 6 de septiembre de 2026.

## Ejecutado

| Comprobación | Resultado | Alcance |
|---|---|---|
| `php tests/unit.php` | 56 aprobadas; 0 fallidas | Regresión del núcleo previo: importes, entradas y firmas; sin MySQL |
| `php tests/academico_unit.php` | 89 aprobadas; 0 fallidas | Enrutamiento, validación del payload/respuesta, protección TEST, HMAC, URLs, privacidad de vista móvil, caducidad |
| `python tests/interfaz.py` | 10 aprobadas | Interfaz heredada con API/SDK simulados |
| `python tests/interfaz_academica.py` | 20 aprobadas | Interfaz nueva, QR decodificado, móvil, efectivo, rechazo, tokenización simulada, error de red y recuperación por polling |
| `php -l` en todos los PHP | 42 archivos sin errores sintácticos | No demuestra ejecución contra MySQL |
| `node --check` en todos los JavaScript | 5 archivos sin errores sintácticos | No homologa el SDK remoto |
| Comparación SHA-256 | Ningún archivo original eliminado | RENIEC/SUNAT, CSS principal, imágenes y reportes conservados; detalle en VERIFICACION_ACADEMICA.txt |
| Diagnóstico del entorno disponible | Falta mysqli | Impide comprobar conexión, migración y transacciones de MySQL aquí |

Total automatizado: **145 pruebas unitarias y 30 escenarios de interfaz aprobados**. No se suman los archivos de sintaxis como si fueran casos de prueba funcionales.

Se revisaron visualmente el checkout y la pantalla móvil. El QR se generó con el JavaScript incluido y OpenCV recuperó exactamente el enlace esperado; no se utilizó OCR. Las pruebas de navegador ejecutan HTML generado por PHP y JavaScript real, pero reemplazan API y SDK por dobles de prueba. La prueba móvil reemplaza también almacenamiento/historial por las restricciones de origen del navegador; no comprueba un escaneo físico ni persistencia real del navegador HTTP.

El sondeo local frecuente no interfiere con la consulta al proveedor: se validó que, ante una respuesta desconocida, se hace la consulta posterior sin enviar otro POST de pago.

## No ejecutado en este entorno

No había mysqli, cURL ni un servidor MySQL disponible. No se ejecutaron la ampliación de esquema, los bloqueos/transacciones, una prueba de concurrencia real ni las escrituras a ventas/inventario. No se hicieron llamadas de red reales a una cuenta de Mercado Pago ni se validó un webhook enviado por el proveedor. No se modificó el servidor ni la base del usuario.

**El código incluido no equivale a una homologación del proveedor ni a una garantía de cero errores.** Los pasos siguientes completan la aceptación en una instalación con PHP/MySQL y credenciales propias de pruebas.

## Matriz de aceptación en tu instalación (pendiente)

Utiliza una copia del sistema y de la base. No hagas estos ensayos sobre una operación comercial.

| Caso | Acción | Resultado a verificar |
|---|---|---|
| Instalación aditiva | Ejecutar dos veces “Instalar / verificar ampliación” | Ambas terminan; mismos productos, usuarios y ventas previas; cinco tablas de pagos disponibles |
| Importe | Seleccionar 2 productos de S/ 5 y 3 de S/ 2.50 | Orden por S/ 17.50; no depende de un total de navegador |
| QR físico | Abrir el QR con cámara de celular en misma Wi-Fi | Página del proyecto, importe S/ 17.50 ya cargado, sin pedir dato bancario |
| QR recuperado | Recuperar desde la misma sesión | Mismo enlace mientras esté vigente |
| Enlace reemplazado | Recuperar desde otra sesión autorizada | Enlace anterior no puede resolver la orden |
| Protección por defecto | Aprobar demo con `academic_record_sales=false` | Estado prueba; no venta, salida de inventario ni ingreso adicional de caja |
| Duplicados | Aprobar y volver a consultar/aprobar la misma orden | Un resultado final; no nuevos efectos de venta |
| Rechazo/vencimiento | Rechazar o esperar caducidad de una demo | No comprobante aprobado ni descuento de stock |
| Caja real intacta | Dejar demo protegida en proceso | No agrega una reserva real ni bloquea cierre por esa prueba |
| Ciclo completo | Base físicamente separada `_demo`, opción true | Una venta académica, su detalle y una salida de inventario; repetición no duplica |
| Base no académica | Opción true con nombre sin sufijo admitido | Bloqueo preventivo antes de registrar una venta académica |
| Yape API TEST | Celular/código de prueba y llaves TEST propias | Pago de prueba en el proveedor; estado verificado por PHP con importe y referencia correctos |
| Tarjeta API TEST | Payment Brick y tarjeta de prueba oficial | Tokenización y respuesta sandbox; no se almacenan PAN/CVV |
| API rechazada | Escenario de rechazo oficial | No aprobación, venta ni descuento |
| Demora/API inaccesible | Interrumpir conexión durante una prueba | Resultado desconocido; consultar sin reenviar ni crear otra operación automáticamente |
| Webhook opcional | Envío auténtico firmado del proveedor | Firma validada, consulta API y efecto único; firma alterada no produce aprobación |
| Conservación de módulos | Probar consultas DNI/RUC, reportes, login, inventario | Funcionamiento previo con tus servicios/configuraciones |

Los logs de unitarias y los resultados JSON de interfaz están en `docs/evidencias/`. No contienen llaves del usuario. Los archivos de diagnóstico no realizan cobros ni migraciones por sí solos.
