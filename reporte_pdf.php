<?php
session_start();
require 'conexion.php';
require 'includes/helpers.php';
requireLogin();
requireAdmin();

$schemaReady = ensureSalesDocumentSchema($conn);
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');

$stmt = $conn->prepare("SELECT COUNT(*) AS total_ventas, COALESCE(SUM(total),0) AS total_periodo FROM ventas WHERE DATE(fecha) BETWEEN ? AND ?");
$stmt->bind_param('ss', $desde, $hasta);
$stmt->execute();
$resumen = $stmt->get_result()->fetch_assoc();
$stmt->close();

$ventas = [];
if ($schemaReady) {
    $stmt = $conn->prepare("SELECT v.id_venta, v.fecha, v.metodo_pago, v.total,
        v.tipo_comprobante, v.tipo_documento, v.numero_documento, v.cliente_nombre,
        GROUP_CONCAT(CONCAT(p.nombre, ' x', dv.cantidad) ORDER BY p.nombre SEPARATOR ', ') AS detalle
        FROM ventas v
        INNER JOIN detalle_venta dv ON dv.id_venta = v.id_venta
        INNER JOIN productos p ON p.id_producto = dv.id_producto
        WHERE DATE(v.fecha) BETWEEN ? AND ?
        GROUP BY v.id_venta, v.fecha, v.metodo_pago, v.total, v.tipo_comprobante,
            v.tipo_documento, v.numero_documento, v.cliente_nombre
        ORDER BY v.id_venta DESC");
    $stmt->bind_param('ss', $desde, $hasta);
    $stmt->execute();
    $ventas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT p.nombre, SUM(dv.cantidad) AS total_vendido, SUM(dv.subtotal) AS ingreso
    FROM detalle_venta dv
    INNER JOIN ventas v ON v.id_venta = dv.id_venta
    INNER JOIN productos p ON p.id_producto = dv.id_producto
    WHERE DATE(v.fecha) BETWEEN ? AND ?
    GROUP BY p.id_producto, p.nombre
    ORDER BY total_vendido DESC LIMIT 10");
$stmt->bind_param('ss', $desde, $hasta);
$stmt->execute();
$topProductos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stockActual = fetchAllAssoc($conn, 'SELECT nombre, tipo, stock, stock_minimo, unidad_medida FROM productos ORDER BY tipo, nombre');

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Fontana <?php echo h($desde); ?> al <?php echo h($hasta); ?></title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 13px; color: #222; background: #fff; padding: 20px; }
        .hoja { width: 186mm; margin: 0 auto; }
        .header { text-align: center; border-bottom: 3px solid #6d28d9; padding-bottom: 14px; margin-bottom: 20px; }
        .header h1 { font-size: 20px; color: #6d28d9; }
        .header p { color: #666; font-size: 12px; margin-top: 4px; }
        .section { margin-bottom: 24px; }
        .section h2 { font-size: 14px; background: #6d28d9; color: #fff; padding: 8px 12px; border-radius: 6px; margin-bottom: 10px; }
        .kpi-row { display: flex; justify-content: space-between; padding: 8px 12px; border: 1px solid #e7def6; border-radius: 6px; margin-bottom: 6px; }
        .kpi-row strong { color: #4c1d95; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th { background: #f5efff; color: #4c1d95; padding: 8px; border: 1px solid #e7def6; text-align: left; }
        td { padding: 7px 8px; border: 1px solid #e7def6; }
        tr:nth-child(even) td { background: #faf8ff; }
        .badge-low { background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 99px; font-size: 11px; }
        .badge-ok  { background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 99px; font-size: 11px; }
        .muted { color: #666; font-size: 10px; }
        .footer { text-align: center; margin-top: 28px; padding-top: 10px; border-top: 1px solid #e7def6; color: #888; font-size: 11px; }
        .no-print { margin-bottom: 16px; }
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
            .hoja { width: auto; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="display:flex;gap:10px;">
        <button onclick="window.print()" style="background:#6d28d9;color:#fff;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:700;">🖨 Imprimir / Guardar PDF</button>
        <a href="reportes.php" style="background:#3d2c8d;color:#fff;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:700;text-decoration:none;">← Volver a reportes</a>
    </div>

    <div class="hoja">
    <div class="header">
        <h1>INDULAC FONTANA S.R.L.</h1>
        <p>Sistema de Gestión de Stock y Ventas</p>
        <p style="margin-top:6px;font-weight:700;">Reporte del <?php echo h($desde); ?> al <?php echo h($hasta); ?></p>
        <p>Generado el <?php echo date('d/m/Y H:i'); ?></p>
    </div>

    <div class="section">
        <h2>Resumen del período</h2>
        <div class="kpi-row"><strong>Total recaudado</strong><span><?php echo h(money($resumen['total_periodo'])); ?></span></div>
        <div class="kpi-row"><strong>Número de ventas</strong><span><?php echo h($resumen['total_ventas']); ?></span></div>
        <?php if ((int)$resumen['total_ventas'] > 0): ?>
            <div class="kpi-row"><strong>Ticket promedio</strong><span><?php echo h(money($resumen['total_periodo'] / $resumen['total_ventas'])); ?></span></div>
        <?php endif; ?>
    </div>

    <?php if ($topProductos): ?>
    <div class="section">
        <h2>Productos más vendidos</h2>
        <table>
            <thead><tr><th>Producto</th><th>Unidades vendidas</th><th>Ingreso total</th></tr></thead>
            <tbody>
                <?php foreach ($topProductos as $p): ?>
                    <tr>
                        <td><?php echo h($p['nombre']); ?></td>
                        <td><?php echo h($p['total_vendido']); ?></td>
                        <td><?php echo h(money($p['ingreso'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($ventas): ?>
    <div class="section">
        <h2>Detalle de ventas</h2>
        <table>
            <thead><tr><th>N°</th><th>Fecha</th><th>Comprobante</th><th>Cliente</th><th>Detalle</th><th>Método</th><th>Total</th></tr></thead>
            <tbody>
                <?php foreach ($ventas as $v): ?>
                    <tr>
                        <td>#<?php echo h($v['id_venta']); ?></td>
                        <td><?php echo fmtFecha($v['fecha']); ?></td>
                        <td>
                            <?php echo h(saleReceiptLabel($v['tipo_comprobante'])); ?><br>
                            <span class="muted"><?php echo h(saleDocumentLabel($v['tipo_documento'])); ?><?php echo $v['numero_documento'] ? ': ' . h($v['numero_documento']) : ''; ?></span>
                        </td>
                        <td><?php echo h($v['cliente_nombre']); ?></td>
                        <td><?php echo h($v['detalle']); ?></td>
                        <td><?php echo h($v['metodo_pago']); ?></td>
                        <td><?php echo h(money($v['total'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="section">
        <h2>Stock actual de productos e insumos</h2>
        <table>
            <thead><tr><th>Producto</th><th>Tipo</th><th>Stock actual</th><th>Stock mínimo</th><th>Unidad</th><th>Estado</th></tr></thead>
            <tbody>
                <?php foreach ($stockActual as $p): ?>
                    <tr>
                        <td><?php echo h($p['nombre']); ?></td>
                        <td><?php echo h(ucfirst($p['tipo'])); ?></td>
                        <td><?php echo h($p['stock']); ?></td>
                        <td><?php echo h($p['stock_minimo']); ?></td>
                        <td><?php echo h($p['unidad_medida']); ?></td>
                        <td>
                            <span class="<?php echo $p['stock'] <= $p['stock_minimo'] ? 'badge-low' : 'badge-ok'; ?>">
                                <?php echo $p['stock'] <= $p['stock_minimo'] ? 'Stock bajo' : 'Normal'; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="footer">
        Sistema de Gestión INDULAC FONTANA S.R.L. &mdash; Documento generado automáticamente
    </div>
    </div>
</body>
</html>
