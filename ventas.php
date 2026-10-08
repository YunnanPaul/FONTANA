<?php
session_start();
require 'conexion.php';
require 'includes/helpers.php';
require 'includes/api_documentos.php';
require_once 'includes/ventas_core.php';
require_once 'includes/venta_cobro_core.php';
requireLogin();

$schemaReady = ensureSalesDocumentSchema($conn);
$apiConfigured = documentApiConfigured();
$csrfToken = csrfToken();
$cajaAbierta = getTodayOpenCashBox($conn);
$metodosPago = paymentMethods();
$pagosReady = pagoSchemaReady($conn);
$draft = is_array($_SESSION['venta_borrador'] ?? null) ? $_SESSION['venta_borrador'] : [];
$formKey = $_SERVER['REQUEST_METHOD'] === 'POST' && is_string($_POST['idempotencia'] ?? null)
    ? $_POST['idempotencia']
    : (isset($draft['idempotencia'], $_SESSION['pago_formularios'][$draft['idempotencia']]) ? $draft['idempotencia'] : pagoNuevaClave());
$pagosAcademicosReady = $pagosReady && pagoAcademicoSchemaReady($conn);
$configPagoVista = null; $errorConfigPago = '';
try { $configPagoVista = pagoConfig(); }
catch (Throwable $e) { $errorConfigPago = 'La configuración de cobros requiere revisión. Puedes continuar con la confirmación manual.'; }
$checkoutFlow = ($configPagoVista['checkout_flow'] ?? 'normal') === 'integrado' ? 'integrado' : 'normal';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('error', 'La sesión del formulario venció. Recarga la página e inténtalo nuevamente.');
        header('Location: ventas.php');
        exit;
    }

    if (!$schemaReady) {
        setFlash('error', 'No se pudo actualizar la base de datos para registrar los datos del comprobante. Revisa los permisos de MySQL.');
        header('Location: ventas.php');
        exit;
    }

    if (!$cajaAbierta) {
        setFlash('error', 'Debes abrir la caja del día antes de registrar ventas.');
        header('Location: ventas.php');
        exit;
    }

    foreach (['metodo_pago','referencia_pago','tipo_comprobante','numero_documento','cliente_nombre','cliente_direccion','idempotencia','flujo_pago','recibido','confirmado','etapa_venta'] as $field) {
        if (isset($_POST[$field]) && !is_string($_POST[$field])) {
            setFlash('error', 'El formulario contiene un campo no válido. Recarga Ventas.');
            header('Location: ventas.php'); exit;
        }
    }
    // La modalidad se configura en el servidor; no se le pide elegir al cajero.
    $flow = $checkoutFlow;
    $stage = $_POST['etapa_venta'] ?? 'preparar';
    if (!in_array($stage, ['preparar', 'confirmar', 'cancelar'], true)) {
        setFlash('error', 'La acción solicitada no es válida.');
        header('Location: ventas.php'); exit;
    }
    if ($stage === 'cancelar') {
        ventaVolverAlPedido($formKey, (int)currentUser()['id_usuario']);
        header('Location: ventas.php'); exit;
    }
    if ($stage === 'confirmar') {
        // Solo se usan los productos e importe preparados por el servidor.
        try {
            $result = ventaConfirmarPreparada($conn, $formKey, (int)currentUser()['id_usuario'], $_POST);
            $_SESSION['pago_confirmar'] = $result;
            unset($_SESSION['document_lookup'], $_SESSION['venta_borrador']);
            setFlash('success', 'Venta #' . $result['venta'] . ' registrada. Total: ' . money($result['total']));
            header('Location: ventas.php'); exit;
        } catch (Throwable $e) {
            $message = $e instanceof InvalidArgumentException || ($e instanceof RuntimeException && !($e instanceof mysqli_sql_exception))
                ? $e->getMessage() : 'No se pudo registrar la venta. Revisa el estado del sistema antes de repetirla.';
            if ($e instanceof mysqli_sql_exception) error_log('Fontana: confirmación de venta, error DB ' . $e->getCode());
            $pending = $_SESSION['ventas_por_cobrar'][$formKey] ?? null;
            if (is_array($pending) && (int)$pending['id_usuario'] === (int)currentUser()['id_usuario']) {
                $_SESSION['ventas_por_cobrar'][$formKey]['recibido'] = substr($_POST['recibido'] ?? '', 0, 30);
                $_SESSION['ventas_por_cobrar'][$formKey]['referencia_pago'] = substr($_POST['referencia_pago'] ?? '', 0, 50);
            }
            setFlash('error', $message);
            header('Location: ventas.php' . (is_array($pending) ? '?cobro=' . rawurlencode($formKey) : '')); exit;
        }
    }
    // Conservar el pedido cuando falla una validación, sin guardar contraseñas ni llaves.
    $draftFields = ['metodo_pago','referencia_pago','tipo_comprobante','numero_documento','cliente_nombre','cliente_direccion','idempotencia','recibido'];
    $_SESSION['venta_borrador'] = [];
    foreach ($draftFields as $field) $_SESSION['venta_borrador'][$field] = substr($_POST[$field] ?? '',0,1000);
    foreach (['id_producto','cantidad'] as $field) {
        $values = is_array($_POST[$field] ?? null) ? array_slice($_POST[$field],0,100) : [];
        $_SESSION['venta_borrador'][$field] = array_map(static fn($v) => is_scalar($v) ? substr((string)$v,0,15) : '', $values);
    }

    $productIds = $_POST['id_producto'] ?? [];
    $quantities = $_POST['cantidad'] ?? [];
    $metodoPago = trim($_POST['metodo_pago'] ?? 'Efectivo');
    $referenciaPago = trim((string)($_POST['referencia_pago'] ?? ''));
    // En el flujo habitual se conserva la referencia manual de la versión original.
    // La referencia solo aplica a pagos que no son en efectivo
    if ($metodoPago === 'Efectivo') {
        $referenciaPago = '';
    }
    $tipoComprobante = strtolower(trim($_POST['tipo_comprobante'] ?? 'boleta'));
    $rawNumeroDocumento = trim((string)($_POST['numero_documento'] ?? ''));
    $numeroDocumento = $rawNumeroDocumento;
    $clienteNombre = trim((string)($_POST['cliente_nombre'] ?? ''));
    $clienteDireccion = trim((string)($_POST['cliente_direccion'] ?? ''));

    if (!in_array($metodoPago, $metodosPago, true)) {
        setFlash('error', 'Selecciona un método de pago válido.');
        header('Location: ventas.php');
        exit;
    }

    if (!in_array($tipoComprobante, ['boleta', 'factura'], true)) {
        setFlash('error', 'Selecciona un tipo de comprobante válido.');
        header('Location: ventas.php');
        exit;
    }

    if ($rawNumeroDocumento !== '' && !ctype_digit($rawNumeroDocumento)) {
        setFlash('error', 'El número de documento solo puede contener dígitos.');
        header('Location: ventas.php');
        exit;
    }

    if ($tipoComprobante === 'factura') {
        $tipoDocumento = 'RUC';
        if (!isValidPeruvianRuc($numeroDocumento)) {
            setFlash('error', 'Para emitir una factura debes ingresar un RUC válido de 11 dígitos.');
            header('Location: ventas.php');
            exit;
        }
        if ($clienteNombre === '') {
            setFlash('error', 'Para una factura debes ingresar la razón social del cliente.');
            header('Location: ventas.php');
            exit;
        }
    } else {
        if ($numeroDocumento === '') {
            $tipoDocumento = 'SIN_DOCUMENTO';
            $clienteNombre = 'CONSUMIDOR FINAL';
            $clienteDireccion = '';
        } else {
            $tipoDocumento = 'DNI';
            if (!preg_match('/^\d{8}$/', $numeroDocumento)) {
                setFlash('error', 'El DNI debe tener exactamente 8 dígitos.');
                header('Location: ventas.php');
                exit;
            }
            if ($clienteNombre === '' || strtoupper($clienteNombre) === 'CONSUMIDOR FINAL') {
                setFlash('error', 'Consulta el DNI o ingresa el nombre completo del cliente.');
                header('Location: ventas.php');
                exit;
            }
        }
    }

    $clienteNombre = preg_replace('/\s+/', ' ', $clienteNombre) ?? $clienteNombre;
    $clienteDireccion = preg_replace('/\s+/', ' ', $clienteDireccion) ?? $clienteDireccion;

    if (preg_match_all('/./us', $clienteNombre) > 180 || preg_match_all('/./us', $clienteDireccion) > 255) {
        setFlash('error', 'Los datos del cliente superan el tamaño permitido.');
        header('Location: ventas.php');
        exit;
    }

    $documentoConsultado = 0;
    $apiProveedor = null;
    $lookup = $_SESSION['document_lookup'] ?? null;
    if (is_array($lookup)) {
        $lookupExpired = (int)($lookup['expires_at'] ?? 0) < time();
        $lookupMatches = !$lookupExpired
            && ($lookup['tipo_documento'] ?? '') === $tipoDocumento
            && ($lookup['numero_documento'] ?? '') === $numeroDocumento
            && trim((string)($lookup['nombre'] ?? '')) === $clienteNombre;

        if ($lookupMatches) {
            $documentoConsultado = 1;
            $apiProveedor = trim((string)($lookup['proveedor'] ?? 'Decolecta'));
        } elseif ($lookupExpired) {
            unset($_SESSION['document_lookup']);
        }
    }

    try {
        $lineas = pagoLineasValidas($productIds, $quantities);
        $clienteNombre = pagoTexto($clienteNombre, 180, true);
        $clienteDireccion = pagoTexto($clienteDireccion, 255);
        $receipt = [
            'tipo_comprobante' => $tipoComprobante, 'tipo_documento' => $tipoDocumento,
            'numero_documento' => $numeroDocumento, 'cliente_nombre' => $clienteNombre,
            'cliente_direccion' => $clienteDireccion, 'documento_consultado' => $documentoConsultado,
            'api_proveedor' => $apiProveedor,
        ];
        if ($flow === 'normal') {
            $pending = ventaPrepararCobro($conn, $lineas, $metodoPago, $receipt,
                (int)currentUser()['id_usuario'], (int)$cajaAbierta['id_cierre'], $formKey, $_SESSION['venta_borrador']);
            // PRG: recargar la ventana no repite escrituras ni vuelve a cobrar.
            header('Location: ventas.php?cobro=' . rawurlencode($formKey)); exit;
        }
        if (!$pagosReady || (in_array($configPagoVista['mode'] ?? '', ['demo','mercadopago_sandbox'], true) && !$pagosAcademicosReady)) {
            throw new RuntimeException('El cobro integrado no está disponible todavía. Solicita al administrador revisar la configuración de cobros.');
        }
        $orden = pagoCrearOrden($conn, $lineas, $metodoPago, $receipt,
            (int)currentUser()['id_usuario'], (int)$cajaAbierta['id_cierre'], $formKey);
        unset($_SESSION['document_lookup'], $_SESSION['venta_borrador']);
        header('Location: pago.php?orden=' . rawurlencode($orden['publico'])); exit;
    } catch (InvalidArgumentException|RuntimeException $e) {
        // Los errores de SQL no se muestran al usuario.
        $message = $e instanceof mysqli_sql_exception ? ($flow==='normal' ? 'No se pudo registrar la venta. Revisa la conexión y los permisos de las tablas originales.' : 'No se pudo preparar la orden. Revisa la instalación del módulo Pagos.') : $e->getMessage();
        if ($e instanceof mysqli_sql_exception) error_log('Fontana: error DB preparando orden; código ' . $e->getCode());
        setFlash('error', $message);
    } catch (Throwable $e) {
        error_log('Fontana: no se pudo preparar la orden; ' . get_class($e));
        setFlash('error', 'No se pudo preparar la orden. No se registró ninguna venta.');
    }
    $pending = $_SESSION['ventas_por_cobrar'][$formKey] ?? null;
    header('Location: ventas.php' . (is_array($pending) && (int)$pending['id_usuario'] === (int)currentUser()['id_usuario'] ? '?cobro=' . rawurlencode($formKey) : ''));
    exit;
}

$pageTitle = 'Registrar venta';
$activePage = 'ventas';
$productos = fetchAllAssoc($conn, "SELECT id_producto, nombre, precio, stock FROM productos WHERE tipo = 'producto' ORDER BY nombre ASC");

if ($schemaReady) {
    $ultimasVentas = fetchAllAssoc($conn, "SELECT v.id_venta, v.fecha, v.total, v.metodo_pago,
        v.tipo_comprobante, v.tipo_documento, v.numero_documento, v.cliente_nombre, v.documento_consultado,
        GROUP_CONCAT(CONCAT(p.nombre, ' x', dv.cantidad) ORDER BY p.nombre SEPARATOR ', ') AS detalle
        FROM ventas v
        INNER JOIN detalle_venta dv ON dv.id_venta = v.id_venta
        INNER JOIN productos p ON p.id_producto = dv.id_producto
        GROUP BY v.id_venta, v.fecha, v.total, v.metodo_pago, v.tipo_comprobante, v.tipo_documento,
            v.numero_documento, v.cliente_nombre, v.documento_consultado
        ORDER BY v.id_venta DESC LIMIT 10");
} else {
    $ultimasVentas = [];
}

include 'includes/header.php';
?>
<?php include 'includes/venta_resultado.php'; ?>
<?php include 'includes/venta_cobro.php'; ?>
<?php if ($errorConfigPago): ?><div class="pay-notice warning"><?php echo h($errorConfigPago); ?></div><?php endif; ?>
<?php if (!$schemaReady): ?>
    <div class="alert error">El registro de ventas no está disponible. Solicita al administrador revisar la instalación.</div>
<?php endif; ?>

<div class="grid-two sales-layout">
    <section class="form-panel">
        <h2>Nueva venta</h2>
        <?php if (!$cajaAbierta): ?>
            <div class="empty-state">Primero abre la caja diaria para poder registrar ventas.</div>
            <div class="actions" style="margin-top:12px;">
                <a class="btn secondary" href="caja.php">Ir a caja diaria</a>
            </div>
        <?php elseif ($schemaReady): ?>
            <form method="POST" id="form-venta" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                <input type="hidden" name="idempotencia" value="<?php echo h($formKey); ?>">
                <input type="hidden" name="etapa_venta" value="preparar">
                <?php if (!$apiConfigured): ?>
                    <div class="lookup-message">La búsqueda de documentos no está disponible. Puedes completar los datos del cliente manualmente.</div>
                <?php endif; ?>

                <div class="customer-card">
                    <div class="customer-card-heading">
                        <div>
                            <h3>Comprobante y cliente</h3>
                            <p>Ingresa el documento completo y los datos del cliente se cargarán automáticamente.</p>
                        </div>
                        <span class="lookup-badge" id="lookup-badge">Automático</span>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label for="tipo_comprobante">Tipo de comprobante</label>
                            <select name="tipo_comprobante" id="tipo_comprobante" required>
                                <option value="boleta">Boleta de venta</option>
                                <option value="factura">Factura</option>
                            </select>
                        </div>
                        <div>
                            <label>Método de pago</label>
                            <select name="metodo_pago" id="metodo_pago" required>
                                <?php foreach ($metodosPago as $metodo): ?>
                                    <option value="<?php echo h($metodo); ?>"><?php echo h($metodo); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="document-search-row automatic-document-lookup">
                        <div class="document-input-wrap">
                            <label for="numero_documento" id="document-label">DNI del cliente (opcional)</label>
                            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8" name="numero_documento" id="numero_documento" placeholder="8 dígitos" aria-describedby="lookup-message">
                        </div>
                    </div>

                    <div id="lookup-message" class="lookup-message" aria-live="polite"></div>

                    <div class="form-grid">
                        <div>
                            <label for="cliente_nombre" id="customer-name-label">Nombre completo</label>
                            <input type="text" maxlength="180" name="cliente_nombre" id="cliente_nombre" value="CONSUMIDOR FINAL" required>
                        </div>
                        <div>
                            <label for="cliente_direccion">Dirección <span class="muted">(opcional)</span></label>
                            <input type="text" maxlength="255" name="cliente_direccion" id="cliente_direccion" placeholder="Se completa automáticamente para RUC">
                        </div>
                    </div>
                </div>

                <div id="sale-lines" class="sale-lines">
                    <div class="sale-line">
                        <div>
                            <label>Producto</label>
                            <select name="id_producto[]" required>
                                <option value="">Selecciona un producto</option>
                                <?php foreach ($productos as $producto): ?>
                                    <option data-centimos="<?php echo pagoCentimos((string)$producto['precio']); ?>" value="<?php echo h($producto['id_producto']); ?>"><?php echo h($producto['nombre'] . ' | ' . money($producto['precio']) . ' | stock: ' . $producto['stock']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Cantidad</label>
                            <input type="number" min="1" max="99999" step="1" name="cantidad[]" value="1" required>
                        </div>
                        <div class="sale-line-actions">
                            <button type="button" class="btn danger btn-small remove-line">Quitar</button>
                        </div>
                    </div>
                </div>

                <template id="sale-line-template">
                    <div class="sale-line">
                        <div>
                            <label>Producto</label>
                            <select name="id_producto[]" required>
                                <option value="">Selecciona un producto</option>
                                <?php foreach ($productos as $producto): ?>
                                    <option data-centimos="<?php echo pagoCentimos((string)$producto['precio']); ?>" value="<?php echo h($producto['id_producto']); ?>"><?php echo h($producto['nombre'] . ' | ' . money($producto['precio']) . ' | stock: ' . $producto['stock']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Cantidad</label>
                            <input type="number" min="1" max="99999" step="1" name="cantidad[]" value="1" required>
                        </div>
                        <div class="sale-line-actions">
                            <button type="button" class="btn danger btn-small remove-line">Quitar</button>
                        </div>
                    </div>
                </template>

                <div class="cart-total" aria-live="polite"><span>Total del pedido</span><strong id="cart-total">S/ 0.00</strong></div>
                <div class="actions">
                    <button type="button" class="btn secondary" id="add-line">Agregar otro producto</button>
                    <button type="submit" class="btn success" id="sale-submit">Proceder con la venta</button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Notas de uso</h2>
        <div class="kpi-list">
            <div class="kpi-row"><strong>Boleta</strong><span class="muted">El DNI es opcional; sin DNI se registra como consumidor final</span></div>
            <div class="kpi-row"><strong>Factura</strong><span class="muted">Requiere RUC válido y razón social</span></div>
            <div class="kpi-row"><strong>Datos del cliente</strong><span class="muted">Al completar el DNI o RUC, el nombre y la dirección se consultan automáticamente</span></div>
            <div class="kpi-row"><strong>Pago</strong><span class="muted">Al continuar se abre automáticamente la pantalla del método elegido</span></div>
        </div>
        <div class="compliance-note">El registro de venta no sustituye un comprobante electrónico emitido ante SUNAT.</div>
    </section>
</div>

<section class="table-panel" style="margin-top:18px;">
    <h2>Últimas ventas registradas</h2>
    <?php if ($ultimasVentas): ?>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Venta</th>
                        <th>Comprobante</th>
                        <th>Cliente</th>
                        <th>Detalle</th>
                        <th>Método</th>
                        <th>Total</th>
                        <th>Fecha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ultimasVentas as $venta): ?>
                        <tr>
                            <td>#<?php echo h($venta['id_venta']); ?></td>
                            <td>
                                <strong><?php echo h(saleReceiptLabel($venta['tipo_comprobante'])); ?></strong><br>
                                <span class="muted">
                                    <?php echo h(saleDocumentLabel($venta['tipo_documento'])); ?>
                                    <?php echo $venta['numero_documento'] ? ': ' . h($venta['numero_documento']) : ''; ?>
                                </span>
                            </td>
                            <td>
                                <?php echo h($venta['cliente_nombre']); ?>
                                <?php if ((int)$venta['documento_consultado'] === 1): ?>
                                    <span class="badge ok">Verificado</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo h($venta['detalle']); ?></td>
                            <td><?php echo h($venta['metodo_pago']); ?></td>
                            <td><?php echo h(money($venta['total'])); ?></td>
                            <td><?php echo fmtFecha($venta['fecha']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state">No hay ventas registradas todavía.</div>
    <?php endif; ?>
</section>

<?php if ($cajaAbierta && $schemaReady): ?>
<script>
(function () {
    'use strict';

    const form = document.getElementById('form-venta');
    const addButton = document.getElementById('add-line');
    const linesContainer = document.getElementById('sale-lines');
    const template = document.getElementById('sale-line-template');
    const receiptType = document.getElementById('tipo_comprobante');
    const documentInput = document.getElementById('numero_documento');
    const documentLabel = document.getElementById('document-label');
    const customerNameLabel = document.getElementById('customer-name-label');
    const customerName = document.getElementById('cliente_nombre');
    const customerAddress = document.getElementById('cliente_direccion');
    const lookupMessage = document.getElementById('lookup-message');
    const lookupBadge = document.getElementById('lookup-badge');
    const lookupEnabled = <?php echo $apiConfigured ? 'true' : 'false'; ?>;
    const csrfToken = <?php echo json_encode($csrfToken, JSON_UNESCAPED_SLASHES); ?>;
    const automaticLookupDelay = 420;

    let lookupSequence = 0;
    let lookupTimer = null;
    let lookupController = null;
    let activeLookupKey = '';
    let successfulLookupKey = '';
    let continueAfterLookup = false;

    function bindRemoveButtons(scope) {
        scope.querySelectorAll('.remove-line').forEach((button) => {
            button.onclick = function () {
                const rows = linesContainer.querySelectorAll('.sale-line');
                if (rows.length === 1) {
                    const select = rows[0].querySelector('select');
                    const input = rows[0].querySelector('input');
                    if (select) select.value = '';
                    if (input) input.value = '';
                    return;
                }
                this.closest('.sale-line').remove();
            };
        });
    }

    function currentDocumentType() {
        return receiptType.value === 'factura' ? 'RUC' : 'DNI';
    }

    function expectedDocumentLength() {
        return currentDocumentType() === 'RUC' ? 11 : 8;
    }

    function currentLookupKey() {
        return currentDocumentType() + ':' + documentInput.value.trim();
    }

    function resetCustomerFields() {
        customerName.value = receiptType.value === 'factura' ? '' : 'CONSUMIDOR FINAL';
        customerAddress.value = '';
    }

    function setLookupState(type, message) {
        lookupMessage.className = 'lookup-message' + (type ? ' ' + type : '');
        lookupMessage.textContent = message || '';
        lookupBadge.className = 'lookup-badge' + (
            type === 'success' ? ' success' :
            type === 'error' ? ' error' :
            type === 'loading' ? ' loading' : ''
        );
        lookupBadge.textContent = type === 'success' ? 'Verificado' :
            type === 'error' ? 'Revisar' :
            type === 'loading' ? 'Consultando…' :
            lookupEnabled ? 'Automático' : 'Manual';
        documentInput.setAttribute('aria-busy', type === 'loading' ? 'true' : 'false');
    }

    function cancelPendingLookup() {
        if (lookupTimer !== null) {
            window.clearTimeout(lookupTimer);
            lookupTimer = null;
        }
        if (lookupController !== null) {
            lookupController.abort();
            lookupController = null;
        }
        activeLookupKey = '';
        lookupSequence++;
    }

    function updateReceiptFields() {
        cancelPendingLookup();
        continueAfterLookup = false;
        successfulLookupKey = '';

        const isInvoice = receiptType.value === 'factura';
        documentLabel.textContent = isInvoice ? 'RUC del cliente (obligatorio)' : 'DNI del cliente (opcional)';
        customerNameLabel.textContent = isInvoice ? 'Razón social' : 'Nombre completo';
        documentInput.maxLength = isInvoice ? 11 : 8;
        documentInput.placeholder = isInvoice ? '11 dígitos' : '8 dígitos';
        documentInput.required = isInvoice;
        documentInput.value = '';
        resetCustomerFields();
        setLookupState('', lookupEnabled
            ? 'La consulta se iniciará al completar ' + (isInvoice ? '11' : '8') + ' dígitos.'
            : 'Completa los datos del cliente manualmente.');
    }

    async function lookupDocument() {
        if (!lookupEnabled) return false;

        const type = currentDocumentType();
        const number = documentInput.value.trim();
        const expectedLength = expectedDocumentLength();
        const key = type + ':' + number;

        if (!new RegExp('^\\d{' + expectedLength + '}$').test(number)) {
            setLookupState('', number === ''
                ? 'La consulta se iniciará al completar ' + expectedLength + ' dígitos.'
                : 'Faltan ' + Math.max(0, expectedLength - number.length) + ' dígitos.');
            return false;
        }

        if (key === successfulLookupKey) return true;
        if (key === activeLookupKey) return false;

        if (lookupTimer !== null) {
            window.clearTimeout(lookupTimer);
            lookupTimer = null;
        }
        if (lookupController !== null) lookupController.abort();

        const sequence = ++lookupSequence;
        lookupController = typeof AbortController === 'function' ? new AbortController() : null;
        activeLookupKey = key;
        setLookupState('loading', 'Consultando automáticamente los datos del ' + type + '…');

        try {
            const formData = new URLSearchParams();
            formData.set('tipo', type);
            formData.set('numero', number);
            formData.set('csrf_token', csrfToken);

            const options = {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: formData.toString()
            };
            if (lookupController) options.signal = lookupController.signal;

            const response = await fetch('api_consulta_documento.php', options);
            let payload;
            try {
                payload = await response.json();
            } catch (_) {
                throw new Error('La consulta devolvió una respuesta no válida. Inténtalo nuevamente.');
            }

            if (sequence !== lookupSequence || currentLookupKey() !== key) return false;
            if (!response.ok || !payload || !payload.ok || !payload.data) {
                throw new Error((payload && payload.message) || 'No se pudo consultar el documento. Completa los datos manualmente.');
            }

            customerName.value = String(payload.data.nombre || '').trim();
            customerAddress.value = String(payload.data.direccion || '').trim();
            if (!customerName.value) {
                throw new Error('No se encontró un nombre válido para el documento ingresado.');
            }

            successfulLookupKey = key;
            let extra = '';
            if (payload.data.estado || payload.data.condicion) {
                extra = ' Estado: ' + [payload.data.estado, payload.data.condicion].filter(Boolean).join(' / ') + '.';
            }
            setLookupState('success', 'Datos del cliente completados automáticamente.' + extra);

            if (continueAfterLookup) {
                continueAfterLookup = false;
                window.queueMicrotask(() => {
                    if (form && typeof form.requestSubmit === 'function') form.requestSubmit();
                });
            }
            return true;
        } catch (error) {
            if (error && error.name === 'AbortError') return false;
            if (sequence !== lookupSequence || currentLookupKey() !== key) return false;
            successfulLookupKey = '';
            continueAfterLookup = false;
            setLookupState('error', error && error.message
                ? error.message
                : 'No se pudo consultar el documento. Puedes completar los datos manualmente.');
            return false;
        } finally {
            if (sequence === lookupSequence) {
                activeLookupKey = '';
                lookupController = null;
            }
        }
    }

    function scheduleAutomaticLookup(immediate) {
        if (lookupTimer !== null) {
            window.clearTimeout(lookupTimer);
            lookupTimer = null;
        }
        if (!lookupEnabled) return;

        const number = documentInput.value.trim();
        const expectedLength = expectedDocumentLength();
        const key = currentDocumentType() + ':' + number;

        if (number.length !== expectedLength) {
            if (number === '') {
                setLookupState('', 'La consulta se iniciará al completar ' + expectedLength + ' dígitos.');
            } else {
                setLookupState('', 'Faltan ' + Math.max(0, expectedLength - number.length) + ' dígitos.');
            }
            return;
        }
        if (key === successfulLookupKey || key === activeLookupKey) return;

        setLookupState('loading', 'Preparando consulta automática…');
        lookupTimer = window.setTimeout(() => {
            lookupTimer = null;
            lookupDocument();
        }, immediate ? 0 : automaticLookupDelay);
    }

    function handleDocumentInput() {
        const sanitized = documentInput.value.replace(/\D/g, '').slice(0, expectedDocumentLength());
        if (documentInput.value !== sanitized) documentInput.value = sanitized;

        const key = currentLookupKey();
        if (successfulLookupKey && key !== successfulLookupKey) {
            successfulLookupKey = '';
            resetCustomerFields();
        }

        cancelPendingLookup();
        continueAfterLookup = false;

        if (receiptType.value === 'boleta' && documentInput.value === '') {
            resetCustomerFields();
        }
        scheduleAutomaticLookup(false);
    }

    addButton.addEventListener('click', function () {
        const clone = template.content.cloneNode(true);
        linesContainer.appendChild(clone);
        bindRemoveButtons(linesContainer);
    });

    receiptType.addEventListener('change', updateReceiptFields);
    documentInput.addEventListener('paste', function (event) {
        const pasted = event.clipboardData ? event.clipboardData.getData('text') : '';
        if (!pasted) return;
        event.preventDefault();
        documentInput.value = pasted.replace(/\D/g, '').slice(0, expectedDocumentLength());
        handleDocumentInput();
    });
    documentInput.addEventListener('input', handleDocumentInput);
    documentInput.addEventListener('blur', function () {
        if (documentInput.value.length === expectedDocumentLength()) scheduleAutomaticLookup(true);
    });
    documentInput.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        if (documentInput.value.length === expectedDocumentLength()) {
            scheduleAutomaticLookup(true);
        } else {
            setLookupState('', 'Completa los ' + expectedDocumentLength() + ' dígitos para consultar.');
        }
    });

    customerName.addEventListener('input', function () {
        cancelPendingLookup();
        continueAfterLookup = false;
        successfulLookupKey = '';
        setLookupState('', 'Nombre editado manualmente; verifica los datos antes de continuar.');
    });

    if (form) {
        form.addEventListener('submit', function (event) {
            const exactLength = documentInput.value.length === expectedDocumentLength();
            const waiting = lookupTimer !== null || activeLookupKey !== '';
            if (!lookupEnabled || !exactLength || !waiting) return;

            event.preventDefault();
            event.stopImmediatePropagation();
            continueAfterLookup = true;
            scheduleAutomaticLookup(true);
            setLookupState('loading', 'Terminando la consulta antes de continuar…');
        }, true);
    }

    bindRemoveButtons(document);
    updateReceiptFields();

    document.addEventListener('DOMContentLoaded', function () {
        if (documentInput.value.length === expectedDocumentLength()) {
            scheduleAutomaticLookup(true);
        }
    }, { once: true });
})();

</script>
<?php endif; ?>

<script type="application/json" id="sale-draft"><?php echo json_encode($draft, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE); ?></script>
<script src="js/ventas-pagos.js?v=cobro-20260906-1" defer></script>
<?php include 'includes/footer.php'; ?>
