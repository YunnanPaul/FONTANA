<?php
session_start();
require 'conexion.php';
require_once 'includes/pagos.php';
requireLogin();
$cajaAbierta = getTodayOpenCashBox($conn);
$tieneTotalEfectivo = (int)pagoUno($conn, "SELECT COUNT(*) n FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cierres_caja' AND column_name='total_efectivo'")['n'] > 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('error','La sesión del formulario venció. Recarga la página.'); header('Location: caja.php'); exit;
    }
    $accion = $_POST['accion'] ?? '';
    $lock = false; $tx = false;
    try {
        $usuarioActual = currentUser();
        if ($accion === 'abrir') {
            $montoInicial = pagoDecimal(pagoCentimos($_POST['monto_inicial'] ?? ''));
            $lock = (int)pagoUno($conn, "SELECT GET_LOCK(CONCAT('fontana:',MD5(DATABASE()),':caja'),5) n")['n'] === 1;
            if (!$lock) throw new RuntimeException('Otra apertura de caja está en curso.');
            pagoBegin($conn); $tx = true;
            $cajasHoy = pagoUno($conn, 'SELECT COUNT(*) n FROM cierres_caja WHERE fecha=CURDATE()');
            if ((int)$cajasHoy['n'] > 0) throw new RuntimeException('Ya existe una caja registrada para hoy. No se abre una segunda caja.');
            pagoExec($conn, "INSERT INTO cierres_caja(fecha,monto_inicial,total_ventas,monto_final,observacion,estado,id_usuario_apertura,nombre_usuario_apertura) VALUES(CURDATE(),?,0,0,'','abierta',?,?)", [$montoInicial,(int)$usuarioActual['id_usuario'],$usuarioActual['nombre']]);
            $conn->commit(); $tx = false;
            setFlash('success','Caja abierta correctamente.');
        } elseif ($accion === 'cerrar') {
            $contado = pagoCentimos($_POST['monto_contado'] ?? '');
            $observacion = pagoTexto($_POST['observacion'] ?? '',1000);
            pagoBegin($conn); $tx = true;
            $box = pagoUno($conn, "SELECT * FROM cierres_caja WHERE fecha=CURDATE() AND estado='abierta' ORDER BY id_cierre DESC LIMIT 1 FOR UPDATE");
            if (!$box) throw new RuntimeException('La caja ya está cerrada o todavía no fue abierta.');
            if (pagoSchemaReady($conn)) {
                $pending = pagoUno($conn, "SELECT COUNT(*) n FROM pagos_ordenes WHERE id_cierre=? AND id_venta IS NULL AND entorno NOT IN ('demo','sandbox') AND ((estado IN ('en_proceso','revision') AND (proveedor<>'demo' OR reserva_hasta>NOW())) OR (estado='pendiente' AND reserva_hasta>NOW()))", [(int)$box['id_cierre']]);
                if ((int)$pending['n'] > 0) throw new RuntimeException('Hay órdenes pendientes o en revisión. Resuélvelas en Pagos antes de cerrar la caja; no vuelvas a cobrar.');
            }
            $sales = pagoUno($conn, "SELECT COALESCE(SUM(total),0) total, COALESCE(SUM(CASE WHEN metodo_pago='Efectivo' THEN total ELSE 0 END),0) efectivo FROM ventas WHERE fecha>=CURDATE() AND fecha<DATE_ADD(CURDATE(),INTERVAL 1 DAY)");
            $cash = pagoCentimos((string)$sales['efectivo']);
            $basis = $tieneTotalEfectivo ? $cash : pagoCentimos((string)$sales['total']);
            $expected = pagoCentimos((string)$box['monto_inicial']) + $basis;
            if ($tieneTotalEfectivo) {
                pagoExec($conn, "UPDATE cierres_caja SET total_ventas=?,total_efectivo=?,monto_final=?,observacion=?,estado='cerrada',id_usuario_cierre=?,nombre_usuario_cierre=? WHERE id_cierre=?", [(string)$sales['total'],pagoDecimal($cash),pagoDecimal($contado),$observacion,(int)$usuarioActual['id_usuario'],$usuarioActual['nombre'],(int)$box['id_cierre']]);
            } else {
                // Compatibilidad exacta del cálculo histórico: no exigir columnas nuevas.
                pagoExec($conn, "UPDATE cierres_caja SET total_ventas=?,monto_final=?,observacion=?,estado='cerrada',id_usuario_cierre=?,nombre_usuario_cierre=? WHERE id_cierre=?", [(string)$sales['total'],pagoDecimal($contado),$observacion,(int)$usuarioActual['id_usuario'],$usuarioActual['nombre'],(int)$box['id_cierre']]);
            }
            $conn->commit(); $tx = false;
            $difference = $contado - $expected;
            setFlash('success','Caja cerrada correctamente. '.($tieneTotalEfectivo ? 'Efectivo esperado: ' : 'Esperado según cálculo original: ').pagoMoneda($expected).($difference!==0 ? ' | Diferencia: '.money($difference/100) : ' | Sin diferencia.'));
        } else throw new InvalidArgumentException('Acción de caja no válida.');
    } catch (Throwable $e) {
        if ($tx) $conn->rollback();
        if ($e instanceof mysqli_sql_exception) error_log('Fontana: caja, error DB '.$e->getCode());
        setFlash('error',$e instanceof mysqli_sql_exception ? 'No se pudo actualizar la caja. No se guardó el cierre.' : $e->getMessage());
    } finally {
        if ($lock) $conn->query("SELECT RELEASE_LOCK(CONCAT('fontana:',MD5(DATABASE()),':caja'))");
    }
    header('Location: caja.php'); exit;
}

$pageTitle = 'Caja diaria';
$activePage = 'caja';
$cajaAbierta = getTodayOpenCashBox($conn);
$ventasHoy = (float)scalar($conn, 'SELECT COALESCE(SUM(total),0) FROM ventas WHERE DATE(fecha) = CURDATE()');
$efectivoHoy = (float)scalar($conn, "SELECT COALESCE(SUM(total),0) FROM ventas WHERE DATE(fecha)=CURDATE() AND metodo_pago='Efectivo'");
$expresion = $tieneTotalEfectivo ? 'COALESCE(total_efectivo,total_ventas)' : 'total_ventas';
$ultimoCierre = fetchAllAssoc($conn, 'SELECT *, (monto_final - (monto_inicial + '.$expresion.')) AS diferencia FROM cierres_caja ORDER BY id_cierre DESC LIMIT 10');

include 'includes/header.php';
?>

<?php if (!$tieneTotalEfectivo): ?><div class="pay-notice warning">Base original detectada: apertura y cierre siguen disponibles. Se conserva el cálculo original (inicial + total de ventas, incluidos pagos digitales). Para separar el efectivo en cierres nuevos, instala la ampliación desde <a href="configurar_pagos.php">Configuración de pagos</a>. No se recalculan cierres antiguos.</div><?php endif; ?>
<div class="grid-two">
    <section class="form-panel">
        <h2>Apertura de caja</h2>
        <?php if (!$cajaAbierta): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                <input type="hidden" name="accion" value="abrir">
                <div>
                    <label>Monto inicial</label>
                    <input type="number" step="0.01" min="0" name="monto_inicial" required>
                </div>
                <div class="actions">
                    <button type="submit">Abrir caja</button>
                </div>
            </form>
        <?php else: ?>
            <div class="kpi-list">
                <div class="kpi-row"><strong>Estado</strong><span class="badge open">Abierta</span></div>
                <div class="kpi-row"><strong>Abrió la caja</strong><span><?php echo h($cajaAbierta['nombre_usuario_apertura'] ?? '—'); ?></span></div>
                <div class="kpi-row"><strong>Monto inicial</strong><span><?php echo h(money($cajaAbierta['monto_inicial'])); ?></span></div>
                <div class="kpi-row"><strong>Ventas acumuladas</strong><span><?php echo h(money($ventasHoy)); ?></span></div>
                <div class="kpi-row"><strong>Ventas en efectivo</strong><span><?php echo h(money($efectivoHoy)); ?></span></div>
                <div class="kpi-row"><strong>Pagos digitales / tarjeta</strong><span><?php echo h(money($ventasHoy - $efectivoHoy)); ?></span></div>
                <div class="kpi-row"><strong><?php echo $tieneTotalEfectivo ? 'Efectivo esperado en caja' : 'Esperado (cálculo original)'; ?></strong><span><?php echo h(money($cajaAbierta['monto_inicial'] + ($tieneTotalEfectivo ? $efectivoHoy : $ventasHoy))); ?></span></div>
            </div>
        <?php endif; ?>
    </section>

    <section class="form-panel">
        <h2>Cierre de caja</h2>
        <?php if ($cajaAbierta): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                <input type="hidden" name="accion" value="cerrar">
                <div>
                    <label>Monto contado al cierre</label>
                    <input type="number" step="0.01" min="0" name="monto_contado" required>
                </div>
                <div>
                    <label>Observación</label>
                    <textarea name="observacion" placeholder="Anota diferencias, faltantes o sobrantes."></textarea>
                </div>
                <div class="actions">
                    <button type="submit" class="btn danger">Cerrar caja</button>
                </div>
            </form>
        <?php else: ?>
            <div class="empty-state">No puedes cerrar la caja porque todavía no existe apertura para hoy.</div>
        <?php endif; ?>
    </section>
</div>

<section class="table-panel" style="margin-top:18px;">
    <h2>Historial de cajas</h2><p class="muted">Los cierres anteriores a esta actualización conservan su cálculo histórico. <?php echo $tieneTotalEfectivo ? 'Los nuevos comparan lo contado solo contra efectivo.' : 'Se conserva el cálculo original mientras no se instale la columna opcional de efectivo.'; ?></p>
    <?php if ($ultimoCierre): ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Fecha</th>
                    <th>Inicial</th>
                    <th>Ventas</th>
                    <th>Final</th>
                    <th>Diferencia</th>
                    <th>Estado</th>
                    <th>Abrió</th>
                    <th>Cerró</th>
                    <th>Observación</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ultimoCierre as $caja): ?>
                    <tr>
                        <td><?php echo h($caja['id_cierre']); ?></td>
                        <td><?php echo fmtFecha($caja['fecha'], false); ?></td>
                        <td><?php echo h(money($caja['monto_inicial'])); ?></td>
                        <td><?php echo h(money($caja['total_ventas'])); ?></td>
                        <td><?php echo h(money($caja['monto_final'])); ?></td>
                        <td><?php echo h(money($caja['diferencia'])); ?></td>
                        <td><span class="badge <?php echo $caja['estado'] === 'abierta' ? 'open' : 'closed'; ?>"><?php echo h(ucfirst($caja['estado'])); ?></span></td>
                        <td><?php echo h($caja['nombre_usuario_apertura'] ?? '—'); ?></td>
                        <td><?php echo h($caja['nombre_usuario_cierre'] ?? '—'); ?></td>
                        <td><?php echo h($caja['observacion']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">Aún no se han registrado aperturas o cierres de caja.</div>
    <?php endif; ?>
</section>

<?php include 'includes/footer.php'; ?>
