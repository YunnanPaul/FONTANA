(() => {
  'use strict';
  const source = document.getElementById('payment-bootstrap');
  if (!source) return;
  const cfg = JSON.parse(source.textContent);
  const el = id => document.getElementById(id);
  const money = cents => 'S/ ' + (cents / 100).toLocaleString('es-PE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  let state = cfg.estado, busy = false, polling = false, timer = null, checkout = null;
  const sdkLoads = new Map();
  function message(text, error = false) {
    const box = el('payment-message'); box.textContent = text; box.className = 'pay-notice' + (error ? ' warning' : ''); box.hidden = !text;
  }
  async function api(action, data = {}) {
    const request = action ? {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'}, body: JSON.stringify({...data, accion: action, orden: cfg.orden, csrf_token: cfg.csrf_token})} : {headers: {'Accept': 'application/json'}};
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 40000);
    try {
      const r = await fetch('api_pago.php' + (action ? '' : '?orden=' + encodeURIComponent(cfg.orden)), {...request, credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
      let data;
      try { data = await r.json(); } catch { throw new Error('El servidor no devolvió un estado válido. No repitas el cobro; consulta la orden.'); }
      if (!r.ok || !data.ok) throw new Error(data.message || 'No se pudo consultar el pago.');
      return data;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('La conexión demoró demasiado. Esto NO significa que el pago falló. Consulta el estado antes de intentar otro cobro.');
      throw error;
    } finally { clearTimeout(timeout); }
  }
  function update(next) {
    state = next;
    el('payment-state').textContent = state.etiqueta;
    el('payment-state').className = 'pay-state ' + state.estado;
    el('payment-alert').textContent = state.alerta || ''; el('payment-alert').hidden = !state.alerta;
    const pending = state.estado === 'pendiente';
    const progress = state.estado === 'en_proceso';
    if (el('manual-payment-form')) el('manual-payment-form').hidden = !pending;
    if (el('gateway-payment-form')) el('gateway-payment-form').hidden = !(pending || progress);
    if (el('gateway-intro')) el('gateway-intro').hidden = !(pending || progress);
    el('payment-wait').hidden = !progress;
    el('cancel-order').hidden = ['pagado','prueba','cancelado','rechazado'].includes(state.estado) || state.pago_verificado;
    const result = ['pagado','prueba','cancelado','rechazado','vencido','revision'].includes(state.estado);
    el('payment-result').hidden = !result;
    if (result) {
      el('result-title').textContent = state.etiqueta;
      const details = {
        pagado: `Venta #${state.id_venta} registrada. Total: ${money(state.total_centimos)}.` + (state.vuelto_centimos !== null ? ` Vuelto: ${money(state.vuelto_centimos)}.` : ''),
        prueba: 'Prueba aprobada. No se registró una venta ni se modificó el inventario.',
        rechazado: 'Pago rechazado. No se registró una venta ni se modificó el inventario.',
        cancelado: 'Cancelación local registrada. No es una devolución ni una anulación bancaria automática.',
        vencido: 'Esta reserva ya no está activa. No cobres esta orden; prepara una nueva.',
        revision: state.pago_verificado ? 'Hay un pago verificado que requiere conciliación. No vuelvas a cobrar al cliente.' : 'El resultado requiere revisión en el panel del proveedor. No entregues el pedido ni vuelvas a cobrar sin comprobarlo.'
      };
      el('result-detail').textContent = details[state.estado] || '';
      el('result-reference').textContent = state.referencia ? 'Referencia: ' + state.referencia : '';
    }
    if (el('reconcile-order')) el('reconcile-order').hidden = !(state.estado === 'revision' && state.pago_verificado && !state.id_venta && ['production','demo_venta','sandbox_venta'].includes(state.entorno));
    if (el('test-receipt')) el('test-receipt').hidden = !['prueba','pagado'].includes(state.estado);
    document.dispatchEvent(new CustomEvent('fontana:payment-state', {detail: state}));
    clearTimeout(timer);
    if (pending || progress) timer = setTimeout(poll, progress ? 4500 : 12000);
  }
  async function poll() {
    if (polling) return;
    polling = true;
    try { const result = await api(null); update(result.orden); }
    catch (e) { message(e.message, true); clearTimeout(timer); timer = setTimeout(poll, 15000); }
    finally { polling = false; }
  }
  async function operate(fn) {
    if (busy) return;
    busy = true;
    document.querySelectorAll('.payment-workspace button').forEach(b => { b.disabled = true; });
    try { message(''); await fn(); }
    catch (e) { message(e.message || 'No se pudo realizar esta acción.', true); }
    finally { busy = false; document.querySelectorAll('.payment-workspace button').forEach(b => { b.disabled = false; }); }
  }
  function loadSdk(url) {
    // URLs devueltas por el servidor y restringidas también aquí. No cargar scripts arbitrarios.
    if (!['https://sandbox-checkout.izipay.pe/payments/v1/js/index.js', 'https://checkout.izipay.pe/payments/v1/js/index.js'].includes(url)) return Promise.reject(new Error('Origen de la pasarela no autorizado.'));
    if (!sdkLoads.has(url)) sdkLoads.set(url, new Promise((resolve, reject) => {
      const script = document.createElement('script'); script.src = url; script.async = true;
      script.onload = () => typeof window.Izipay === 'function' ? resolve() : reject(new Error('No se pudo abrir el formulario de pago. Revisa la conexión.'));
      script.onerror = () => { sdkLoads.delete(url); reject(new Error('No se pudo cargar Izipay. No se confirmó ningún pago; revisa el estado de la orden.')); };
      document.head.appendChild(script);
    }));
    return sdkLoads.get(url);
  }
  el('gateway-payment-form')?.addEventListener('submit', event => {
    event.preventDefault();
    operate(async () => {
      const billing = Object.fromEntries(new FormData(event.currentTarget).entries());
      message('Preparando la misma orden en Izipay…');
      const result = await api('preparar', {billing});
      await loadSdk(result.checkout.sdk);
      const spec = result.checkout;
      checkout = new window.Izipay({config: spec.config});
      checkout.LoadForm({authorization: spec.authorization, keyRSA: spec.keyRSA, callbackResponse: async response => {
        try {
          // Ni code=00 ni una pantalla de éxito bastan: el backend verifica la firma.
          if (!response || typeof response.payloadHttp !== 'string' || typeof response.signature !== 'string') {
            message('El formulario se cerró o no devolvió una autorización firmada. Comprobando el estado; no repitas el cobro.', true);
            await poll(); return;
          }
          const confirmation = await api('callback', {respuesta: response});
          message(''); update(confirmation.orden);
        } catch (e) { message(e.message, true); await poll(); }
      }});
      message('El total está incluido en la sesión. Esperando confirmación segura del pago.');
      await poll();
    });
  });
  el('manual-payment-form')?.addEventListener('submit', event => {
    event.preventDefault();
    const fields = Object.fromEntries(new FormData(event.currentTarget).entries());
    operate(async () => { const r = await api('confirmar_manual', fields); update(r.orden); });
  });
  el('recibido')?.addEventListener('input', event => {
    const value = event.target.value;
    const parts = /^(\d+)(?:\.(\d{0,2}))?$/.exec(value);
    const cents = parts ? Number(parts[1]) * 100 + Number((parts[2] || '').padEnd(2, '0')) : 0;
    el('cash-change').textContent = money(Math.max(0, cents - state.total_centimos));
  });
  el('refresh-status').addEventListener('click', () => operate(async () => { const r = await api(null); update(r.orden); message('Estado actualizado.'); }));
  el('cancel-order').addEventListener('click', () => {
    if (!window.confirm(state.academico ? '¿Cancelar esta prueba? No se moverá dinero real.' : '¿Cancelar esta orden? Esto NO anula ni devuelve un cobro del proveedor.')) return;
    operate(async () => { const r = await api('cancelar', {nota: el('admin-note')?.value || '', sin_cobro: !!el('admin-no-charge')?.checked}); update(r.orden); });
  });
  el('reconcile-order')?.addEventListener('click', () => operate(async () => {
    const r = await api('conciliar', {nota: el('admin-note')?.value || ''}); update(r.orden);
  }));
  document.addEventListener('visibilitychange', () => { if (!document.hidden && ['pendiente','en_proceso','revision'].includes(state.estado)) poll(); });
  window.FontanaPayments = {cfg, api, update, operate, message, poll, money, getState: () => state};
  update(state);
})();
