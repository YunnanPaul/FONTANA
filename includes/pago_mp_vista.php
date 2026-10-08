<div id="academic-workspace" class="academic-panel">

  <?php if($o['metodo']==='Yape'): ?>
  <p>Ingresa los datos de prueba de Yape para continuar. El importe ya está incluido.</p>
  <form id="mp-yape-form" class="pay-form">
    <label for="mp-test-phone">Resultado de prueba</label>
    <select id="mp-test-phone" required>
      <option value="111111111">Aprobado · 111111111</option>
      <option value="111111113">Fondos insuficientes · 111111113</option>
      <option value="111111114">Rechazado · 111111114</option>
      <option value="111111117">Código no válido · 111111117</option>
    </select>
    <label for="mp-test-otp">Código de aprobación de prueba</label><input id="mp-test-otp" value="123456" readonly>
    <label for="mp-test-email">Correo de prueba (@testuser.com)</label><input id="mp-test-email" type="email" value="test_user_fontana@testuser.com" maxlength="120" required>
    <button type="submit" id="mp-yape-submit">Continuar por <?php echo h(pagoMoneda((int)$o['total_centimos'])); ?></button>
  </form>
  <?php else: ?>
  <p><strong>Utiliza únicamente una tarjeta de prueba.</strong> No ingreses datos de una tarjeta personal.</p>
  <button type="button" id="mp-load-card">Abrir formulario de tarjeta de prueba</button>
  <div id="mp-card-brick"></div>
  <details class="billing-details"><summary>Datos de prueba</summary><p>Utiliza tarjetas de la documentación oficial de Mercado Pago Perú y un correo terminado en <code>@testuser.com</code>. Para simular aprobación, el titular de prueba es <strong>APRO</strong>. La guía del paquete contiene la referencia y los pasos.</p></details>
  <?php endif; ?>
  <button type="button" id="mp-refresh" class="btn secondary">Actualizar estado</button>
  <p class="pay-footnote">No repitas el pago mientras esté pendiente. Puedes actualizar su estado.</p>
</div>
