DOCUMENTO HISTÓRICO DE LA ENTREGA ANTERIOR.
La revisión actual, sus cambios y resultados están en ../LEEME_PRIMERO.md e ../INFORME_REVISION.md.
Las instrucciones y cifras de este documento pueden no corresponder a la versión corregida.

NOTA: este documento describe la entrega anterior con Izipay. Para la versión académica actual consulta README_PAGOS.md, CAMBIOS_ACADEMICOS.md y RESULTADOS_ACADEMICOS.md.

# Cambios realizados sobre el ZIP original

## Pagos

Nuevo núcleo `includes/pagos.php`, adaptador `includes/izipay.php`, pantalla `pago.php`, historial `pagos.php`, API interna `api_pago.php`, notificación `webhook_izipay.php` y configuración protegida `configurar_pagos.php`. Nuevas tablas aditivas y configuración por entorno. Importe calculado en céntimos; snapshots de compra; reservas; idempotencia; autorización firmada; manejo diferenciado de sandbox; cola de revisión y conciliación documentada.

Se sustituyó el modal que aparecía después de registrar la venta por una pantalla de cobro anterior a la venta. Se añadieron total/subtotales en el carrito y vuelto antes de confirmar efectivo. No se convierte un QR estático en dinámico artificialmente.

## Correcciones puntuales

Se eliminaron lecturas de stock fuera de transacción en los caminos de venta e inventario modificados. Se bloquean filas al aprobar reposiciones. Se corrigió el tratamiento de `motivo` que el código original enlazaba como entero en varios movimientos (`isiiii`); los nuevos insertos lo envían como texto.

Caja distingue efectivo de otros medios y no cierra con órdenes sin resolver. Los productos vinculados a órdenes o reposiciones no pueden eliminarse, y la edición de stock detecta cambios concurrentes. Ajustar stock a cero es válido si no existen reservas.

Se añadieron tokens CSRF a formularios administrativos/caja/login, regeneración de identificador al iniciar sesión, comprobación actualizada de usuario/rol en páginas conectadas a la base y detección amigable de nombres de usuario duplicados. Se evita que un administrador se quite su propio acceso desde su sesión. No es una auditoría de seguridad exhaustiva.

Se impide que una respuesta documental atrasada sobrescriba los datos de un documento distinto. Editar manualmente el nombre deja de presentarse como el dato originalmente verificado por la API.

## Conservado

El proveedor documental, su endpoint y configuración de credenciales no fueron reemplazados. Los archivos originales de reportes, dashboard, estilos base, imágenes y SQL base se conservaron. Los estilos de pagos son una hoja adicional. Se conservan PHP, mysqli, la estructura de tablas de ventas y los métodos Efectivo/Yape/Plin/Tarjeta.

## Límites

El código de integración requiere las pruebas de aceptación en MySQL e Izipay del documento adjunto. No se añadieron cobros divididos, reembolsos automáticos, hardware POS ni emisión CPE. Los cambios tributarios, comerciales y de servidor se deben gestionar aparte. No hay garantía de haber detectado todos los defectos posibles del sistema.
