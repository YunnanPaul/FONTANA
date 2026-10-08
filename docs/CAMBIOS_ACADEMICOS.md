DOCUMENTO HISTÓRICO DE LA ENTREGA ANTERIOR.
La revisión actual, sus cambios y resultados están en ../LEEME_PRIMERO.md e ../INFORME_REVISION.md.
Las instrucciones y cifras de este documento pueden no corresponder a la versión corregida.

# Cambios de la entrega académica

Base de trabajo: `fontana_pagos_mejorado.zip`; se conserva el sistema del ZIP original y la separación orden/pago/venta añadida previamente.

## Incorporaciones

- `demo` como modo inicial; `mercadopago_sandbox` como integración externa TEST para Yape y tarjetas.
- Simulador local con QR generado sin red, token aleatorio de 256 bits y hash en base de datos; vista móvil con importe fijo, caducidad, rechazo y aprobación.
- API PHP de Mercado Pago, SDK oficial para tokenización, Payment Brick, idempotencia por orden, recuperación por consulta y webhook opcional con firma.
- Validación del importe, moneda, referencia, método y live_mode=false antes de aprobar una operación externa. No se aceptan estados de pago del cliente.
- Protección de ventas/stock/caja frente a pruebas ordinarias. Excepción explícita para ciclo completo en una copia con sufijo académico; las pruebas ordinarias tampoco reservan inventario real ni bloquean el cierre.
- Comprobante imprimible de prueba sin validez tributaria; etiquetas inequívocas de entorno y proveedor.
- Migración aditiva/repetible: tablas `pagos_demo` y `pagos_mercadopago`. El instalador ejecuta ambas migraciones en orden y verifica los archivos antes de procesarlos.
- 89 pruebas unitarias nuevas y 20 escenarios nuevos de interfaz con servicios simulados; se conservan las 56 unitarias y 10 de interfaz anteriores.

## Correcciones específicas

La consulta local frecuente no reinicia el temporizador de consulta al proveedor. Una demora de red no aprueba la orden ni autoriza un reenvío automático. Los errores de SQL de los endpoints no exponen consultas al cliente. La validación del token móvil se repite bajo bloqueo para impedir que un enlace reemplazado resuelva la orden. Los enlaces se conservan al recuperarlos en la misma sesión del cajero.

## Fuera del alcance ejecutado

No se migró la base del usuario, no se accedió a su servidor ni a su cuenta de Mercado Pago, no se efectuaron cobros y no se probó contra MySQL en el entorno de desarrollo. No se auditó exhaustivamente la totalidad del sistema heredado. Las pruebas simuladas no equivalen a una validación integral de producción. Los modos comerciales heredados no deben activarse para una exposición.
