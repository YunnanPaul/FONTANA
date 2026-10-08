# Fontana — QR Izipay compartido para Yape y Plin

## Funcionamiento entregado

El sistema conserva su lógica de ventas y base de datos. El único cambio funcional es el QR de cobro:

1. El cajero selecciona productos y elige **Yape** o **Plin**.
2. Pulsa **Proceder con la venta**.
3. La ventana de cobro se abre automáticamente.
4. Se muestra el mismo QR Básico de Izipay de **INDULAC FONTANA SRL** para ambos métodos.
5. Fontana muestra el total calculado por el servidor y la venta se registra únicamente después de pulsar **Confirmar pago y registrar venta**.

No se añadió ninguna tabla, columna ni migración. Los archivos SQL y la conexión permanecen sin cambios respecto de la versión base.

## Cambiar el QR

Reemplaza únicamente:

```text
img/qr_izipay.png
```

Mantén el nombre y usa una imagen PNG cuadrada y legible. El sistema añade una versión basada en la fecha del archivo para evitar que el navegador siga mostrando el QR anterior por caché.

## Alcance del QR Básico

El QR incluido es estático. La pantalla de Fontana muestra el total automáticamente, pero el importe dentro de Yape o Plin y la confirmación bancaria automática no pueden obtenerse a partir de una imagen QR Básico. Para esas dos funciones se requieren las credenciales de Izipay Punto Web y la generación de una orden QR dinámica por cada venta.

## Instalación

1. Respalda la carpeta actual y la base de datos.
2. Copia la carpeta `fontana_mejorado` en `C:\xampp\htdocs\`.
3. Conserva tu configuración privada de conexión y de consultas documentales si ya la personalizaste.
4. Inicia Apache y MySQL.
5. Abre `http://localhost/fontana_mejorado/` y recarga con `Ctrl + F5`.

No necesitas reinstalar XAMPP ni volver a importar la base para este cambio.
