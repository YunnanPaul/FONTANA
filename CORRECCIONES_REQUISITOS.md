# Correcciones aplicadas para cumplir el punto 1.7 del informe

Estas correcciones alinean el sistema con los requisitos especificados en 1.7.

## RNF20 — Permisos por rol (el cajero no accede a módulos administrativos)
- Se agregó `requireAdmin()` a: `productos.php`, `inventario.php`, `reposicion.php`,
  `reportes.php` y `reporte_pdf.php`. Antes solo `usuarios.php` estaba protegido.
- El menú lateral (`includes/header.php`) ahora oculta al cajero los enlaces de
  Productos, Inventario, Reposición, Reportes y Usuarios.
- El dashboard (`index.php`) oculta al cajero los accesos rápidos administrativos.
- Resultado: el cajero solo accede a Dashboard, Ventas y Caja diaria, tal como
  indica el propio sistema. Si un cajero intenta abrir un módulo admin por URL,
  se le redirige con un mensaje.

## RNF11 — Fechas en formato comprensible
- Nueva función `fmtFecha()` en `includes/helpers.php` (formato `d/m/Y h:i a`).
- Aplicada en: `caja.php`, `inventario.php`, `reportes.php`, `reporte_pdf.php`,
  `ventas.php` y `usuarios.php`. Los importes ya salían con dos decimales.

## RNF17 — Reporte PDF en hoja A4
- `reporte_pdf.php` ahora fija `@page { size: A4 portrait; margin: 12mm; }` y ajusta
  el ancho del contenido a A4. El reporte se sigue imprimiendo/guardando desde el
  navegador, pero ya sale con tamaño A4 forzado.

## RF13 — Ajustes de inventario con motivo obligatorio
- `inventario.php` rechaza un ajuste sin motivo (validación en el servidor) y marca
  el campo como obligatorio en pantalla cuando el movimiento es "Ajuste".

## Pendientes que dependen del entorno (no del código)
- RNF01 (respuesta < 3 s), RNF02 (disponibilidad 95%), RNF07 (copias de seguridad)
  y RNF21 (HTTPS al publicar). Se evidencian con la operación y la configuración
  del servidor, no desde el programa.
