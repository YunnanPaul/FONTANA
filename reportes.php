<?php
session_start();
require 'conexion.php';
require 'includes/helpers.php';
requireLogin();
requireAdmin();

$schemaReady = ensureSalesDocumentSchema($conn);
$pageTitle = 'Reportes';
$activePage = 'reportes';
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');

$stmt = $conn->prepare("SELECT COUNT(*) AS total_ventas, COALESCE(SUM(total),0) AS total_periodo FROM ventas WHERE DATE(fecha) BETWEEN ? AND ?");
$stmt->bind_param('ss', $desde, $hasta);
$stmt->execute();
$resumen = $stmt->get_result()->fetch_assoc() ?: ['total_ventas' => 0, 'total_periodo' => 0];
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
    ORDER BY total_vendido DESC, ingreso DESC LIMIT 10");
$stmt->bind_param('ss', $desde, $hasta);
$stmt->execute();
$topProductos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalPeriodo = (float)($resumen['total_periodo'] ?? 0);
$cantidadVentas = (int)($resumen['total_ventas'] ?? 0);
include 'includes/header.php';
?>

<?php if (!$schemaReady): ?>
    <div class="alert error">No se pudo cargar la información de clientes y comprobantes. Verifica los permisos ALTER del usuario MySQL.</div>
<?php endif; ?>

<section class="form-panel">
    <h2>Filtrar reportes</h2>
    <form method="GET" class="filters">
        <div><label>Desde</label><input type="date" name="desde" value="<?php echo h($desde); ?>"></div>
        <div><label>Hasta</label><input type="date" name="hasta" value="<?php echo h($hasta); ?>"></div>
        <div style="flex:0 0 auto; min-width:150px;"><button type="submit">Generar</button></div>
        <div style="flex:0 0 auto; min-width:180px;"><a href="reporte_pdf.php?desde=<?php echo h($desde); ?>&hasta=<?php echo h($hasta); ?>" target="_blank" class="btn warning" style="width:100%;justify-content:center;">🖨 Exportar PDF</a></div>
    </form>
</section>

<div class="grid-cards" style="margin-top:18px;">
    <div class="stat-card bg-blue"><h3>Total vendido</h3><div class="number"><?php echo h(money($totalPeriodo)); ?></div><p>Ventas acumuladas del rango seleccionado</p></div>
    <div class="stat-card bg-green"><h3>Ventas realizadas</h3><div class="number"><?php echo h($cantidadVentas); ?></div><p>Operaciones únicas realizadas en el periodo</p></div>
</div>

<div class="grid-two">
    <section class="table-panel">
        <h2>Detalle de ventas</h2>
        <?php if ($ventas): ?>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Venta</th><th>Fecha</th><th>Comprobante</th><th>Cliente</th><th>Detalle</th><th>Método</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($ventas as $venta): ?>
                            <tr>
                                <td>#<?php echo h($venta['id_venta']); ?></td>
                                <td><?php echo fmtFecha($venta['fecha']); ?></td>
                                <td>
                                    <?php echo h(saleReceiptLabel($venta['tipo_comprobante'])); ?><br>
                                    <span class="muted"><?php echo h(saleDocumentLabel($venta['tipo_documento'])); ?><?php echo $venta['numero_documento'] ? ': ' . h($venta['numero_documento']) : ''; ?></span>
                                </td>
                                <td><?php echo h($venta['cliente_nombre']); ?></td>
                                <td><?php echo h($venta['detalle']); ?></td>
                                <td><?php echo h($venta['metodo_pago']); ?></td>
                                <td><?php echo h(money($venta['total'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No hay ventas en ese rango de fechas.</div>
        <?php endif; ?>
    </section>

    <section class="table-panel">
        <h2>Productos más vendidos</h2>
        <?php if ($topProductos): ?>
            <table>
                <thead><tr><th>Producto</th><th>Unidades</th><th>Ingreso</th></tr></thead>
                <tbody>
                    <?php foreach ($topProductos as $producto): ?>
                        <tr><td><?php echo h($producto['nombre']); ?></td><td><?php echo h($producto['total_vendido']); ?></td><td><?php echo h(money($producto['ingreso'])); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">Aún no hay datos suficientes para mostrar un ranking.</div>
        <?php endif; ?>
    </section>
</div>

<?php include 'includes/footer.php'; ?>
