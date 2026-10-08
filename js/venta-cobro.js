/* La ventana se renderiza desde PHP; el QR ya está visible sin pedir otro clic. */
(() => {
  'use strict';
  const modal = document.getElementById('cobro-overlay');
  if (!modal) return;
  const form = document.getElementById('confirmar-cobro-form');
  const back = document.getElementById('volver-pedido-form');
  const received = document.getElementById('cobro-recibido');
  const change = document.getElementById('cobro-vuelto');
  const total = Number(modal.dataset.total);
  let sending = false;
  const money = value => 'S/ ' + (value / 100).toLocaleString('es-PE', {minimumFractionDigits:2, maximumFractionDigits:2});
  function update() {
    if (!received || !change) return;
    const m = /^(\d{1,8})(?:\.(\d{1,2}))?$/.exec(received.value);
    const cents = m ? Number(m[1]) * 100 + Number((m[2] || '').padEnd(2,'0')) : 0;
    change.textContent = money(Math.max(0, cents - total));
  }
  received?.addEventListener('input', update);
  document.getElementById('cobro-exacto')?.addEventListener('click', () => { received.value = (total/100).toFixed(2); update(); });
  const qr = document.getElementById('cobro-qr');
  function qrFailed() { qr.hidden = true; document.getElementById('cobro-qr-missing').hidden = false; }
  if (qr) { qr.addEventListener('error',qrFailed); if (qr.complete && !qr.naturalWidth) qrFailed(); }
  [form,back].filter(Boolean).forEach(node => node.addEventListener('submit', event => {
    if (sending) { event.preventDefault(); return; }
    sending = true;
    modal.querySelectorAll('button').forEach(button => { button.disabled = true; });
    if (node === form) document.getElementById('confirmar-cobro').textContent = 'Registrando venta…';
  }));
  function reset() {
    sending = false;
    modal.querySelectorAll('button').forEach(button => { button.disabled = false; });
    if (form) document.getElementById('confirmar-cobro').textContent = 'Confirmar pago y registrar venta';
    update();
  }
  window.addEventListener('pageshow', reset);
  document.body.classList.add('cobro-abierto');
  modal.addEventListener('keydown', event => {
    if (event.key !== 'Tab') return;
    const nodes = [...modal.querySelectorAll('button:not(:disabled), input:not([type=hidden]):not(:disabled), a[href]')].filter(n => n.getClientRects().length);
    if (!nodes.length) return;
    const first = nodes[0], last = nodes[nodes.length-1];
    if (event.shiftKey && (document.activeElement === first || document.activeElement === modal.querySelector('.cobro-card'))) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
  (received || modal.querySelector('.cobro-card')).focus();
  update();
})();
