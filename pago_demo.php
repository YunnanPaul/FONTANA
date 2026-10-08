<?php
// Página pública sin datos de cliente: el token se lee del fragmento, nunca de la URL del servidor.
header('Cache-Control: no-store, private'); header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fontana · Pago</title><link rel="stylesheet" href="css/estilos.css"><link rel="stylesheet" href="css/pagos.css?v=cobro-20260906-1"><script src="js/demo-movil.js?v=cobro-20260906-1" defer></script></head>
<body class="demo-mobile-body"><main class="demo-mobile-card">
<div class="demo-brand">FONTANA</div>
<p class="payment-test-note"><strong>Modo de prueba · No se realizan cobros reales.</strong></p>
<span id="demo-mobile-state" class="pay-state">Consultando orden…</span>
<h1 id="demo-mobile-title">Tu pedido</h1>
<div class="pay-total"><span>Total de los productos</span><strong id="demo-mobile-total">—</strong><small>Soles peruanos</small></div>
<p id="demo-mobile-method"></p><p id="demo-mobile-expiry" class="muted"></p>
<div id="demo-mobile-message" class="pay-notice warning" role="status" aria-live="polite" hidden></div>
<div id="demo-mobile-actions" hidden>
<div id="demo-mobile-cash-box" hidden><label for="demo-mobile-cash">Monto recibido</label><input id="demo-mobile-cash" type="number" inputmode="decimal" step="0.01" min="0"></div>
<div class="demo-actions"><button type="button" data-mobile-result="aprobado">Aprobar prueba</button><button type="button" class="btn secondary" data-mobile-result="pendiente">Mantener pendiente</button><button type="button" class="btn danger" data-mobile-result="rechazado">Rechazar prueba</button></div>
</div>
<div id="demo-mobile-result" class="payment-result" hidden></div>
<button id="demo-mobile-refresh" type="button" class="btn secondary">Consultar estado</button>
<p class="pay-footnote">El cajero verá el resultado en su pantalla. Este documento no tiene validez tributaria ni acredita una transferencia.</p>
<noscript><p class="pay-notice warning">Activa JavaScript para continuar.</p></noscript>
</main></body></html>
