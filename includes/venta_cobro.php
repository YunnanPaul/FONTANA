<?php
// Se abre automáticamente después de preparar el pedido. No crea ventas al renderizar.
$cobroKey = is_string($_GET['cobro'] ?? null) ? $_GET['cobro'] : '';
$cobro = preg_match('/^[a-f0-9]{64}$/D', $cobroKey) ? ($_SESSION['ventas_por_cobrar'][$cobroKey] ?? null) : null;
if (is_array($cobro) && (int)$cobro['id_usuario'] === (int)currentUser()['id_usuario'] && !isset($_SESSION['ventas_finalizadas'][$cobroKey])):
    $cash = $cobro['metodo'] === 'Efectivo';
    $wallet = in_array($cobro['metodo'], ['Yape', 'Plin'], true);
    // Yape y Plin comparten el QR Básico interoperable de Izipay del comercio.
    $qr = $wallet ? 'img/qr_izipay.png' : '';
    $qrFile = $qr !== '' ? __DIR__ . '/../' . $qr : '';
    $hasQr = $qrFile !== '' && is_file($qrFile);
    // Evita que el navegador conserve un QR anterior después de reemplazar la imagen.
    $qrSrc = $hasQr ? $qr . '?v=' . rawurlencode((string)filemtime($qrFile)) : '';
    $expired = (int)$cobro['vence'] < time();
?>
<div class="pago-overlay" id="cobro-overlay" role="dialog" aria-modal="true" aria-labelledby="cobro-title" aria-describedby="cobro-note" data-total="<?php echo (int)$cobro['total_centimos']; ?>">
  <section class="pago-card cobro-card" tabindex="-1">
    <div class="pago-head"><span class="pago-metodo pago-<?php echo h(strtolower($cobro['metodo'])); ?>"><?php echo h($cobro['metodo']); ?></span><span class="pago-venta">Confirmar cobro</span></div>
    <h2 id="cobro-title"><?php echo $cash ? 'Pago en efectivo' : 'Pago con ' . h($cobro['metodo']); ?></h2>
    <div class="pago-monto">Total a pagar <strong><?php echo h(pagoMoneda((int)$cobro['total_centimos'])); ?></strong></div>
    <?php if (!empty($flash) && ($flash['type'] ?? '') === 'error'): ?><div class="pay-notice warning" role="alert"><?php echo h($flash['message']); ?></div><?php endif; ?>
    <?php if ($expired): ?>
      <p id="cobro-note" class="pay-notice warning">Este cobro venció. Vuelve al pedido para revisar el total y las existencias.</p>
    <?php else: ?>
      <?php if ($wallet): ?>
        <?php if ($hasQr): ?>
          <img class="pago-qr" id="cobro-qr" src="<?php echo h($qrSrc); ?>" alt="QR Izipay de INDULAC FONTANA SRL para pagar con Yape o Plin">
          <p id="cobro-qr-missing" class="pay-notice warning" hidden>No se pudo cargar el QR. Verifica la imagen del comercio antes de continuar.</p>
          <p class="pago-instruccion">Escanea este QR desde Yape o Plin y realiza el pago de <strong><?php echo h(pagoMoneda((int)$cobro['total_centimos'])); ?></strong>.</p>
        <?php else: ?>
          <p class="pago-qr-falta">El QR Izipay del comercio todavía no está disponible. Verifica el archivo <code>img/qr_izipay.png</code>.</p>
        <?php endif; ?>
      <?php elseif (!$cash): ?>
        <p class="pago-tarjeta">Realiza el cobro de <?php echo h(pagoMoneda((int)$cobro['total_centimos'])); ?> en el terminal de tarjetas y verifica su aprobación.</p>
      <?php endif; ?>
      <form method="POST" action="ventas.php" id="confirmar-cobro-form" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
        <input type="hidden" name="idempotencia" value="<?php echo h($cobroKey); ?>">
        <input type="hidden" name="etapa_venta" value="confirmar">
        <input type="hidden" name="confirmado" value="1">
        <?php if ($cash): ?>
          <div class="pago-efectivo"><label for="cobro-recibido">Monto recibido</label><input type="number" name="recibido" id="cobro-recibido" min="<?php echo h(pagoDecimal((int)$cobro['total_centimos'])); ?>" step="0.01" inputmode="decimal" placeholder="0.00" value="<?php echo h($cobro['recibido'] ?? ''); ?>" required autofocus>
          <button type="button" class="btn secondary btn-small" id="cobro-exacto">Importe exacto</button>
          <div class="pago-vuelto">Vuelto <strong id="cobro-vuelto">S/ 0.00</strong></div></div>
        <?php else: ?>
          <label for="cobro-referencia">N.° de operación / voucher <span class="muted">(opcional)</span></label><input type="text" name="referencia_pago" id="cobro-referencia" maxlength="50" value="<?php echo h($cobro['referencia_pago'] ?? ''); ?>" placeholder="Número de operación">
        <?php endif; ?>
        <p id="cobro-note" class="pago-nota"><?php echo $cash ? 'Confirma el efectivo recibido antes de registrar la venta.' : 'Verifica el pago en tu cuenta o terminal antes de confirmar.'; ?></p>
        <button type="submit" class="btn success" id="confirmar-cobro">Confirmar pago y registrar venta</button>
      </form>
    <?php endif; ?>
    <form method="POST" action="ventas.php" id="volver-pedido-form">
      <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
      <input type="hidden" name="idempotencia" value="<?php echo h($cobroKey); ?>">
      <input type="hidden" name="etapa_venta" value="cancelar">
      <button type="submit" class="btn secondary">Volver al pedido</button>
    </form>
  </section>
</div>
<script src="js/venta-cobro.js?v=cobro-20260906-1" defer></script>
<?php endif; ?>
