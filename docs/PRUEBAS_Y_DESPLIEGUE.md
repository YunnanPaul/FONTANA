DOCUMENTO HISTÓRICO DE LA ENTREGA ANTERIOR.
La revisión actual, sus cambios y resultados están en ../LEEME_PRIMERO.md e ../INFORME_REVISION.md.
Las instrucciones y cifras de este documento pueden no corresponder a la versión corregida.

NOTA: este documento describe la entrega anterior con Izipay. Para la versión académica actual consulta README_PAGOS.md, CAMBIOS_ACADEMICOS.md y RESULTADOS_ACADEMICOS.md.

# Verificación y aceptación

## Ejecutado en esta entrega

**56 pruebas unitarias PHP aprobadas, 0 fallidas.** Validan céntimos, límites, decimales, cantidades enteras, agrupación de productos, Unicode, HMAC, alteración de payload, aprobación compatible, rechazo de importes/monedas/comercios/métodos distintos y distinción de reserva vencida frente a sesión iniciada.

**10 pruebas de interfaz Chromium aprobadas.** Se ejecutaron en memoria con HTML real y los JavaScript del proyecto, utilizando respuestas de API y un SDK simulados. Se verificaron total/subtotales, quitar líneas, vuelto, confirmación manual, envío al checkout, envío de callback al backend, rechazo de éxito sin firma, tarjeta, vista móvil a 390 px y ausencia de errores JavaScript.

Se revisó la sintaxis de todos los PHP del paquete y de los dos JavaScript nuevos. Se inspeccionaron visualmente capturas del formulario y de la pantalla de efectivo. Se compararon con el ZIP original los archivos que debían preservarse.

La ejecución de Chromium por navegación HTTP local estaba restringida en el entorno, por lo que las pruebas de DOM se hicieron en memoria, sin navegar a un servidor ni sortear esa restricción.

## No ejecutado aquí

No había extensión `mysqli`, extensión `curl` ni servidor MySQL en el entorno de construcción. **No se ejecutaron la migración, las consultas/transacciones MySQL, los bloqueos concurrentes, ni un recorrido completo contra la base del usuario.** Tampoco había credenciales de comercio: **no se generó un token real de Izipay, no se escaneó un QR bancario real, no se autorizó una tarjeta real y no se recibió una notificación real**.

Las pruebas simuladas no sustituyen estas verificaciones. La entrega no está homologada ni puede considerarse lista para cobrar en producción sin superar la aceptación siguiente.

## Repetir pruebas locales

Desde la carpeta del proyecto:

```bash
php tests/unit.php
php tests/diagnostico.php
```

`unit.php` no requiere red ni base. `diagnostico.php` inspecciona extensiones, configuración, esquema y motores; no crea ni elimina datos. La instalación se hace desde la pantalla protegida de Configuración.

Para generar fixtures HTML de interfaz sin base:

```bash
php tests/generar_vistas.php /ruta/privada/fontana-vistas
```

Son vistas ficticias, no pagos ni comprobantes. No publiques estos fixtures en producción. El navegador de pruebas utilizó mocks para no realizar peticiones bancarias.

Se incluye el recorrido reproducible en `tests/interfaz.py`. Requiere Python 3, Playwright y Chromium instalados; no son dependencias del sistema PHP en producción. Ejemplo:

```bash
python tests/interfaz.py --chromium /ruta/a/chromium --salida /ruta/privada/resultados
```

Genera las vistas, ejecuta los diez casos en memoria y guarda capturas y `resultados.json`. Las API y el SDK están simulados deliberadamente; no acredita conexión con Izipay.

## Aceptación obligatoria en una copia de la base

| Escenario | Resultado que debe comprobarse |
|---|---|
| Instalar ampliación y repetirla | No hay eliminaciones, duplicación de tablas ni alteración de ventas históricas; aparecen las tres tablas y total_efectivo |
| RENIEC/SUNAT | Funcionan los mismos datos de configuración; consulta y guardado DNI/RUC como antes; respuesta tardía no pisa otro documento |
| Orden de efectivo de S/ 17.50 | Antes de confirmar no hay venta ni salida de stock; recibido 20 genera vuelto 2.50 y una venta |
| Efectivo insuficiente | Recibido 10 no registra venta de 17.50 |
| Doble POST / recarga | La misma clave de formulario lleva a la misma orden; repetir confirmación no duplica venta ni stock |
| Cantidad decimal o negativa | El servidor la rechaza sin truncarla ni aceptarla parcialmente |
| Dos usuarios compran últimas unidades | Las reservas y bloqueos dejan pasar solo la cantidad disponible; nunca stock negativo |
| Cambiar stock desde productos/inventario | No destruye reservas; una ficha desactualizada pide recargar en vez de sobrescribir ventas recientes |
| Dos aprobaciones de reposición | La misma solicitud incrementa stock una sola vez |
| Sesión de Izipay sandbox | Token y checkout aceptan los identificadores, credenciales y datos del pagador del comercio; confirmar cualquier ajuste exigido por Izipay |
| QR Yape y QR Plin | Las billeteras habilitadas leen exactamente el total de la orden; verificar importes bajos reales del catálogo y límites del comercio |
| Tarjeta aprobada/rechazada y autenticación | SDK resuelve los casos habilitados por el comercio; rechazo o cierre no crea venta |
| Sandbox aprobado | Estado prueba; no venta, no descuento físico; se libera la reserva |
| Callback e IPN repetidos | La misma operación se procesa una vez; no aparecen dos ventas ni movimientos |
| Navegador cerrado después de pagar | IPN auténtica confirma; la orden se recupera desde Pagos |
| Firma / moneda / importe / método incompatibles | No registra una venta pagada; rechaza o envía a revisión según corresponda |
| Timeout de token o formulario | No se interpreta como pago fallido definitivo ni se crea otro cobro automático; comprobar panel |
| Orden cancelada, luego autorización tardía | Registra el hecho auténtico o lo conserva en revisión por caja/stock; nunca lo ignora |
| Pago tardío con caja cerrada | Se conserva como verificado/en revisión; conciliación con nota, sin cobrar otra vez |
| Cierre de caja | Inicial + solo efectivo; digitales separados; órdenes sin resolver impiden cerrar |
| Misma orden, dos autorizaciones distintas | Alerta de posible doble cobro; una sola venta; revisar devolución en el proveedor |
| Permisos | Cajero solo abre sus órdenes; administrador revisa todas; webhook no acepta firmas falsas |
| Hosting | Config, SQL, respaldos y pruebas no son descargables; webhooks sí son accesibles por POST HTTPS |

## Puesta en producción

Aprueba los escenarios anteriores con evidencia. Habilita QR y tarjetas para el comercio en Izipay; usa sus credenciales de producción y una URL HTTPS pública estable. Verifica una operación controlada autorizada por el propietario del comercio, sus notificaciones, su conciliación y la disponibilidad del dinero. Documenta comisiones, liquidación, límites, soporte y procedimiento de devoluciones con el proveedor.

No cambies de comercio ni elimines llaves mientras existan pagos pendientes. No mezcles los archivos viejos y nuevos operando al mismo tiempo sobre la misma base: los controladores antiguos no respetan reservas.

## Reversión

Antes de empezar cobros reales, puede restaurarse la copia de archivos y base completa tomada previamente. Después de recibir pagos nuevos, **no restaures ciegamente una copia anterior ni elimines las tablas nuevas**: primero detén cobros, conserva órdenes/eventos/ventas y concilia todas las autorizaciones con Izipay. Restaurar una base no devuelve dinero ni anula QR pendientes.
