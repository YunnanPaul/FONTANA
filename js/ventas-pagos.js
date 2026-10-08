/* Carrito y un único botón de continuación. PHP prepara el cobro y valida el importe. */
(() => {
  'use strict';
  const form = document.getElementById('form-venta');
  const lines = document.getElementById('sale-lines');
  const total = document.getElementById('cart-total');
  if (!form || !lines || !total) return;
  const el = id => document.getElementById(id);
  const button = el('sale-submit');
  const money = cents => 'S/ ' + (cents / 100).toLocaleString('es-PE', {minimumFractionDigits:2, maximumFractionDigits:2});
  let currentTotal = 0, submitting = false;
  function calculate() {
    let sum = 0;
    lines.querySelectorAll('.sale-line').forEach(row => {
      const option = row.querySelector('select')?.selectedOptions[0];
      const text = row.querySelector('input[name="cantidad[]"]')?.value || '';
      const qty = /^[1-9]\d{0,4}$/.test(text) ? Number(text) : 0;
      const cents = Number(option?.dataset.centimos || 0) * qty;
      sum += cents;
      let label = row.querySelector('.line-subtotal');
      if (!label) { label=document.createElement('small'); label.className='line-subtotal'; row.querySelector('.sale-line-actions')?.prepend(label); }
      label.textContent = money(cents);
    });
    currentTotal = sum;
    total.textContent = money(sum);
  }
  try {
    const draft = JSON.parse(el('sale-draft')?.textContent || '{}');
    if (draft.tipo_comprobante) { el('tipo_comprobante').value = draft.tipo_comprobante; el('tipo_comprobante').dispatchEvent(new Event('change')); }
    ['metodo_pago','numero_documento','cliente_nombre','cliente_direccion'].forEach(name => {
      if (el(name) && typeof draft[name] === 'string') el(name).value = draft[name];
    });
    if (Array.isArray(draft.id_producto) && draft.id_producto.length) {
      const count = Math.min(100,draft.id_producto.length);
      for (let i=1; i<count; i++) el('add-line').click();
      lines.querySelectorAll('.sale-line').forEach((row,i) => {
        row.querySelector('select').value=draft.id_producto[i] || '';
        row.querySelector('input[name="cantidad[]"]').value=draft.cantidad?.[i] || '1';
      });
    }
  } catch { /* Un borrador inválido no bloquea una nueva venta. */ }
  lines.addEventListener('input',calculate);
  lines.addEventListener('change',calculate);
  form.addEventListener('click',()=>queueMicrotask(calculate));
  form.addEventListener('submit',event=>{
    if (submitting) { event.preventDefault(); return; }
    calculate();
    if (!Number.isSafeInteger(currentTotal) || currentTotal<=0 || currentTotal>9999999999) {
      event.preventDefault(); window.alert('Revisa los productos, cantidades y el total permitido.'); return;
    }
    submitting=true;
    button.disabled=true; button.textContent='Preparando cobro…';
  });
  window.addEventListener('pageshow',()=>{ submitting=false; button.disabled=false; button.textContent='Proceder con la venta'; calculate(); });
  calculate();
})();
