<?php
session_start(); require 'conexion.php'; require_once 'includes/pagos.php'; requireLogin();
header('Cache-Control: no-store, private');
$pageTitle='Pagos y cobros'; $activePage='pagos'; $ready=pagoSchemaReady($conn) && (!in_array(pagoConfig()['mode'],['demo','mercadopago_sandbox'],true) || pagoAcademicoSchemaReady($conn)); $user=currentUser();
$states=['todos','pendiente','en_proceso','revision','pagado','cancelado','prueba','rechazado'];
$filter=(string)($_GET['estado'] ?? 'todos'); if (!in_array($filter,$states,true)) $filter='todos';
$page=max(1,min(100000,(int)($_GET['pagina'] ?? 1))); $offset=($page-1)*30;
$orders=[]; $count=0;
if ($ready) {
    $where=[]; $args=[];
    if (!isAdmin()) { $where[]='o.id_usuario=?'; $args[]=(int)$user['id_usuario']; }
    if ($filter!=='todos') { $where[]='o.estado=?'; $args[]=$filter; }
    $sqlWhere=$where ? ' WHERE '.implode(' AND ',$where) : '';
    $count=(int)pagoUno($conn,'SELECT COUNT(*) n FROM pagos_ordenes o'.$sqlWhere,$args)['n'];
    $orders=pagoTodos($conn,'SELECT o.*,u.nombre AS cajero FROM pagos_ordenes o JOIN usuarios u ON u.id_usuario=o.id_usuario'.$sqlWhere." ORDER BY o.id_orden DESC LIMIT 30 OFFSET $offset",$args);
}
include 'includes/header.php';
?>
<div class="pay-toolbar"><p>Consulta tus pedidos y continúa los cobros pendientes sin duplicarlos.</p><div class="actions"><a href="ventas.php" class="btn">Nueva venta</a><?php if(isAdmin()): ?><a href="configurar_pagos.php" class="btn secondary">Configuración</a><?php endif; ?></div></div>
<?php if (!$ready): ?><div class="pay-notice warning">Falta instalar el módulo. Un administrador debe ingresar a Configuración.</div>
<?php else: ?>
<form method="GET" class="pay-filter"><label for="estado">Estado</label><select id="estado" name="estado"><?php foreach($states as $state): ?><option value="<?php echo h($state); ?>" <?php echo $state===$filter ? 'selected' : ''; ?>><?php echo h(ucfirst(str_replace('_',' ',$state))); ?></option><?php endforeach; ?></select><button type="submit" class="btn secondary">Filtrar</button></form>
<section class="table-panel"><div class="table-responsive"><table><thead><tr><th>Orden / fecha</th><th>Método</th><th>Total</th><th>Estado</th><th>Cajero</th><th></th></tr></thead><tbody>
<?php foreach($orders as $o): $status=pagoEstadoPublico($o); ?>
<tr><td><strong>#<?php echo (int)$o['id_orden']; ?></strong><br><small><?php echo fmtFecha($o['creado']); ?></small></td><td><?php echo h($o['metodo']); ?><br><small><?php echo pagoEsAcademico($o) ? 'Modo de prueba' : ''; ?></small></td><td><?php echo h(pagoMoneda((int)$o['total_centimos'])); ?></td><td><span class="pay-state <?php echo h($status['estado']); ?>"><?php echo h($status['etiqueta']); ?></span><?php if($o['alerta']): ?><br><small class="pay-warning-text">Tiene una alerta: revisar detalle</small><?php endif; ?></td><td><?php echo h($o['cajero']); ?></td><td><a class="btn secondary btn-small" href="pago.php?orden=<?php echo h($o['publico']); ?>">Abrir</a></td></tr>
<?php endforeach; ?>
<?php if(!$orders): ?><tr><td colspan="6">No hay órdenes para este filtro.</td></tr><?php endif; ?>
</tbody></table></div></section>
<div class="actions"><?php if($page>1): ?><a class="btn secondary" href="?estado=<?php echo h($filter); ?>&amp;pagina=<?php echo $page-1; ?>">Anterior</a><?php endif; ?><span>Página <?php echo $page; ?> · <?php echo $count; ?> órdenes</span><?php if($offset+30<$count): ?><a class="btn secondary" href="?estado=<?php echo h($filter); ?>&amp;pagina=<?php echo $page+1; ?>">Siguiente</a><?php endif; ?></div>
<?php endif; include 'includes/footer.php'; ?>
