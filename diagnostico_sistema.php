<?php
session_start();
require 'conexion.php'; require_once 'includes/pagos.php'; requireLogin(); requireAdmin();
header('Cache-Control: no-store, private');
$pageTitle='Diagnóstico de compatibilidad'; $activePage='pagos';
$base=pagoUno($conn,'SELECT DATABASE() nombre, VERSION() version');
$expected=['usuarios','sesiones_activas','productos','ventas','detalle_venta','movimientos_inventario','cierres_caja','solicitudes_reposicion'];
$tables=pagoTodos($conn,'SELECT TABLE_NAME,ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE()');
$present=[]; foreach ($tables as $t) $present[$t['TABLE_NAME']]=$t['ENGINE'];
$columns=pagoTodos($conn,"SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('ventas','cierres_caja')");
$found=[];foreach ($columns as $c) $found[$c['TABLE_NAME']][$c['COLUMN_NAME']]=true;
$docColumns=['referencia_pago','tipo_comprobante','tipo_documento','numero_documento','cliente_nombre','cliente_direccion','documento_consultado','api_proveedor'];
$missingDocs=array_values(array_filter($docColumns,fn($c)=>!isset($found['ventas'][$c])));
$ready=pagoSchemaReady($conn) && pagoAcademicoSchemaReady($conn);
include 'includes/header.php';
?>
<section class="panel">
<h2>Conexión y estructura seleccionadas</h2>
<p>Base: <strong><?php echo h($base['nombre']); ?></strong>. Servidor SQL: <?php echo h($base['version']); ?>. PHP: <?php echo h(PHP_VERSION); ?>.</p>
<p>Esta pantalla no instala ni borra tablas y no modifica datos de negocio. No es una prueba de cobro ni una validación de todos los datos existentes.</p>
<table><thead><tr><th>Tabla original</th><th>Estado</th><th>Motor</th></tr></thead><tbody>
<?php foreach ($expected as $table): ?><tr><td><?php echo h($table); ?></td><td><?php echo isset($present[$table]) ? 'Presente' : 'FALTA: revisar conexión/esquema'; ?></td><td><?php echo h($present[$table] ?? '—'); ?></td></tr><?php endforeach; ?>
</tbody></table>
<p>Columnas de comprobantes: <strong><?php echo $missingDocs ? 'Pendientes: '.h(implode(', ',$missingDocs)) : 'Presentes'; ?></strong>.</p>
<p>Pagos avanzados: <strong><?php echo $ready ? 'Columnas requeridas presentes' : 'Opcional, no instalado o incompleto'; ?></strong>. Las ventas habituales no exigen una pasarela.</p>
<p>Separación del efectivo: <strong><?php echo isset($found['cierres_caja']['total_efectivo']) ? 'Disponible para cierres nuevos' : 'Se conserva el cálculo original'; ?></strong>.</p>
<p>Conexión con el servicio de cobros: <strong><?php echo extension_loaded('curl') ? 'Disponible' : 'No disponible; la confirmación manual sigue disponible'; ?></strong>.</p>
<div class="actions"><a href="ventas.php" class="btn">Volver a ventas</a><a href="configurar_pagos.php" class="btn secondary">Ampliación opcional</a></div>
</section>
<?php include 'includes/footer.php'; ?>
