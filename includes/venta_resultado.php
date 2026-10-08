<?php
$result = $_SESSION['pago_confirmar'] ?? null;
unset($_SESSION['pago_confirmar']);
if (is_array($result)):
?>
<div class="pago-overlay" id="pago-overlay" role="dialog" aria-modal="true" aria-labelledby="sale-result-title">
    <div class="pago-card">
        <div class="pago-head"><span class="pago-metodo"><?php echo h($result['metodo']); ?></span><span class="pago-venta">Venta N° <?php echo (int)$result['venta']; ?></span></div>
        <h2 id="sale-result-title">Venta registrada</h2>
        <div class="pago-monto">Total <strong><?php echo h(money($result['total'])); ?></strong></div>
        <p>La venta se guardó correctamente.</p>
        <?php if (($result['recibido_centimos'] ?? null) !== null): ?><p>Recibido: <?php echo h(pagoMoneda((int)$result['recibido_centimos'])); ?></p><div class="pago-vuelto">Vuelto: <strong><?php echo h(pagoMoneda((int)$result['vuelto_centimos'])); ?></strong></div><?php endif; ?>
        <?php if (($result['referencia'] ?? '') !== ''): ?><p>Referencia: <strong><?php echo h(($result['referencia'] ?? '')); ?></strong></p><?php endif; ?>
        
        <div class="pago-acciones"><a class="btn" href="ventas.php">Continuar / nueva venta</a></div>
    </div>
</div>
<?php endif; ?>
