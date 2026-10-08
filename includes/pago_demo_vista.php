<?php /* Aviso de prueba único en la vista principal. El QR no es bancario. */ ?>
<div id="academic-workspace" class="academic-panel">
  <?php if($o['metodo']!=='Efectivo'): ?>
  <div class="academic-qr-card">
    <div class="pay-eyebrow">ESCANEA PARA CONTINUAR</div>
    <div id="demo-qr" class="academic-qr" role="img" aria-label="QR de prueba de Fontana"><span>Preparando QR…</span></div>
    <strong class="academic-amount"><?php echo h(pagoMoneda((int)$o['total_centimos'])); ?></strong>
    <p>Escanea con la cámara del celular.<br><small>Se abre en Fontana; no es un QR bancario.</small></p>
    <a id="demo-open" class="btn secondary" target="_blank" rel="noopener noreferrer" hidden>Abrir pago en otra pestaña</a>
    <button id="demo-retry-qr" class="btn secondary" type="button" hidden>Reintentar conexión</button>
    <p id="demo-network-note" class="pay-footnote"></p>
  </div>
  <?php else: ?>
  <label for="demo-cash">Monto recibido</label>
  <input id="demo-cash" type="number" min="<?php echo h(pagoDecimal((int)$o['total_centimos'])); ?>" step="0.01" inputmode="decimal" value="<?php echo h(pagoDecimal((int)$o['total_centimos'])); ?>">
  <div class="pay-change">Vuelto <strong id="demo-change">S/ 0.00</strong></div>
  <?php endif; ?>
  <details class="billing-details" <?php echo $o['metodo']==='Efectivo' ? 'open' : ''; ?>><summary>Controles de prueba</summary>
    <div class="demo-actions">
      <button type="button" data-demo-result="aprobado">Aprobar prueba</button>
      <button type="button" class="btn secondary" data-demo-result="pendiente">Mantener pendiente</button>
      <button type="button" class="btn danger" data-demo-result="rechazado">Rechazar prueba</button>
    </div>
  </details>
</div>
