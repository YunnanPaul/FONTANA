# Consulta de DNI/RUC en Ventas

## Funcionalidad agregada

- Selección de **Boleta de venta** o **Factura** en `ventas.php`.
- Búsqueda de **DNI** para boleta y **RUC** para factura.
- Autocompletado de nombre/razón social y dirección cuando la API la devuelve.
- Validación de DNI de 8 dígitos y RUC de 11 dígitos con dígito verificador.
- El token se consume únicamente desde PHP; nunca se envía al navegador.
- La consulta requiere sesión iniciada, usa POST, protección CSRF y no almacena respuestas en caché.
- La venta guarda tipo de comprobante, documento, cliente, dirección y si el documento fue verificado por API.
- Reportes actualizados para mostrar comprobante y cliente.

## 1. Obtener y configurar el token

La integración está preparada para **Decolecta**.

1. Crea una cuenta en Decolecta y genera un token.
2. Abre `config/api.php`.
3. Pega el token en el valor `token`:

```php
'token' => 'TU_TOKEN_AQUI',
```

En un servidor de producción es preferible definir la variable de entorno:

```text
DECOLECTA_API_TOKEN=TU_TOKEN_AQUI
```

La variable de entorno tiene prioridad sobre el archivo.

## 2. Requisitos del servidor

- PHP 7.4 o superior.
- Extensión PHP `mysqli`.
- Extensión PHP `curl` habilitada o, como respaldo, `allow_url_fopen=On`.
- Conexión HTTPS saliente hacia `api.decolecta.com`.
- El usuario de MySQL debe tener permiso `ALTER` para la actualización automática inicial.

## 3. Actualización de la base de datos

Al abrir `ventas.php`, el sistema revisa la tabla `ventas` y agrega automáticamente las columnas faltantes.

Como alternativa, ejecuta una sola vez:

```text
sql/migracion_api_dni_ruc.sql
```

Para una instalación nueva, `sql/fontana_db.sql` ya contiene la estructura actualizada.

## 4. Flujo de uso

### Boleta

- Selecciona **Boleta de venta**.
- El DNI es opcional.
- Sin DNI, la venta se guarda como **CONSUMIDOR FINAL**.
- Con DNI, escribe o pega los 8 dígitos. La consulta comienza automáticamente y completa el nombre.

### Factura

- Selecciona **Factura**.
- Ingresa un RUC válido de 11 dígitos.
- Con RUC, escribe o pega los 11 dígitos. La consulta comienza automáticamente y completa la razón social y la dirección.
- La razón social y el RUC son obligatorios para registrar la venta como factura.

## Importante sobre SUNAT

Esta actualización resuelve la **consulta de identidad y el registro de datos del cliente/comprobante** dentro del sistema. No genera, firma ni envía por sí sola un Comprobante de Pago Electrónico a SUNAT.

Para una emisión electrónica con validez tributaria todavía se necesita integrar un proveedor CPE/OSE o el SEE del contribuyente, además de configurar RUC emisor, series, correlativos, certificado digital, impuestos, XML UBL, envío y CDR.
