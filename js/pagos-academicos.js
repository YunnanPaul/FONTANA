(() => {
  'use strict';
  const app = window.FontanaPayments;
  if (!app) return;
  const {cfg, api, update, operate, message, money} = app;
  const el = id => document.getElementById(id);
  let sdkPromise = null, mp = null, brick = null, mpTimer = null, mpChecking = false;
  function active() { return ['pendiente', 'en_proceso'].includes(app.getState().estado); }
  function renderState(state) {
    // Keep the explicit API reconciliation button available even after an unknown result.
    const workspace = el('academic-workspace');
    if (workspace) workspace.hidden = !active() && !(cfg.provider === 'mercadopago' && state.estado === 'revision');
    el('demo-qr')?.classList.toggle('qr-expired', !active());
    if (el('mp-yape-form')) el('mp-yape-form').hidden = state.estado !== 'pendiente';
    if (el('mp-load-card')) el('mp-load-card').hidden = state.estado !== 'pendiente';
    if (el('mp-card-brick')) el('mp-card-brick').hidden = state.estado !== 'pendiente';
    // Local polling may update every 4.5s. Do not reset the independent API
    // timer on each update: that would postpone a 10s reconciliation forever.
    if (cfg.provider !== 'mercadopago' || state.estado !== 'en_proceso') {
      clearTimeout(mpTimer); mpTimer = null;
    } else if (mpTimer === null && !mpChecking) mpTimer = setTimeout(checkMpQuietly, 10000);
  }
  document.addEventListener('fontana:payment-state', event => renderState(event.detail));
  async function showQr() {
    if (el('demo-retry-qr')) el('demo-retry-qr').hidden = true;
    try {
    const response = await api('demo_preparar');
    const url = new URL(response.demo.url, window.location.href);
    if (!['http:', 'https:'].includes(url.protocol)) throw new Error('El enlace de pago no es válido.');
    window.FontanaQR.render(el('demo-qr'), url.href);
    el('demo-open').href = url.href; el('demo-open').hidden = false;
    const loopback = ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname);
    el('demo-network-note').textContent = loopback
      ? 'Este enlace solo abre en esta computadora. Para usar un celular, solicita configurar la dirección de la red local.'
      : 'Conecta el celular y esta computadora a la misma red Wi-Fi.';
    update(response.orden);
    } catch (error) {
      if (el('demo-retry-qr')) el('demo-retry-qr').hidden = !active();
      if (el('demo-qr')) el('demo-qr').textContent = 'No se pudo cargar el QR.';
      throw error;
    }
  }
  el('demo-retry-qr')?.addEventListener('click', () => operate(showQr));
  document.querySelectorAll('[data-demo-result]').forEach(button => button.addEventListener('click', () => operate(async () => {
    const response = await api('demo_resolver', {resultado: button.dataset.demoResult, recibido: el('demo-cash')?.value || ''});
    update(response.orden);
  })));
  el('demo-cash')?.addEventListener('input', event => {
    const match = /^(\d+)(?:\.(\d{0,2}))?$/.exec(event.target.value);
    const cents = match ? Number(match[1]) * 100 + Number((match[2] || '').padEnd(2, '0')) : 0;
    el('demo-change').textContent = money(Math.max(0, cents - app.getState().total_centimos));
  });
  async function sdk() {
    if (mp) return mp;
    const response = await api('mp_configurar');
    const data = response.checkout;
    if (!data.test_only || !/^TEST-/.test(data.public_key) || data.sdk !== 'https://sdk.mercadopago.com/js/v2')
      throw new Error('Revisa la configuración del servicio de pago de prueba.');
    if (!sdkPromise) sdkPromise = new Promise((resolve, reject) => {
      if (typeof window.MercadoPago === 'function') { resolve(); return; }
      const script = document.createElement('script'); script.src = data.sdk; script.async = true;
      const fail = () => { script.remove(); sdkPromise = null; reject(new Error('No se pudo cargar Mercado Pago. Revisa Internet y los bloqueadores de contenido. ')); };
      const timer = setTimeout(fail, 15000);
      script.onload = () => { clearTimeout(timer); typeof window.MercadoPago === 'function' ? resolve() : fail(); };
      script.onerror = () => { clearTimeout(timer); fail(); };
      document.head.appendChild(script);
    });
    await sdkPromise;
    mp = new window.MercadoPago(data.public_key, {locale: 'es-PE'});
    return mp;
  }
  async function sendPayment(formData) {
    message('Procesando pago…');
    try {
      const response = await api('mp_pagar', {formData});
      update(response.orden); message(''); return response;
    } catch (error) {
      // A timeout is never treated as a rejected/approved payment.
      try { const current = await api(null); update(current.orden); } catch { /* Show the original error. */ }
      throw error;
    }
  }
  el('mp-yape-form')?.addEventListener('submit', event => {
    event.preventDefault();
    operate(async () => {
      const provider = await sdk();
      const phoneNumber = el('mp-test-phone').value, otp = el('mp-test-otp').value;
      if (!/^11111111[1-8]$/.test(phoneNumber) || otp !== '123456') throw new Error('Usa únicamente los datos de prueba indicados.');
      const token = await provider.yape({phoneNumber, otp}).create();
      if (!token?.id || token.live_mode === true) throw new Error('No se pudo iniciar el pago de Yape. Revisa la configuración de prueba.');
      await sendPayment({token: token.id, payment_method_id: 'yape', installments: 1, payer: {email: el('mp-test-email').value}});
    });
  });
  el('mp-load-card')?.addEventListener('click', () => operate(async () => {
    if (brick) return;
    const provider = await sdk();
    message('Cargando el formulario de tarjetas de prueba…');
    brick = await provider.bricks().create('payment', 'mp-card-brick', {
      initialization: {amount: app.getState().total_centimos / 100, payer: {email: 'test_user_fontana@testuser.com'}},
      customization: {paymentMethods: {creditCard: 'all', debitCard: 'all', prepaidCard: 'all', maxInstallments: 12}},
      callbacks: {
        onReady: () => message('Formulario de pruebas listo. Usa tarjeta de prueba, titular APRO y correo @testuser.com.'),
        onSubmit: async ({formData}) => {
          try { await sendPayment(formData); }
          catch (error) { message(error.message || 'No se pudo completar la prueba.', true); throw error; }
        },
        onError: () => message('El formulario del proveedor reportó un error. Revisa los datos y la conexión; no se confirma un pago por este mensaje.', true)
      }
    });
  }));
  el('mp-refresh')?.addEventListener('click', () => operate(async () => {
    const response = await api('mp_consultar'); update(response.orden); message('Estado actualizado.');
  }));
  async function checkMpQuietly() {
    mpTimer = null;
    if (mpChecking || document.hidden || app.getState().estado !== 'en_proceso') {
      if (active()) mpTimer = setTimeout(checkMpQuietly, 10000); return;
    }
    mpChecking = true;
    try { const response = await api('mp_consultar'); update(response.orden); }
    catch (error) { message(error.message, true); mpTimer = setTimeout(checkMpQuietly, 15000); }
    finally {
      mpChecking = false;
      if (app.getState().estado === 'en_proceso' && mpTimer === null) mpTimer = setTimeout(checkMpQuietly, 10000);
    }
  }
  window.addEventListener('pagehide', () => { clearTimeout(mpTimer); if (brick && typeof brick.unmount === 'function') brick.unmount(); });
  renderState(app.getState());
  if (cfg.provider === 'demo' && el('demo-qr') && active()) operate(showQr);
})();
