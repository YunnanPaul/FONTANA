<?php
session_start(); require 'conexion.php'; require_once 'includes/pagos.php'; requireLogin();
header('Cache-Control: no-store, private'); header('Referrer-Policy: no-referrer'); header('X-Frame-Options: DENY');
try {
    $o=pagoOrden($conn,(string)($_GET['orden'] ?? ''),currentUser());
    if (!pagoEsAcademico($o) || !in_array($o['estado'],['prueba','pagado'],true)) throw new InvalidArgumentException('Solo se emiten resúmenes de pruebas aprobadas.');
    $lines=pagoTodos($conn,'SELECT * FROM pagos_lineas WHERE id_orden=? ORDER BY id_linea',[(int)$o['id_orden']]);
} catch(InvalidArgumentException $e) { http_response_code(404); exit(h($e->getMessage())); }
?><!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fontana · Resumen #<?php echo (int)$o['id_orden']; ?></title><link rel="stylesheet" href="css/estilos.css"><link rel="stylesheet" href="css/pagos.css?v=cobro-20260906-1"></head>
<body class="demo-mobile-body"><main class="demo-mobile-card">
<div class="demo-brand">FONTANA <span>COMPROBANTE DE PRUEBA</span></div>
<div class="pay-notice academic-banner"><strong>SIN VALIDEZ TRIBUTARIA</strong><br>Modo de prueba. No acredita una transferencia ni constituye un comprobante electrónico de SUNAT.</div>
<h1>Orden #<?php echo (int)$o['id_orden']; ?></h1><p><?php echo h(fmtFecha($o['actualizado'])); ?><br>Método: <?php echo h($o['metodo']); ?><br>Origen: <?php echo $o['proveedor']==='demo' ? 'Simulador propio de Fontana' : 'Servicio de pago de prueba'; ?></p>
<div class="pay-items"><?php foreach($lines as $l): ?><div><span><strong><?php echo h($l['nombre']); ?></strong><small><?php echo (int)$l['cantidad']; ?> × <?php echo h(pagoMoneda((int)$l['precio_centimos'])); ?></small></span><strong><?php echo h(pagoMoneda((int)$l['subtotal_centimos'])); ?></strong></div><?php endforeach; ?></div>
<div class="pay-total"><span>Total de prueba</span><strong><?php echo h(pagoMoneda((int)$o['total_centimos'])); ?></strong></div>
<?php if($o['metodo']==='Efectivo' && $o['vuelto_centimos']!==null): ?><p>Vuelto simulado: <?php echo h(pagoMoneda((int)$o['vuelto_centimos'])); ?></p><?php endif; ?>
<p class="pay-footnote">Referencia: <?php echo h($o['referencia']); ?><br><?php echo $o['id_venta'] ? 'Venta de prueba #'.(int)$o['id_venta'].' registrada en una base de pruebas separada.' : 'No modificó ventas, caja ni inventario reales.'; ?></p>
<p class="no-print">Para imprimir, utiliza la opción Imprimir de tu navegador (Ctrl+P).</p><a class="btn secondary no-print" href="pago.php?orden=<?php echo h($o['publico']); ?>">Volver al pago</a>
</main></body></html>
