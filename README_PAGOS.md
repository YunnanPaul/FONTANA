> **Actualización de interfaz:** lee primero `LEEME_INTERFAZ.md`. Ventas ya no utiliza el selector de modalidad; abre el cobro automáticamente. Esta sección conserva la documentación anterior y no implica ejecutar otra importación SQL.

# Fontana — revisión de compatibilidad y pagos

## Antes de instalar

Este paquete sustituye la versión académica anterior. Conserva la carpeta `fontana_mejorado`, las rutas originales y las configuraciones documentales aportadas. Es privado: contiene la configuración RENIEC/SUNAT de tu proyecto. No lo publiques en GitHub ni como descarga pública.

**No borres tu base, no la renombres y no importes el SQL de un ZIP anterior.** Esta revisión corrige código y compatibilidad; no puede recuperar registros eliminados previamente ni conocer cambios que solo existen en tu computadora.

1. Respalda los archivos actuales y exporta tu base desde phpMyAdmin. Prueba primero en una carpeta separada y en una copia de la base.
2. Descomprime el ZIP. Conserva los datos de conexión de tu instalación. La conexión mantiene los valores originales por defecto: `127.0.0.1`, base `fontana_db`, usuario `root`, contraseña vacía. Puedes conservar tus valores en `conexion.php` o copiar `config/conexion.example.php` a `config/conexion.php` y editarlos allí. **No reemplaces una configuración personalizada por estos valores sin revisarla.** Si ya tienes `config/api.php` o `config/pagos.php` personalizados, consérvalos.
3. Abre Apache y MySQL en XAMPP, entra en la carpeta del proyecto e inicia sesión. Abre la caja y entra a **Ventas**. El modo seleccionado inicialmente es **Registro habitual**: registra venta, detalle y movimiento de inventario al confirmar. No requiere instalar pagos, credenciales TEST ni renombrar la base.
4. Para el QR y la API de pruebas, entra a **Pagos y cobros → Configuración → Instalar / verificar ampliación**. Con respaldo y sin usuarios operando, instala solamente esa ampliación. Después elige **Pago académico** en la modalidad de Ventas.

### Instalación nueva, no actualización

Solo para empezar desde cero, crea y selecciona una base VACÍA en phpMyAdmin e importa `fontana_db.sql` de ESTE ZIP. Es idéntico a `sql/fontana_db.sql`: usa `CREATE TABLE IF NOT EXISTS`, no contiene borrados ni cambia la base seleccionada. Las semillas de usuarios y productos solo se insertan si la base estaba completamente vacía. Mantiene los valores iniciales del proyecto original.

Repetirlo no restaura usuarios, no reinicia contraseñas y no duplica productos. **No es un reparador de tablas existentes.** Los otros SQL conservan funciones de migración específicas; no importes toda la carpeta SQL indiscriminadamente.

## Tres recorridos distintos

| Recorrido | Qué ocurre al aprobar | Configuración |
|---|---|---|
| Registro habitual, valor inicial | Se registra la venta, detalle e inventario como en el sistema original; reportes y caja leen esa venta. | Ninguna pasarela requerida. El cajero confirma el cobro manual. |
| Pago académico aislado | QR/operación TEST registrada en Pagos; no se crea venta ni se descuenta stock. La pantalla lo advierte. | `mode=demo` o `mercadopago_sandbox`, `academic_record_sales=false`. |
| Pago académico de ciclo completo | La aprobación crea una venta y descuenta stock EN LA BASE ACADÉMICA AUTORIZADA. | Autorización explícita abajo; nunca en una base operativa. |

El registro habitual solicita confirmar la recepción del pago antes de guardar. Para efectivo, muestra recibido y vuelto; para Yape, Plin y tarjetas permite conservar la referencia de operación. Esa confirmación es manual, no una verificación bancaria. El QR estático original de Yape se conserva dentro de las instrucciones desplegables; no incluye un monto bancario automático.

### Mostrar QR y registrar también la venta académica

Como tu sistema es académico, puedes exponer el ciclo completo sin cambiar el nombre de la base. Hazlo solo en una base que no contenga operaciones reales; preferentemente en una copia. Copia `config/pagos.example.php` a `config/pagos.php` y modifica:

```php
'mode' => 'demo',
'academic_record_sales' => true,
'academic_database' => 'NOMBRE_EXACTO_DE_TU_BASE_ACADEMICA',
```

Pon el nombre REAL que usa tu conexión, no el texto de ejemplo. Si tu base exclusivamente académica se llama `fontana_db`, puedes declarar ese nombre exacto; esto no la renombra, no crea otra base y no borra tablas. La comprobación alternativa por sufijos `_demo`, `_academico` o `_pruebas` continúa funcionando. El nombre declarado es tu autorización explícita; no demuestra por sí solo que una base sea de prueba.

**Crea una orden nueva después de cambiar esa opción.** Las órdenes antiguas conservan su modalidad y no se convierten retrospectivamente en ventas. No repitas una venta ya registrada para corregir el modo.

## QR desde un teléfono

Conecta teléfono y computadora a la misma Wi-Fi privada. En `base_url` coloca la dirección real de tu computadora y carpeta, por ejemplo `http://192.168.1.20/fontana_mejorado`. Sustituye IP, carpeta y puerto. Desde el teléfono comprueba que esa dirección abra el proyecto. `localhost` en el teléfono no apunta a la computadora.

Selecciona **Pago académico**, elige productos/método y continúa. Escanea con la **cámara del celular**, no dentro de Yape o Plin. La página de Fontana mostrará el importe de esa orden. También existe «Abrir simulador» para otra pestaña del mismo equipo. No se mueve dinero real.

## API externa conservada

El adaptador existente de Mercado Pago TEST para Yape por celular/código y tarjetas se conserva. Plin continúa como simulador local: no es una API oficial de Plin. Para activarlo configura las credenciales TEST en `config/pagos.php`, solo en el servidor. No pegues claves en chats, JavaScript ni repositorios. No se realizaron llamadas reales ni validaciones de cuenta durante esta revisión.

```php
'mode' => 'mercadopago_sandbox',
'mercadopago' => [
    'public_key' => 'TU_PUBLIC_KEY_TEST',
    'access_token' => 'TU_ACCESS_TOKEN_TEST',
    'webhook_secret' => '',
    'timeout_seconds' => 18,
],
```

Son marcadores, no credenciales funcionales. El simulador local no necesita Internet ni cURL; la API externa sí los requiere. El webhook es opcional para una exposición local con consulta al proveedor. La integración externa no está certificada ni probada con tu cuenta. Los adaptadores Izipay heredados permanecen para no eliminar archivos ni bloquear órdenes anteriores; no son el flujo predeterminado y esta revisión no los habilita.

## Base de datos y caja

El registro habitual utiliza las tablas originales. Las columnas documentales DNI/RUC siguen comprobándose con el mecanismo del proyecto original; la primera ejecución en una base antigua puede necesitar permiso `ALTER` para agregarlas. Eso es independiente de pagos.

La ampliación opcional valida columnas, motores y tipos de identificadores; añade `pagos_ordenes`, `pagos_lineas`, `pagos_eventos`, `pagos_demo`, `pagos_mercadopago` y la columna nullable `cierres_caja.total_efectivo`. No hace `DROP`, no recrea ventas ni sustituye datos. Si detecta una estructura incompleta, no la da por instalada. El DDL de MySQL no es una sola transacción reversible: si una instrucción falla, las anteriores pueden permanecer; revisa el diagnóstico antes de repetir.

**Caja funciona con ambos esquemas.** Sin `total_efectivo`, conserva el cálculo original (inicial + todas las ventas, incluidas digitales) y lo indica. Con esa columna, los cierres nuevos comparan lo contado con inicial + efectivo. Los históricos que no tenían desglose conservan su cálculo; no se reinterpretan ni reescriben. Las pruebas aisladas no bloquean un cierre como si fueran cobros reales; las órdenes de ciclo completo pendientes sí deben resolverse.

En **Pagos y cobros → Configuración → Ver diagnóstico** puedes ver la base seleccionada, tablas, motores y columnas presentes sin instalar una migración ni cambiar datos de negocio.

## Validación y límites

Lee `INFORME_REVISION.md`. Esta entrega se comprobó con sintaxis, pruebas de lógica, un doble explícito de almacenamiento y vistas renderizadas con datos de prueba. No se ejecutó MySQL/MariaDB, porque el entorno de construcción no disponía de ese servidor ni de `mysqli`. No se probó la API documental, una cuenta de pagos, tu red, tu PHP instalado ni una cámara física.

No se garantiza ausencia universal de errores. La prueba final es sobre la copia de TU base y TU XAMPP. La versión corregida no se conectó a tu servidor ni hizo cambios sobre tu instalación activa.

### Comandos reproducibles

Desde la carpeta del proyecto:

```text
php tests/unit.php
php tests/academico_unit.php
php -n tests/regresion_ventas.php
php tests/vistas_modulos.php
php tests/diagnostico.php
python tests/interfaz.py
python tests/interfaz_academica.py
python tests/interfaz_regresion.py
```

Los navegadores requieren Python, Playwright y Chromium, solo para desarrollo. El programa de negocio no requiere Python, Node ni Composer. Requisitos del sistema: PHP 8.0+ de 64 bits, `mysqli`/mysqlnd, JSON, sesiones y MySQL/MariaDB con tablas InnoDB; cURL y HTTPS para proveedor externo.

`tests/integracion_mysql.php` es optativo y NO se ejecutó aquí. Crea una base temporal aleatoria `fontana_test_…_pruebas`, realiza comprobaciones y elimina SOLO la base que acaba de crear. No usa `FONTANA_DB_NAME`. Úsalo únicamente contra un servidor local de pruebas con permiso CREATE/DROP DATABASE:

```text
Windows CMD:
set FONTANA_RUN_MYSQL_TESTS=1
php tests/integracion_mysql.php

Linux/macOS:
FONTANA_RUN_MYSQL_TESTS=1 php tests/integracion_mysql.php
```

Conexión de esa suite: `FONTANA_TEST_DB_HOST`, `FONTANA_TEST_DB_PORT`, `FONTANA_TEST_DB_USER`, `FONTANA_TEST_DB_PASSWORD`; por defecto localhost/root/contraseña vacía. No apuntes esas variables a producción. En Windows usa el `php.exe` de XAMPP si `php` no está en PATH. Un resultado `SKIP` no cuenta como aprobación.

## Verificación antes de reemplazar tu instalación

En una copia: inicia sesión administrador/cajero; crea y edita un producto y un insumo sin precio; abre caja; registra una venta habitual en efectivo y otra digital; confirma venta/detalle/stock/reportes; prueba stock insuficiente y reenvío; registra entrada/salida y reposición; cierra caja con el cálculo que indique tu esquema. Luego instala la ampliación, repítela y comprueba que no cambien tus datos anteriores. Prueba QR aislado y ciclo completo por separado; finalmente la API externa con tus llaves TEST. Revisa que no haya errores en el log de Apache/PHP.

Si un SQL anterior ya eliminó registros, detén las escrituras y conserva copias: este ZIP no recupera datos perdidos. La recuperación necesita un respaldo real o registros de recuperación del servidor. No reinserta operaciones ni reconstruye ventas imaginándolas.
