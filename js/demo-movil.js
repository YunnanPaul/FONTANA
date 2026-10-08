(() => {
  'use strict';
  const el = id => document.getElementById(id);
  const money = cents => 'S/ ' + (cents / 100).toLocaleString('es-PE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  let token = new URLSearchParams(location.hash.slice(1)).get('t') || '', busy = false, timer = null;
  // Keep the token out of referrers/server logs and permit a reload in this tab.
  try {
    if (token) { sessionStorage.setItem('fontana_demo_token', token); history.replaceState(null, '', location.pathname); }
    else token = sessionStorage.getItem('fontana_demo_token') || '';
  } catch { /* Fragment continues to work when storage is blocked. */ }
  function message(text) { el('demo-mobile-message').textContent = text; el('demo-mobile-message').hidden = !text; }
  function render(s) {
    const active = ['pendiente', 'en_proceso'].includes(s.estado);
    el('demo-mobile-state').textContent = s.etiqueta;
    el('demo-mobile-state').className = 'pay-state ' + s.estado;
    el('demo-mobile-title').textContent = 'Pedido #' + s.id_orden;
    el('demo-mobile-total').textContent = money(s.total_centimos);
    el('demo-mobile-method').textContent = 'Método: ' + s.metodo;
    el('demo-mobile-expiry').textContent = active ? 'Enlace válido por aproximadamente ' + Math.ceil(s.vence_en / 60) + ' minuto(s).' : 'El pedido ya fue procesado.';
    el('demo-mobile-actions').hidden = !active;
    el('demo-mobile-cash-box').hidden = s.metodo !== 'Efectivo';
    if (s.metodo === 'Efectivo' && !el('demo-mobile-cash').value) el('demo-mobile-cash').value = (s.total_centimos / 100).toFixed(2);
    const result = el('demo-mobile-result'); result.hidden = active;
    result.textContent = ['prueba','pagado'].includes(s.estado)
      ? 'Prueba aprobada por ' + money(s.total_centimos) + '. Referencia ' + (s.referencia || '') + '. No hubo movimiento de dinero real.'
      : s.etiqueta + '. No se realizó un cobro bancario.';
    clearTimeout(timer); if (active) timer = setTimeout(() => request('consultar'), 5000);
  }
  async function request(action, result = '') {
    if (busy) return;
    if (!/^[a-f0-9]{64}$/.test(token)) { message('Abre esta página desde el QR de una orden. No se recibió un enlace de prueba válido.'); return; }
    busy = true; document.querySelectorAll('button').forEach(b => { b.disabled = true; });
    const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch('api_pago_demo.php', {method: 'POST', headers: {'Content-Type':'application/json'}, credentials: 'omit', cache: 'no-store', signal: controller.signal,
        body: JSON.stringify({token, accion: action, resultado: result, recibido: el('demo-mobile-cash').value})});
      let data; try { data = await response.json(); } catch { throw new Error('El servidor no devolvió un resultado válido. Verifica PHP y la conexión con la base de datos.'); }
      if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo completar la simulación.');
      message(''); render(data.orden);
    } catch (error) {
      message(error.name === 'AbortError' ? 'La conexión demoró demasiado. Consulta el estado antes de repetir.' : error.message);
      clearTimeout(timer); timer = setTimeout(() => request('consultar'), 15000);
    } finally { clearTimeout(timeout); busy = false; document.querySelectorAll('button').forEach(b => { b.disabled = false; }); }
  }
  document.querySelectorAll('[data-mobile-result]').forEach(b => b.addEventListener('click', () => request('resolver', b.dataset.mobileResult)));
  el('demo-mobile-refresh').addEventListener('click', () => request('consultar'));
  window.addEventListener('pagehide', () => clearTimeout(timer));
  request('consultar');
})();
