<?php /* Vista extraída para poder probarla con datos ficticios sin una base real. */ ?>
<div class="pay-steps"><span>1 · Productos seleccionados</span><strong>2 · Confirmar pago</strong><span>3 · Venta e inventario</span></div>
<?php if (pagoEsAcademico($o)): ?><p class="payment-test-note"><strong>Modo de prueba · No se realizan cobros reales.</strong></p><?php endif; ?>
<div class="payment-layout">
<section class="panel payment-summary">
  <div class="pay-eyebrow">PEDIDO #<?php echo (int)$o['id_orden']; ?></div>
  <h2>Resumen de compra</h2>
  <p><?php echo h($receipt['cliente_nombre']); ?><br><small><?php echo h(saleReceiptLabel($receipt['tipo_comprobante'])); ?> · <?php echo h($receipt['numero_documento'] ?: 'Sin documento'); ?></small></p>
  <div class="pay-items"><?php foreach($lineas as $l): ?><div><span><strong><?php echo h($l['nombre']); ?></strong><small><?php echo (int)$l['cantidad']; ?> × <?php echo h(pagoMoneda((int)$l['precio_centimos'])); ?></small></span><strong><?php echo h(pagoMoneda((int)$l['subtotal_centimos'])); ?></strong></div><?php endforeach; ?></div>
  <div class="pay-total"><span>Total a cobrar</span><strong><?php echo h(pagoMoneda((int)$o['total_centimos'])); ?></strong><small>Soles peruanos</small></div>
  <div class="kpi-row"><strong>Método elegido</strong><span><?php echo h($o['metodo']); ?></span></div>
  <p class="muted">Revisa los productos y el importe antes de confirmar.</p>
  <a class="btn secondary" href="pagos.php">Volver a pagos</a>
</section>
<section class="form-panel payment-workspace">
  <span id="payment-state" class="pay-state <?php echo h($status['estado']); ?>"><?php echo h($status['etiqueta']); ?></span>
  <h2><?php echo $o['metodo']==='Efectivo' ? 'Cobro en efectivo' : ($o['metodo']==='Tarjeta' ? 'Pago con tarjeta' : 'Pago con '.$o['metodo']); ?></h2>
  <div id="payment-message" class="pay-notice" role="status" aria-live="polite" hidden></div>
  <div id="payment-alert" class="pay-notice warning" <?php echo empty($o['alerta']) ? 'hidden' : ''; ?>><?php echo h($o['alerta'] ?? ''); ?></div>
  <div id="payment-result" class="payment-result" hidden><h3 id="result-title"></h3><p id="result-detail"></p><p id="result-reference"></p><a href="ventas.php" class="btn">Nueva venta</a><a href="" class="btn secondary">Ver detalle</a><?php if(pagoEsAcademico($o)): ?><a id="test-receipt" class="btn secondary" href="comprobante_prueba.php?orden=<?php echo h($o['publico']); ?>" target="_blank" rel="noopener" hidden>Comprobante de prueba</a><?php endif; ?></div>
  <?php if($o['proveedor']==='manual'): ?>
  <form id="manual-payment-form" class="pay-form">
    <?php if($o['metodo']==='Efectivo'): ?>
    <p>Recibe el efectivo y verifica el vuelto antes de confirmar la venta.</p>
    <label for="recibido">Efectivo recibido</label><input type="number" inputmode="decimal" id="recibido" name="recibido" min="<?php echo h(pagoDecimal((int)$o['total_centimos'])); ?>" step="0.01" required placeholder="0.00">
    <div class="pay-change">Vuelto <strong id="cash-change">S/ 0.00</strong></div>
    <?php else: ?>
    <p>Verifica que hayas recibido el pago antes de confirmar.</p>
    <?php if(in_array($o['metodo'],['Yape','Plin'],true)):
      $qr='img/qr_izipay.png';
      $qrFile=__DIR__.'/../'.$qr;
      $qrExists=is_file($qrFile);
      $qrSrc=$qrExists ? $qr.'?v='.rawurlencode((string)filemtime($qrFile)) : ''; ?>
      <?php if($qrExists): ?><img src="<?php echo h($qrSrc); ?>" class="pay-static-qr" alt="QR Izipay de INDULAC FONTANA SRL para pagar con Yape o Plin"><?php else: ?><div class="pay-notice">El QR Izipay del comercio todavía no está disponible. Verifica <code>img/qr_izipay.png</code>.</div><?php endif; ?>
      <p>Escanea este QR desde Yape o Plin y realiza el pago de <strong><?php echo h(pagoMoneda((int)$o['total_centimos'])); ?></strong>.</p>
    <?php else: ?><p>Cobra <?php echo h(pagoMoneda((int)$o['total_centimos'])); ?> en tu POS y verifica la aprobación del voucher. No escribas números de tarjeta ni CVV en Fontana.</p><?php endif; ?>
    <label for="referencia">Número de operación / voucher</label><input id="referencia" name="referencia" type="text" maxlength="50" required autocomplete="off">
    <?php endif; ?>
    <label class="pay-check"><input type="checkbox" name="confirmado" value="1" required> Confirmo que recibí el pago completo<?php echo $o['metodo']==='Efectivo' ? ' en efectivo' : ' y lo verifiqué en la cuenta o POS del comercio, no solo en una captura'; ?>.</label>
    <button id="confirm-manual" type="submit">Confirmar cobro y registrar venta</button>
  </form>
  <?php elseif($o['proveedor']==='demo'): ?>
  <?php include __DIR__.'/pago_demo_vista.php'; ?>
  <?php elseif($o['proveedor']==='mercadopago'): ?>
  <?php include __DIR__.'/pago_mp_vista.php'; ?>
  <?php else: ?>
  <div id="gateway-intro">
    <?php if($o['metodo']==='Tarjeta'): ?><p>Abre el formulario seguro de Izipay. El cliente ingresa allí los datos de su tarjeta; Fontana no los captura ni los guarda.</p>
    <?php else: ?><p>El QR generado por Izipay llevará <strong><?php echo h(pagoMoneda((int)$o['total_centimos'])); ?></strong>. El cliente lo escanea con su billetera compatible, revisa el importe y autoriza. No necesita escribir el monto.</p><p class="muted">Yape y Plin utilizan el mismo canal QR interoperable. La disponibilidad depende de la habilitación del comercio y de la billetera.</p><?php endif; ?>
  </div>
  <form id="gateway-payment-form" class="pay-form">
    <details class="billing-details" <?php echo !$o['billing_json'] ? 'open' : ''; ?>><summary>Datos del pagador</summary>
      <p class="muted">Completa los datos de la persona que realiza el pago.</p>
      <div class="form-grid">
      <?php foreach(['firstName'=>['Nombres','text',50], 'lastName'=>['Apellidos','text',50], 'email'=>['Correo electrónico','email',100], 'phoneNumber'=>['Teléfono','tel',15], 'street'=>['Dirección','text',100], 'city'=>['Ciudad','text',50], 'state'=>['Departamento','text',50], 'postalCode'=>['Código postal','text',10]] as $field=>$spec): ?>
      <div><label for="billing-<?php echo h($field); ?>"><?php echo h($spec[0]); ?></label><input id="billing-<?php echo h($field); ?>" name="<?php echo h($field); ?>" type="<?php echo h($spec[1]); ?>" maxlength="<?php echo $spec[2]; ?>" value="<?php echo h($billing[$field] ?? ''); ?>" required></div>
      <?php endforeach; ?>
      <div><label for="billing-documentType">Tipo de documento del pagador</label><select name="documentType" id="billing-documentType"><?php foreach(['DNI','RUC','CE','PASAPORTE'] as $doc): ?><option <?php echo ($billing['documentType'] ?? 'DNI')===$doc ? 'selected' : ''; ?>><?php echo h($doc); ?></option><?php endforeach; ?></select></div>
      <div><label for="billing-document">Documento del pagador</label><input name="document" id="billing-document" type="text" maxlength="15" value="<?php echo h($billing['document'] ?? ''); ?>" required></div>
      </div>
    </details>
    <button type="submit" id="launch-checkout"><?php echo $o['estado']==='en_proceso' ? 'Reabrir la misma sesión de pago' : ($o['metodo']==='Tarjeta' ? 'Abrir pago seguro por ' : 'Generar QR por ').pagoMoneda((int)$o['total_centimos']); ?></button>
    <p class="muted">Espera la confirmación del pago antes de entregar el pedido.</p>
  </form>
  <?php endif; ?>
  <div id="payment-wait" class="pay-notice" hidden><span class="payment-pulse"></span> Consultando confirmación. No entregues el pedido ni vuelvas a cobrar hasta ver el resultado.</div>
  <div class="pay-toolbar"><button type="button" class="btn secondary" id="refresh-status">Consultar estado</button><button type="button" class="btn danger" id="cancel-order">Cancelar pedido</button></div>
  <?php if(isAdmin() && ($o['proveedor']==='izipay' || in_array($o['entorno'],['demo_venta','sandbox_venta'],true))): ?>
  <details id="admin-payment-tools" class="billing-details"><summary>Revisión del administrador</summary>
    <p>Comprueba el estado del cobro antes de corregir su registro. Cerrar esta página no anula una operación pendiente.</p>
    <label for="admin-note">Evidencia / motivo de la revisión</label><textarea id="admin-note" maxlength="350" placeholder="Referencia del panel, resultado comprobado y acción realizada"></textarea>
    <label class="pay-check"><input id="admin-no-charge" type="checkbox"> Verifiqué que no hubo cobro y que la operación ya no puede pagarse en el proveedor (o corresponde al modo de prueba).</label>
    <button type="button" class="btn secondary" id="reconcile-order">Registrar una venta con pago ya verificado</button>
  </details>
  <?php endif; ?>
  <p class="pay-footnote">Pedido: <?php echo h($o['numero_externo']); ?> · Válido hasta <?php echo fmtFecha($o['reserva_hasta']); ?>.</p>
</section>
</div>
<script type="application/json" id="payment-bootstrap"><?php echo json_encode(['orden'=>$o['publico'],'csrf_token'=>csrfToken(),'provider'=>$o['proveedor'],'estado'=>$status],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); ?></script>
<script src="js/pagos.js?v=cobro-20260906-1" defer></script>
<?php if(in_array($o['proveedor'],['demo','mercadopago'],true)): ?><script src="js/vendor/fontana-qr.js" defer></script><script src="js/pagos-academicos.js?v=cobro-20260906-1" defer></script><?php endif; ?>
