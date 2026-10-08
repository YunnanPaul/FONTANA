# Informe de revisión — Fontana corregido

## Fuentes y alcance

Se compararon el ZIP original `fontana_mejorado(4).zip`, la entrega con Izipay y la entrega académica más reciente `fontana_pagos_academicos.zip`. Se trabajó sobre una copia local. No se accedió al XAMPP, servidor ni base de datos en funcionamiento del usuario. Los esquemas disponibles son los incluidos en esos ZIP, no una exportación actual de la base del usuario.

El objetivo de esta revisión es restaurar compatibilidad y aislar la mejora de pagos. No es una certificación bancaria, una auditoría exhaustiva de seguridad ni una garantía de ausencia absoluta de errores.

## Regresiones y riesgos concretos corregidos

| Hallazgo en los archivos | Corrección entregada |
|---|---|
| El modo DEMO sustituía el registro habitual; aprobaba una prueba sin insertar ventas ni descontar stock. | Se separaron los flujos. «Registro habitual» es el valor inicial y utiliza venta, detalle e inventario originales. «Pago académico» es una elección explícita. |
| El formulario de Ventas dependía de que estuviera instalado el esquema de pagos. | El formulario habitual se mantiene disponible con las tablas originales; solo el flujo avanzado exige la ampliación. |
| Caja impedía cerrar si faltaba `total_efectivo`. | Se soportan ambos esquemas: cálculo original sin la columna y efectivo separado para nuevos cierres con ella. No se reinterpretan los históricos. |
| Los dos SQL base contenían `DROP TABLE` y `USE fontana_db`. Importarlos podía borrar registros o afectar una base distinta a la seleccionada. | Ambos se reemplazaron por el mismo instalador nuevo no destructivo: sin borrados ni cambio de base, con semillas protegidas contra reinserción. Esto no implica que se haya producido ni recuperado una pérdida en la instalación del usuario. |
| Un chequeo incompleto de tablas podía dar la ampliación por instalada; el resultado de esquema quedaba cacheado en la misma petición. | Se verifica el conjunto de columnas necesarias, se vuelve a comprobar después de instalar y se detectan estructuras parciales. El instalador valida motores y tipos INT de claves de origen sin cambiar las claves existentes. |
| Los errores al crear productos, solicitar reposiciones o registrar sesiones podían terminar sin un mensaje controlado bajo el comportamiento estricto de mysqli. | Se añadieron capturas y mensajes. El seguimiento opcional de usuarios conectados ya no impide el acceso por faltar su tabla. |
| Un insumo con precio vacío era rechazado por la validación añadida. | Se restablece precio cero para insumos sin precio, manteniendo precio positivo para productos vendidos. |
| Un error de validación eliminaba la selección de productos y datos escritos. | Se conserva un borrador limitado del carrito y del comprobante; no se restaura automáticamente la confirmación del pago. |
| Los formularios aceptaban arrays donde esperaban texto; algunas escrituras podían fallar antes de una validación controlada. | Se validan campos escalares, tamaños y acciones; usuarios maneja también errores de contraseña fuera de rango. |
| La hoja de estilos adicional se cargaba en todas las pantallas. | Se limita a ventas/pagos/caja; CSS principal y recursos originales no se modifican. |

El registro habitual usa una transacción para venta, detalles y movimientos, calcula importes desde los precios del servidor y conserva el motivo textual del inventario. La protección contra doble envío habitual funciona dentro de la misma sesión PHP; no es una garantía persistente contra cualquier caída de proceso o pérdida de sesión. Las órdenes avanzadas mantienen su idempotencia almacenada en la base.

## Conservación

Se conservan las **32 rutas de archivos del ZIP original, sin eliminar ninguna**. De esos archivos, **18 permanecen byte a byte iguales y 14 fueron modificados**. Todos los archivos nuevos de la entrega académica se mantienen; se agregan la revisión y sus pruebas.

Se verificó coincidencia exacta en consultas DNI/RUC, `config/api.php`, `includes/api_documentos.php`, `index.php`, `reportes.php`, `reporte_pdf.php`, `css/estilos.css` y las imágenes principales. No se exponen sus claves en este informe. El ZIP debe tratarse como privado.

Archivos originales modificados en esta revisión acumulada respecto al original:

`fontana_db.sql, conexion.php, login.php, .gitignore, usuarios.php, logout.php, caja.php, ventas.php, inventario.php, productos.php, reposicion.php, sql/fontana_db.sql, includes/header.php, includes/helpers.php`

El manifiesto `docs/MANIFIESTO_REVISION.json` contiene hashes y diferencias respecto a las dos bases. Los documentos heredados se marcaron HISTÓRICOS para no confundir sus instrucciones/resultados con esta entrega.

## Pruebas ejecutadas en esta revisión

| Comprobación | Resultado | Alcance real |
|---|---:|---|
| Lógica PHP heredada | 56/56 | Importes, validaciones, firmas y estados; sin SQL ni red. |
| Lógica académica | 89/89 | Rutas DEMO/TEST, validaciones y estados; sin SQL ni proveedor. |
| Servicio de venta habitual | 32/32 | Código real con doble explícito de mysqli en memoria. Confirma cálculo, stock, parámetros, duplicados de sesión y rollback simulado. No es un motor SQL. |
| Interfaz heredada | 10/10 | HTML real y respuestas API/SDK simuladas. |
| Interfaz académica | 20/20 | QR realmente generado y decodificado, móvil, Yape/tarjeta con SDK ficticio, pendientes, rechazos y recuperación. |
| Interfaz de regresión | 22/22 | Formulario sin tablas de pagos, normal/academic separados, referencia, efectivo, doble clic, restauración de carrito y móvil. |
| Plantillas de módulos | 13/13 | Renderizado PHP con fixtures de dashboard, productos, inventario, reposición, usuarios, reportes, caja y configuración; sin ejecutar controladores SQL. |
| Sintaxis PHP | 50 archivos | PHP 8.4.23, sin errores de sintaxis detectados. |
| Sintaxis JavaScript | 5 archivos y 7 bloques renderizados | Verificación `node --check`. |
| Estructura y recursos | Correcto en verificaciones descritas | 32 rutas originales presentes, recursos locales enlazados existentes, columnas del código coincidentes con migraciones. |
| MySQL/MariaDB real | **NO EJECUTADO — SKIP** | No están instalados mysqli ni un servidor MySQL/MariaDB en el entorno de construcción. |

Total de lógica: **177 comprobaciones**. Total de navegador: **52 escenarios**. Las 13 plantillas se cuentan por separado y no como 13 módulos ejecutados de extremo a extremo. No se cuentan los SKIP como éxitos. Los logs exactos están en `docs/resultados_revision/`.

Se inspeccionaron capturas de ventas en escritorio y móvil. Que una plantilla se renderice no demuestra por sí solo que sus consultas funcionen en todos los esquemas MySQL. Las pruebas de doble en memoria no prueban concurrencia real, bloqueos ni compatibilidad entre motores/versiones.

## Validaciones que siguen pendientes

No se ejecutaron la migración, los commits/rollbacks/bloqueos reales de MySQL ni la compatibilidad contra una exportación actual del usuario. Se intentó preparar las dependencias de ese motor, pero no estaban disponibles en este entorno. La suite optativa `tests/integracion_mysql.php` se entrega para un servidor de pruebas y quedó como SKIP aquí.

No se probaron credenciales reales de Mercado Pago, RENIEC/SUNAT o Izipay; no se enviaron cobros, tokens ni webhooks auténticos. No se comprobó el celular físico, la Wi-Fi del aula, el servidor del usuario, carga sostenida ni todos los navegadores. Las tarjetas y Yape en API TEST requieren las credenciales y la validación de la cuenta. Plin sigue siendo una simulación local, no una API bancaria.

## Cómo aceptar la actualización

Prueba primero en una copia de archivos/base, conserva las configuraciones actuales y recorre la matriz de `LEEME_PRIMERO.md`. **No importes el SQL completo para actualizar.** Instala la ampliación solo para el flujo avanzado; la venta habitual no la exige. El nuevo diagnóstico, accesible desde Configuración, muestra qué base se está usando y qué tablas/columnas están presentes.

Si se perdieron datos tras importar un SQL de una entrega anterior, esta revisión no los reconstruye. Necesitas un respaldo real o registros de recuperación del servidor; no se inventarán ventas, usuarios o stock para rellenarlos.
