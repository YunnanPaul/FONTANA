# Fontana — Interfaz de ventas y cobro automático

Esta revisión se aplica sobre `fontana_corregido_revision.zip`. La carpeta del proyecto sigue siendo `fontana_mejorado`.

## Lo que cambia para el cajero

La pantalla de ventas ya no muestra el selector «Registro habitual / Pago académico», explicaciones de API, etiquetas «Verificado por API» ni avisos grandes sobre trabajos académicos. Las consultas DNI/RUC continúan; la etiqueta visible pasa a ser «Verificado».

El recorrido es **seleccionar productos → Proceder con la venta → pantalla de cobro automática**. En el funcionamiento habitual, el servidor comprueba productos, existencias y total y abre una ventana con el método elegido. La venta se registra únicamente al pulsar **Confirmar pago y registrar venta**. Preparar o cerrar esa ventana no registra una venta ni descuenta existencias.

En esa ventana, el efectivo permite introducir recibido y calcular vuelto; Yape y Plin muestran la imagen del comercio cuando existe; tarjeta permite confirmar el pago del terminal. «Volver al pedido» conserva el carrito y el cliente e invalida la ventana anterior. Recargar o reenviar una confirmación válida no inserta otra venta dentro de la misma sesión.

## Actualizar una instalación que ya funciona

1. Guarda una copia de tu carpeta y un respaldo de la base. No es necesario reinstalar XAMPP.
2. Cierra las pestañas de Fontana para no dejar formularios de la versión anterior abiertos.
3. Copia los archivos de esta revisión sobre la misma carpeta `fontana_mejorado`. Si utilizas el ZIP de **solo actualización**, aplícalo únicamente sobre la última revisión corregida, no sobre el proyecto original.
4. Conserva tu `conexion.php`, `config/conexion.php` si lo tienes, `config/api.php`, `config/pagos.php` y tus imágenes QR personalizadas. El paquete de solo actualización no incluye esos archivos privados ni ningún SQL.
5. Abre Ventas y recarga con Ctrl+F5. Los recursos modificados llevan una versión nueva para evitar que el navegador reutilice los anteriores.

**Esta corrección de interfaz no requiere importar SQL, crear otra base, borrar carpetas de MySQL ni ejecutar una migración nueva.** Todos los archivos SQL, el instalador de pagos y la conexión se conservaron idénticos a la revisión anterior. No copies carpetas físicas de bases de datos para aplicar esta actualización.

## Qué QR se utiliza

### Confirmación del cajero — configuración inicial

Yape y Plin utilizan el mismo QR Básico de Izipay guardado en `img/qr_izipay.png`. El código aparece automáticamente después de pulsar **Proceder con la venta**, junto con el total calculado por Fontana. Para cambiar el destinatario basta con sustituir ese archivo y recargar con Ctrl + F5.

El QR Básico es estático: Fontana muestra el total, pero el importe dentro de la billetera y la confirmación automática requieren una orden dinámica de Izipay y sus credenciales de Punto Web.

### Cobro integrado ya instalado

La integración de pagos de la revisión anterior no se eliminó. Para abrirla directamente desde el botón de ventas, sin que el cajero elija una modalidad, añade en tu archivo existente `config/pagos.php` esta opción al arreglo que devuelve:

```php
'checkout_flow' => 'integrado',
```

Conserva las demás opciones y tus credenciales existentes. `checkout_flow` solo determina qué pantalla abre Ventas; **no cambia por sí solo el proveedor, el entorno ni la autorización de registrar ventas de prueba**. El administrador configura esto una sola vez; el cajero no ve otro selector.

En `mode => demo`, el QR de prueba de Yape y Plin se prepara automáticamente al abrir la orden. Ya no existe el botón «Mostrar / recuperar QR». Un botón de reintento aparece únicamente si falla la preparación. El cliente escanea con la cámara y abre Fontana con el importe fijado; no es un QR bancario para escanear dentro de Yape o Plin.

En `mode => mercadopago_sandbox`, se conserva el flujo TEST previo de Yape por celular/código y de tarjetas. Plin continúa usando el simulador local. No se transformó esta integración en un servicio oficial de Plin.

En los recorridos simulados o de pruebas permanece una sola advertencia discreta: **«Modo de prueba · No se realizan cobros reales»**. Quitar etiquetas académicas no convierte una prueba en un pago bancario real.

Con `academic_record_sales => false`, las aprobaciones se guardan como pruebas en Pagos y cobros, sin insertar ventas ni descontar inventario. Para un ciclo completo en una base exclusivamente de pruebas, conserva la autorización explícita anterior (`academic_record_sales` y, cuando corresponda, `academic_database`). El ejemplo `config/pagos.academico-completo.example.php` ya incluye `checkout_flow => integrado`. No se activa esa autorización automáticamente en una base con operaciones reales.

Si la ampliación de pagos todavía no está instalada, el flujo habitual sigue funcionando sin ella. Elegir el flujo integrado requiere la ampliación que ya existía en la versión anterior; esta entrega no introduce otra migración.

## Compatibilidad y límites

Se preservan las rutas originales, la conexión, los SQL, las consultas documentales, los módulos de caja/inventario/productos/usuarios/reportes y el CSS principal. Los ajustes visuales están en las vistas y en `css/pagos.css`.

La preparación de un cobro habitual permanece en la sesión, no en una tabla nueva. No reserva stock durante toda la espera: antes de confirmar se comprueban nuevamente caja, existencias y precios. Si un precio cambió, el sistema no registra una venta por un importe diferente del que mostró; pide revisar el pedido. Si ya se realizó un abono fuera del sistema y aparece un error, comprueba ese pago antes de volver a cobrar.

Las instrucciones de versiones anteriores que mencionan seleccionar «Pago académico» en Ventas quedan sustituidas por esta guía. El resto de documentación histórica se conserva como referencia técnica.

## Verificación

Consulta `INFORME_INTERFAZ.md` y los registros en `docs/verificacion_interfaz/`. Las pruebas de base de datos utilizan un doble explícito en memoria; las de pasarela utilizan respuestas simuladas. No se ejecutó MySQL/MariaDB ni se validó una cuenta externa real en este entorno. Tampoco se intervino la instalación del usuario.

Antes de presentar o utilizar tu instalación, prueba una venta por cada método disponible, el vuelto, volver al pedido, el historial y el stock. Usa una copia con datos de prueba para esos recorridos. Nunca pruebes cobros reales sin revisar a qué cuenta pertenece el QR.
