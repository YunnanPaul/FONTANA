<?php
session_start();
require 'conexion.php';
require 'includes/helpers.php';
requireLogin();

$pageTitle = 'Dashboard';
$activePage = 'dashboard';

$totalProductos = scalar($conn, 'SELECT COUNT(*) FROM productos');
$totalInsumos = scalar($conn, "SELECT COUNT(*) FROM productos WHERE tipo = 'insumo'");
$stockBajo = scalar($conn, 'SELECT COUNT(*) FROM productos WHERE stock <= stock_minimo');
$ventasHoy = scalar($conn, 'SELECT COUNT(*) FROM ventas WHERE DATE(fecha) = CURDATE()');
$ingresosHoy = scalar($conn, 'SELECT COALESCE(SUM(total),0) FROM ventas WHERE DATE(fecha) = CURDATE()');
$unidadesStock = scalar($conn, 'SELECT COALESCE(SUM(stock),0) FROM productos');
$productosCriticos = fetchAllAssoc($conn, 'SELECT nombre, stock, stock_minimo, tipo FROM productos WHERE stock <= stock_minimo ORDER BY stock ASC, nombre ASC LIMIT 8');
$movimientosHoy = scalar($conn, "SELECT COUNT(*) FROM movimientos_inventario WHERE DATE(fecha) = CURDATE()");
$ventasMes = scalar($conn, "SELECT COALESCE(SUM(total),0) FROM ventas WHERE YEAR(fecha) = YEAR(CURDATE()) AND MONTH(fecha) = MONTH(CURDATE())");
$ultimasVentas = fetchAllAssoc($conn, "SELECT v.id_venta, v.fecha, v.total, v.metodo_pago,
    GROUP_CONCAT(CONCAT(p.nombre, ' x', dv.cantidad) ORDER BY p.nombre SEPARATOR ', ') AS detalle
    FROM ventas v
    INNER JOIN detalle_venta dv ON dv.id_venta = v.id_venta
    INNER JOIN productos p ON p.id_producto = dv.id_producto
    GROUP BY v.id_venta, v.fecha, v.total, v.metodo_pago
    ORDER BY v.id_venta DESC LIMIT 8");
$cajaAbierta = getTodayOpenCashBox($conn);

include 'includes/header.php';
?>

<div class="grid-cards">
    <div class="stat-card bg-green">
        <h3>Total de productos</h3>
        <div class="number"><?php echo h($totalProductos); ?></div>
        <p>Incluye productos e insumos registrados</p>
    </div>
    <div class="stat-card bg-blue">
        <h3>Ventas de hoy</h3>
        <div class="number"><?php echo h($ventasHoy); ?></div>
        <p>Ventas registradas en el día</p>
    </div>
    <div class="stat-card bg-orange">
        <h3>Ingresos de hoy</h3>
        <div class="number"><?php echo h(money($ingresosHoy)); ?></div>
        <p>Recaudación acumulada actual</p>
    </div>
    <div class="stat-card bg-gold">
        <h3>Ventas del mes</h3>
        <div class="number"><?php echo h(money($ventasMes)); ?></div>
        <p>Recaudación acumulada en el mes actual</p>
    </div>
    <div class="stat-card bg-red">
        <h3>Stock bajo</h3>
        <div class="number"><?php echo h($stockBajo); ?></div>
        <p>Productos por debajo del mínimo</p>
    </div>
    <div class="stat-card bg-indigo">
        <h3>Movimientos de hoy</h3>
        <div class="number"><?php echo h($movimientosHoy); ?></div>
        <p>Entradas, salidas y ajustes registrados hoy</p>
    </div>
    <div class="stat-card bg-dark">
        <h3>Unidades en stock</h3>
        <div class="number"><?php echo h($unidadesStock); ?></div>
        <p>Existencias totales actuales</p>
    </div>
    <div class="stat-card bg-teal">
        <h3>Caja del día</h3>
        <div class="number"><?php echo $cajaAbierta ? 'Abierta' : 'Pendiente'; ?></div>
        <p><?php echo $cajaAbierta ? 'Monto inicial: ' . h(money($cajaAbierta['monto_inicial'])) : 'Todavía no se realizó apertura'; ?></p>
    </div>
</div>

<div class="grid-two">
    <section class="panel">
        <h2>Resumen operativo</h2>
        <div class="kpi-list">
            <div class="kpi-row"><strong>Productos terminados</strong><span><?php echo h($totalProductos - $totalInsumos); ?></span></div>
            <div class="kpi-row"><strong>Insumos</strong><span><?php echo h($totalInsumos); ?></span></div>
            <div class="kpi-row"><strong>Estado de caja</strong><span class="badge <?php echo $cajaAbierta ? 'open' : 'closed'; ?>"><?php echo $cajaAbierta ? 'Abierta' : 'Sin apertura'; ?></span></div>
            <div class="kpi-row"><strong>Objetivo del proyecto</strong><span class="muted">Stock, ventas, caja, seguridad y reportes</span></div>
            <div class="kpi-row"><strong>Acceso al sistema</strong><span class="badge open">Protegido con login</span></div>
        </div>
    </section>

    <section class="panel">
        <h2>Accesos rápidos</h2>
        <div class="actions">
            <?php if (isAdmin()): ?>
            <a class="btn" href="productos.php">Registrar producto</a>
            <?php endif; ?>
            <a class="btn success" href="ventas.php">Registrar venta</a>
            <?php if (isAdmin()): ?>
            <a class="btn warning" href="inventario.php">Mover inventario</a>
            <?php endif; ?>
            <a class="btn secondary" href="caja.php">Abrir / cerrar caja</a>
        </div>
    </section>
</div>

<div class="grid-two" style="margin-top: 18px;">
    <section class="table-panel">
        <h2>Productos con alerta de stock</h2>
        <?php if ($productosCriticos): ?>
            <table>
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>Tipo</th>
                        <th>Stock actual</th>
                        <th>Stock mínimo</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($productosCriticos as $producto): ?>
                        <tr>
                            <td><?php echo h($producto['nombre']); ?></td>
                            <td><?php echo h(ucfirst($producto['tipo'])); ?></td>
                            <td><?php echo h($producto['stock']); ?></td>
                            <td><?php echo h($producto['stock_minimo']); ?></td>
                            <td>
                                <span class="badge low">Stock bajo</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">No hay productos con stock bajo en este momento.</div>
        <?php endif; ?>
    </section>

    <section class="table-panel">
        <h2>Últimas ventas</h2>
        <?php if ($ultimasVentas): ?>
            <table>
                <thead>
                    <tr>
                        <th>Venta</th>
                        <th>Detalle</th>
                        <th>Método</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ultimasVentas as $venta): ?>
                        <tr>
                            <td>#<?php echo h($venta['id_venta']); ?></td>
                            <td><?php echo h($venta['detalle']); ?></td>
                            <td><?php echo h($venta['metodo_pago']); ?></td>
                            <td><?php echo h(money($venta['total'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">Aún no existen ventas registradas.</div>
        <?php endif; ?>
    </section>
</div>

<?php include 'includes/footer.php'; ?>
