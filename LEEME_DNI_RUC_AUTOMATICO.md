# Fontana — consulta automática de DNI y RUC

## Funcionamiento

En **Ventas**, el botón «Buscar DNI / Buscar RUC» fue retirado. La consulta se inicia automáticamente cuando el campo alcanza la longitud correcta:

- **Boleta:** 8 dígitos para DNI.
- **Factura:** 11 dígitos para RUC.

Al encontrar el documento, el sistema completa:

- DNI: nombre completo.
- RUC: razón social y dirección; también muestra estado y condición cuando el proveedor los devuelve.

La consulta funciona al escribir o pegar el documento. Antes de completar la cantidad de dígitos no se realiza ninguna solicitud. Se aplica una espera breve para evitar llamadas repetidas, se cancela una consulta anterior si el número cambia y se ignoran respuestas atrasadas.

Si el usuario pulsa **Proceder con la venta** mientras la consulta está terminando, el formulario espera el resultado y continúa una sola vez. Cuando la consulta no está disponible o no encuentra el documento, los datos pueden completarse manualmente.

## Instalación

El ZIP contiene el sistema completo dentro de `fontana_mejorado`.

1. Respalda la carpeta actual y la base de datos.
2. Sustituye la carpeta del proyecto por esta versión o copia su contenido sobre una copia de pruebas.
3. Conserva tus archivos privados de conexión y configuración cuando tengan valores distintos.
4. Abre Ventas y recarga con `Ctrl + F5`.

Esta mejora **no modifica SQL, tablas, columnas ni registros**. Tampoco cambia el QR Izipay incorporado en la entrega anterior.

## Requisitos de consulta

La computadora debe tener Internet y `config/api.php` debe contener una configuración válida. Las credenciales privadas deben conservarse únicamente en el servidor y no publicarse en repositorios.

## Verificación incluida

La entrega incluye `tests/interfaz_documentos_automaticos.py`, que utiliza respuestas controladas y comprueba: inicio por longitud exacta, DNI, RUC, pegado, cancelación de solicitudes anteriores, respuestas atrasadas, errores, restauración de borradores, continuación de la venta y vista móvil.

La prueba no consulta personas reales ni reemplaza la verificación con el proveedor configurado en la instalación del usuario.
