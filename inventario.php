<?php
session_start(); require 'conexion.php'; require_once 'includes/pagos.php'; requireLogin(); requireAdmin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) { setFlash('error','El formulario venció. Recarga la página.'); header('Location: inventario.php'); exit; }
    try { validarCamposTexto($_POST, ['id_producto', 'cantidad', 'tipo_movimiento', 'motivo']); }
    catch (InvalidArgumentException $e) { setFlash('error',$e->getMessage()); header('Location: inventario.php'); exit; }
    $tx=false;
    try {
        $id = (string)($_POST['id_producto'] ?? ''); $raw=(string)($_POST['cantidad'] ?? '');
        if (!preg_match('/^[1-9][0-9]{0,8}$/D',$id) || !preg_match('/^(0|[1-9][0-9]{0,7})$/D',$raw)) throw new InvalidArgumentException('Producto o cantidad entera no válidos.');
        $idProducto=(int)$id; $cantidad=(int)$raw; $tipoMovimiento=$_POST['tipo_movimiento'] ?? '';
        if (!in_array($tipoMovimiento,['entrada','salida','ajuste'],true) || ($tipoMovimiento!=='ajuste' && $cantidad===0)) throw new InvalidArgumentException('Movimiento o cantidad no válidos.');
        $motivo=pagoTexto($_POST['motivo'] ?? '',160,$tipoMovimiento==='ajuste');
        pagoBegin($conn); $tx=true;
        $producto=pagoUno($conn,'SELECT stock,nombre FROM productos WHERE id_producto=? FOR UPDATE',[$idProducto]);
        if (!$producto) throw new RuntimeException('El producto ya no existe.');
        $stockActual=(int)$producto['stock'];
        $nuevoStock=$tipoMovimiento==='ajuste' ? $cantidad : ($tipoMovimiento==='entrada' ? $stockActual+$cantidad : $stockActual-$cantidad);
        if ($nuevoStock<0 || $nuevoStock>2147483647) throw new RuntimeException('El movimiento deja un stock fuera de rango.');
        if ($nuevoStock<pagoReservado($conn,$idProducto)) throw new RuntimeException('Hay unidades reservadas para cobros pendientes. Resuelve esos pagos antes de reducir el stock.');
        pagoExec($conn,'UPDATE productos SET stock=? WHERE id_producto=?',[$nuevoStock,$idProducto]);
        pagoExec($conn,'INSERT INTO movimientos_inventario(id_producto,tipo_movimiento,cantidad,motivo,stock_anterior,stock_nuevo) VALUES(?,?,?,?,?,?)',[$idProducto,$tipoMovimiento,$cantidad,$motivo,$stockActual,$nuevoStock]);
        $conn->commit(); $tx=false; setFlash('success','Movimiento de inventario registrado correctamente.');
    } catch(Throwable $e) {
        if ($tx) $conn->rollback();
        setFlash('error',$e instanceof mysqli_sql_exception ? 'No se pudo registrar el movimiento de inventario.' : $e->getMessage());
    }
    header('Location: inventario.php'); exit;
}

$pageTitle = 'Control de inventario';
$activePage = 'inventario';
$productos = fetchAllAssoc($conn, 'SELECT id_producto, nombre, stock, unidad_medida FROM productos ORDER BY nombre ASC');
$movimientos = fetchAllAssoc($conn, "SELECT m.*, p.nombre 
    FROM movimientos_inventario m 
    INNER JOIN productos p ON p.id_producto = m.id_producto
    ORDER BY m.id_movimiento DESC LIMIT 15");

include 'includes/header.php';
?>

<div class="grid-two">
    <section class="form-panel">
        <h2>Registrar entrada, salida o ajuste</h2>
        <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
            <div class="form-grid">
                <div>
                    <label>Producto</label>
                    <select name="id_producto" required>
                        <option value="">Selecciona un producto</option>
                        <?php foreach ($productos as $producto): ?>
                            <option value="<?php echo h($producto['id_producto']); ?>"><?php echo h($producto['nombre'] . ' | stock: ' . $producto['stock'] . ' ' . $producto['unidad_medida']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Tipo de movimiento</label>
                    <select name="tipo_movimiento" id="tipo_movimiento" required>
                        <option value="entrada">Entrada</option>
                        <option value="salida">Salida</option>
                        <option value="ajuste">Ajuste</option>
                    </select>
                </div>
                <div>
                    <label>Cantidad</label>
                    <input type="number" min="1" max="99999999" step="1" name="cantidad" id="mov-cantidad" required>
                </div>
                <div>
                    <label>Motivo <span id="motivo-oblig" style="display:none;color:#b91c1c;">(obligatorio en ajustes)</span></label>
                    <input type="text" name="motivo" id="motivo" placeholder="Compra, merma, regularización, etc.">
                </div>
            </div>
            <div class="actions">
                <button type="submit">Guardar movimiento</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Guía rápida</h2>
        <div class="kpi-list">
            <div class="kpi-row"><strong>Entrada</strong><span class="muted">Suma unidades al stock</span></div>
            <div class="kpi-row"><strong>Salida</strong><span class="muted">Resta unidades por uso o pérdida</span></div>
            <div class="kpi-row"><strong>Ajuste</strong><span class="muted">Deja el stock en el valor escrito</span></div>
        </div>
    </section>
</div>

<section class="table-panel" style="margin-top:18px;">
    <h2>Últimos movimientos</h2>
    <?php if ($movimientos): ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Producto</th>
                    <th>Tipo</th>
                    <th>Cantidad</th>
                    <th>Motivo</th>
                    <th>Antes</th>
                    <th>Después</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movimientos as $mov): ?>
                    <tr>
                        <td><?php echo h($mov['id_movimiento']); ?></td>
                        <td><?php echo h($mov['nombre']); ?></td>
                        <td><?php echo h(ucfirst($mov['tipo_movimiento'])); ?></td>
                        <td><?php echo h($mov['cantidad']); ?></td>
                        <td><?php echo h($mov['motivo']); ?></td>
                        <td><?php echo h($mov['stock_anterior']); ?></td>
                        <td><?php echo h($mov['stock_nuevo']); ?></td>
                        <td><?php echo fmtFecha($mov['fecha']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">Todavía no existen movimientos de inventario.</div>
    <?php endif; ?>
</section>

<script>
(function () {
    const tipo = document.getElementById('tipo_movimiento');
    const motivo = document.getElementById('motivo');
    const aviso = document.getElementById('motivo-oblig');
    if (!tipo || !motivo) return;
    function aplicar() {
        const esAjuste = tipo.value === 'ajuste';
        motivo.required = esAjuste;
        document.getElementById('mov-cantidad').min = esAjuste ? '0' : '1';
        aviso.style.display = esAjuste ? 'inline' : 'none';
    }
    tipo.addEventListener('change', aplicar);
    aplicar();
})();
</script>
<?php include 'includes/footer.php'; ?>
