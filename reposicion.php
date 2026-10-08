<?php
session_start();
require 'conexion.php';
require_once 'includes/pagos.php';
requireLogin();
requireAdmin();

$usuario = currentUser();
$esAdmin = $usuario['rol'] === 'administrador';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) { setFlash('error','La sesión del formulario venció. Recarga la página.'); header('Location: reposicion.php'); exit; }
    try { validarCamposTexto($_POST, ['accion', 'id_producto', 'cantidad_solicitada', 'observacion', 'id_solicitud', 'nota_admin']); }
    catch (InvalidArgumentException $e) { setFlash('error',$e->getMessage()); header('Location: reposicion.php'); exit; }
    $accion = $_POST['accion'] ?? '';

    // Crear solicitud de reposición
    if ($accion === 'solicitar') {
        if (!preg_match('/^[1-9][0-9]{0,8}$/D',(string)($_POST['id_producto'] ?? '')) || !preg_match('/^[1-9][0-9]{0,7}$/D',(string)($_POST['cantidad_solicitada'] ?? ''))) {
            setFlash('error','Selecciona un producto y una cantidad entera válida.'); header('Location: reposicion.php'); exit;
        }
        $idProducto   = (int)($_POST['id_producto'] ?? 0);
        $cantidadSol  = (int)($_POST['cantidad_solicitada'] ?? 0);
        $observacion  = trim($_POST['observacion'] ?? '');

        if ($idProducto <= 0 || $cantidadSol <= 0) {
            setFlash('error', 'Selecciona un producto y una cantidad válida.');
            header('Location: reposicion.php');
            exit;
        }

        try {
            if (strlen($observacion)>60000) throw new InvalidArgumentException('La observación es demasiado larga.');
            if (!pagoUno($conn,'SELECT id_producto FROM productos WHERE id_producto=?',[$idProducto])) throw new InvalidArgumentException('El producto ya no existe.');
            pagoExec($conn,'INSERT INTO solicitudes_reposicion (id_producto,cantidad_solicitada,observacion,id_usuario_solicita) VALUES (?,?,?,?)',
                [$idProducto,$cantidadSol,$observacion,(int)$usuario['id_usuario']]);
            setFlash('success','Solicitud de reposición enviada correctamente.');
        } catch (Throwable $e) {
            setFlash('error',$e instanceof mysqli_sql_exception ? 'No se pudo guardar la solicitud. El producto o usuario pudo cambiar.' : $e->getMessage());
        }
        header('Location: reposicion.php');
        exit;
    }

    // Aprobar/rechazar una sola vez, incluso ante solicitudes concurrentes.
    if (in_array($accion, ['aprobar','rechazar'], true) && $esAdmin) {
        $tx=false;
        try {
            $idSolicitud=(int)($_POST['id_solicitud'] ?? 0);
            $notaAdmin=pagoTexto($_POST['nota_admin'] ?? '',255);
            pagoBegin($conn); $tx=true;
            $solicitud=pagoUno($conn,"SELECT * FROM solicitudes_reposicion WHERE id_solicitud=? FOR UPDATE",[$idSolicitud]);
            if (!$solicitud || $solicitud['estado']!=='pendiente') throw new RuntimeException('Solicitud no encontrada o ya procesada.');
            if ($accion==='aprobar') {
                $prod=pagoUno($conn,'SELECT stock FROM productos WHERE id_producto=? FOR UPDATE',[(int)$solicitud['id_producto']]);
                if (!$prod) throw new RuntimeException('El producto ya no existe.');
                $old=(int)$prod['stock']; $qty=(int)$solicitud['cantidad_solicitada']; $new=$old+$qty;
                if ($qty<=0 || $new>2147483647) throw new RuntimeException('Cantidad de reposición fuera de rango.');
                pagoExec($conn,'UPDATE productos SET stock=? WHERE id_producto=?',[$new,(int)$solicitud['id_producto']]);
                pagoExec($conn,'INSERT INTO movimientos_inventario(id_producto,tipo_movimiento,cantidad,motivo,stock_anterior,stock_nuevo) VALUES(?,?,?,?,?,?)',[(int)$solicitud['id_producto'],'entrada',$qty,'Reposición aprobada #'.$idSolicitud,$old,$new]);
            }
            pagoExec($conn,'UPDATE solicitudes_reposicion SET estado=?,nota_admin=?,id_usuario_aprueba=?,fecha_respuesta=NOW() WHERE id_solicitud=?',[$accion==='aprobar' ? 'aprobada' : 'rechazada',$notaAdmin,(int)$usuario['id_usuario'],$idSolicitud]);
            $conn->commit(); $tx=false;
            setFlash('success',$accion==='aprobar' ? 'Reposición aprobada y stock actualizado.' : 'Solicitud rechazada.');
        } catch(Throwable $e) {
            if ($tx) $conn->rollback();
            setFlash('error',$e instanceof mysqli_sql_exception ? 'No se pudo procesar la reposición.' : $e->getMessage());
        }
        header('Location: reposicion.php'); exit;
    }
}

$pageTitle  = 'Reposición de stock';
$activePage = 'reposicion';

$productosStockBajo = fetchAllAssoc($conn, 'SELECT id_producto, nombre, stock, stock_minimo, unidad_medida, tipo FROM productos WHERE stock <= stock_minimo ORDER BY stock ASC');
$todosProductos     = fetchAllAssoc($conn, 'SELECT id_producto, nombre, stock, unidad_medida FROM productos ORDER BY nombre ASC');
$solicitudes        = fetchAllAssoc($conn, "SELECT r.*, p.nombre AS producto, us.nombre AS solicitante, ua.nombre AS aprobador
    FROM solicitudes_reposicion r
    INNER JOIN productos p ON p.id_producto = r.id_producto
    INNER JOIN usuarios us ON us.id_usuario = r.id_usuario_solicita
    LEFT JOIN usuarios ua ON ua.id_usuario = r.id_usuario_aprueba
    ORDER BY r.id_solicitud DESC LIMIT 30");

include 'includes/header.php';
?>

<?php if ($productosStockBajo): ?>
<div class="alert-stock-banner">
    <strong>⚠ Alerta:</strong> Hay <?php echo count($productosStockBajo); ?> producto(s) por debajo del stock mínimo.
    <?php foreach ($productosStockBajo as $p): ?>
        <span class="badge low"><?php echo h($p['nombre']); ?> (<?php echo h($p['stock']); ?>/<?php echo h($p['stock_minimo']); ?>)</span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid-two">
    <section class="form-panel">
        <h2>Nueva solicitud de reposición</h2>
        <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
            <input type="hidden" name="accion" value="solicitar">
            <div class="form-grid">
                <div>
                    <label>Producto o insumo</label>
                    <select name="id_producto" required>
                        <option value="">Selecciona un producto</option>
                        <?php foreach ($todosProductos as $p): ?>
                            <option value="<?php echo h($p['id_producto']); ?>"><?php echo h($p['nombre'] . ' | stock actual: ' . $p['stock'] . ' ' . $p['unidad_medida']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Cantidad a reponer</label>
                    <input type="number" min="1" name="cantidad_solicitada" required>
                </div>
            </div>
            <div>
                <label>Observación</label>
                <textarea name="observacion" placeholder="Motivo de la solicitud, urgencia, etc."></textarea>
            </div>
            <div class="actions">
                <button type="submit">Enviar solicitud</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Productos con stock bajo</h2>
        <?php if ($productosStockBajo): ?>
            <div class="kpi-list">
                <?php foreach ($productosStockBajo as $p): ?>
                    <div class="kpi-row">
                        <strong><?php echo h($p['nombre']); ?></strong>
                        <span class="badge low"><?php echo h($p['stock']); ?> / mín <?php echo h($p['stock_minimo']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">Todos los productos tienen stock suficiente.</div>
        <?php endif; ?>
    </section>
</div>

<section class="table-panel" style="margin-top:18px;">
    <h2>Historial de solicitudes</h2>
    <?php if ($solicitudes): ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Producto</th>
                    <th>Cantidad</th>
                    <th>Solicitante</th>
                    <th>Observación</th>
                    <th>Estado</th>
                    <th>Aprobador</th>
                    <th>Nota</th>
                    <?php if ($esAdmin): ?><th>Acción</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($solicitudes as $s): ?>
                    <tr>
                        <td><?php echo h($s['id_solicitud']); ?></td>
                        <td><?php echo h($s['producto']); ?></td>
                        <td><?php echo h($s['cantidad_solicitada']); ?></td>
                        <td><?php echo h($s['solicitante']); ?></td>
                        <td><?php echo h($s['observacion']); ?></td>
                        <td>
                            <span class="badge <?php echo ['pendiente'=>'low','aprobada'=>'ok','rechazada'=>'closed'][$s['estado']] ?? 'closed'; ?>">
                                <?php echo h(ucfirst($s['estado'])); ?>
                            </span>
                        </td>
                        <td><?php echo h($s['aprobador'] ?? '—'); ?></td>
                        <td><?php echo h($s['nota_admin'] ?? '—'); ?></td>
                        <?php if ($esAdmin): ?>
                            <td>
                                <?php if ($s['estado'] === 'pendiente'): ?>
                                    <div class="actions actions-inline">
                                        <form method="POST" onsubmit="return confirm('¿Aprobar esta reposición?');">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                            <input type="hidden" name="accion" value="aprobar">
                                            <input type="hidden" name="id_solicitud" value="<?php echo h($s['id_solicitud']); ?>">
                                            <input type="text" name="nota_admin" placeholder="Nota (opcional)" style="width:120px;padding:6px 8px;">
                                            <button type="submit" class="btn success btn-small">Aprobar</button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('¿Rechazar esta solicitud?');">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                            <input type="hidden" name="accion" value="rechazar">
                                            <input type="hidden" name="id_solicitud" value="<?php echo h($s['id_solicitud']); ?>">
                                            <input type="text" name="nota_admin" placeholder="Motivo rechazo" style="width:120px;padding:6px 8px;">
                                            <button type="submit" class="btn danger btn-small">Rechazar</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">Procesada</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">No hay solicitudes de reposición registradas.</div>
    <?php endif; ?>
</section>

<?php include 'includes/footer.php'; ?>
