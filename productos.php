<?php
session_start();
require 'conexion.php';
require_once 'includes/pagos.php';
requireLogin();
requireAdmin();

$tiposValidos = ['producto', 'insumo'];
$editarId = (int)($_GET['editar'] ?? 0);
$productoEditar = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) { setFlash('error','La sesión del formulario venció. Recarga la página.'); header('Location: productos.php'); exit; }
    try { validarCamposTexto($_POST, ['accion', 'id_producto', 'nombre', 'descripcion', 'tipo', 'precio', 'stock', 'stock_minimo', 'stock_original', 'unidad_medida']); }
    catch (InvalidArgumentException $e) { setFlash('error',$e->getMessage()); header('Location: productos.php'); exit; }
    $accion = $_POST['accion'] ?? 'guardar';
    if (!in_array($accion,['guardar','actualizar','eliminar'],true) || ($accion==='actualizar' && (int)($_POST['id_producto'] ?? 0)<=0)) {
        setFlash('error','Acción de producto no válida.'); header('Location: productos.php'); exit;
    }

    if ($accion === 'eliminar') {
        $idProducto = (int)($_POST['id_producto'] ?? 0);
        if ($idProducto <= 0) {
            setFlash('error', 'Producto inválido.');
            header('Location: productos.php');
            exit;
        }

        $tx=false;
        try {
            pagoBegin($conn); $tx=true;
            $locked=pagoUno($conn,'SELECT id_producto FROM productos WHERE id_producto=? FOR UPDATE',[$idProducto]);
            if (!$locked) throw new RuntimeException('El producto ya no existe.');
            $refs=pagoUno($conn,'SELECT (SELECT COUNT(*) FROM detalle_venta WHERE id_producto=?) + (SELECT COUNT(*) FROM movimientos_inventario WHERE id_producto=?) + (SELECT COUNT(*) FROM solicitudes_reposicion WHERE id_producto=?) n',[$idProducto,$idProducto,$idProducto]);
            $paymentRefs=pagoSchemaReady($conn) ? (int)pagoUno($conn,'SELECT COUNT(*) n FROM pagos_lineas WHERE id_producto=?',[$idProducto])['n'] : 0;
            if ((int)$refs['n']+$paymentRefs>0) throw new RuntimeException('No se elimina: el producto tiene ventas, movimientos, reposiciones u órdenes de pago vinculadas.');
            pagoExec($conn,'DELETE FROM productos WHERE id_producto=?',[$idProducto]);
            $conn->commit(); setFlash('success','Producto eliminado correctamente.');
        } catch(Throwable $e) {
            if ($tx) $conn->rollback();
            setFlash('error',$e instanceof mysqli_sql_exception ? 'No se puede eliminar el producto porque tiene registros relacionados.' : $e->getMessage());
        }
        header('Location: productos.php');
        exit;
    }

    $idProducto = (int)($_POST['id_producto'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $tipo = $_POST['tipo'] ?? 'producto';
    $precioRaw=trim($_POST['precio'] ?? '');
    if ($precioRaw==='' && $tipo==='insumo') $precioRaw='0';
    $precio = (float)$precioRaw;
    $stock = (int)($_POST['stock'] ?? 0);
    $stockMinimo = (int)($_POST['stock_minimo'] ?? 0);
    $unidad = trim($_POST['unidad_medida'] ?? 'unidad');

    try {
        $nombre=pagoTexto($nombre,120,true); $unidad=pagoTexto($unidad,40,true);
        pagoCentimos($precioRaw);
        if (strlen($descripcion)>60000) throw new InvalidArgumentException('La descripción es demasiado larga.');
        foreach (['stock','stock_minimo'] as $field) {
            if (!preg_match('/^(0|[1-9][0-9]{0,8})$/D',(string)($_POST[$field] ?? ''))) throw new InvalidArgumentException('El stock debe ser un entero no negativo.');
        }
    } catch(InvalidArgumentException $e) { setFlash('error',$e->getMessage()); header('Location: productos.php'); exit; }

    if ($nombre === '' || $stock < 0 || $stockMinimo < 0 || !in_array($tipo, $tiposValidos, true)) {
        setFlash('error', 'Completa correctamente los datos del producto.');
        header('Location: productos.php' . ($idProducto > 0 ? '?editar=' . $idProducto : ''));
        exit;
    }

    if ($tipo === 'producto' && $precio <= 0) {
        setFlash('error', 'Los productos terminados deben tener un precio mayor a cero.');
        header('Location: productos.php' . ($idProducto > 0 ? '?editar=' . $idProducto : ''));
        exit;
    }

    if ($tipo === 'insumo') {
        $precio = max(0, $precio);
    }

    if ($accion === 'actualizar' && $idProducto > 0) {
        $tx=false;
        try {
            pagoBegin($conn); $tx=true;
            $actual=pagoUno($conn,'SELECT stock,tipo FROM productos WHERE id_producto=? FOR UPDATE',[$idProducto]);
            if (!$actual) throw new RuntimeException('El producto ya no existe.');
            if (!isset($_POST['stock_original']) || (string)$_POST['stock_original'] !== (string)$actual['stock']) {
                throw new RuntimeException('El stock cambió mientras editabas. Recarga el producto para no sobrescribir una venta o reposición reciente.');
            }
            $reservado=pagoReservado($conn,$idProducto);
            if ($stock<$reservado || ($tipo==='insumo' && $reservado>0)) throw new RuntimeException('El cambio afecta unidades reservadas para pagos pendientes. Resuelve las órdenes primero.');
            pagoExec($conn,'UPDATE productos SET nombre=?,descripcion=?,tipo=?,precio=?,stock=?,stock_minimo=?,unidad_medida=? WHERE id_producto=?',[$nombre,$descripcion,$tipo,pagoDecimal(pagoCentimos($precioRaw)),$stock,$stockMinimo,$unidad,$idProducto]);
            if ($stock!==(int)$actual['stock']) pagoExec($conn,'INSERT INTO movimientos_inventario(id_producto,tipo_movimiento,cantidad,motivo,stock_anterior,stock_nuevo) VALUES(?,?,?,?,?,?)',[$idProducto,'ajuste',$stock,'Ajuste desde ficha de producto',(int)$actual['stock'],$stock]);
            $conn->commit(); setFlash('success','Producto actualizado correctamente.');
        } catch(Throwable $e) {
            if ($tx) $conn->rollback();
            setFlash('error',$e instanceof mysqli_sql_exception ? 'No se pudo actualizar el producto.' : $e->getMessage());
        }
        header('Location: productos.php');
        exit;
    }

    try {
        pagoExec($conn,'INSERT INTO productos (nombre,descripcion,tipo,precio,stock,stock_minimo,unidad_medida) VALUES (?,?,?,?,?,?,?)',
            [$nombre,$descripcion,$tipo,pagoDecimal(pagoCentimos($precioRaw)),$stock,$stockMinimo,$unidad]);
        setFlash('success','Producto registrado correctamente.');
    } catch (mysqli_sql_exception $e) {
        error_log('Fontana: alta de producto falló; código '.$e->getCode());
        setFlash('error','No se pudo registrar el producto. Verifica sus datos y la conexión.');
    }
    header('Location: productos.php');
    exit;
}

$pageTitle = 'Gestión de productos';
$activePage = 'productos';
$buscar = is_string($_GET['buscar'] ?? '') ? trim($_GET['buscar'] ?? '') : '';

if ($editarId > 0) {
    $stmt = $conn->prepare('SELECT * FROM productos WHERE id_producto = ? LIMIT 1');
    $stmt->bind_param('i', $editarId);
    $stmt->execute();
    $productoEditar = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$formData = $productoEditar ?: [
    'id_producto' => 0,
    'nombre' => '',
    'descripcion' => '',
    'tipo' => 'producto',
    'precio' => '',
    'stock' => 0,
    'stock_minimo' => 0,
    'unidad_medida' => 'unidad',
];

if ($buscar !== '') {
    $stmt = $conn->prepare('SELECT * FROM productos WHERE nombre LIKE CONCAT("%", ?, "%") OR tipo LIKE CONCAT("%", ?, "%") OR descripcion LIKE CONCAT("%", ?, "%") ORDER BY id_producto DESC');
    $stmt->bind_param('sss', $buscar, $buscar, $buscar);
    $stmt->execute();
    $productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $productos = fetchAllAssoc($conn, 'SELECT * FROM productos ORDER BY id_producto DESC');
}

include 'includes/header.php';
?>

<div class="grid-two">
    <section class="form-panel">
        <h2><?php echo $productoEditar ? 'Editar producto o insumo' : 'Nuevo producto o insumo'; ?></h2>
        <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
            <input type="hidden" name="accion" value="<?php echo $productoEditar ? 'actualizar' : 'guardar'; ?>">
            <input type="hidden" name="id_producto" value="<?php echo h($formData['id_producto']); ?>">
            <input type="hidden" name="stock_original" value="<?php echo h((string)$formData['stock']); ?>">
            <div class="form-grid">
                <div>
                    <label>Nombre</label>
                    <input type="text" name="nombre" value="<?php echo h($formData['nombre']); ?>" required>
                </div>
                <div>
                    <label>Tipo</label>
                    <select name="tipo" required>
                        <option value="producto" <?php echo $formData['tipo'] === 'producto' ? 'selected' : ''; ?>>Producto</option>
                        <option value="insumo" <?php echo $formData['tipo'] === 'insumo' ? 'selected' : ''; ?>>Insumo</option>
                    </select>
                </div>
                <div>
                    <label>Precio de venta</label>
                    <input type="number" step="0.01" min="0" name="precio" value="<?php echo h((string)$formData['precio']); ?>" required>
                </div>
                <div>
                    <label>Unidad de medida</label>
                    <input type="text" name="unidad_medida" value="<?php echo h($formData['unidad_medida']); ?>" required>
                </div>
                <div>
                    <label>Stock inicial / actual</label>
                    <input type="number" min="0" name="stock" value="<?php echo h((string)$formData['stock']); ?>" required>
                </div>
                <div>
                    <label>Stock mínimo</label>
                    <input type="number" min="0" name="stock_minimo" value="<?php echo h((string)$formData['stock_minimo']); ?>" required>
                </div>
            </div>
            <div>
                <label>Descripción</label>
                <textarea name="descripcion" placeholder="Ejemplo: helado artesanal, topping, vaso, leche, etc."><?php echo h($formData['descripcion']); ?></textarea>
            </div>
            <div class="actions">
                <button type="submit"><?php echo $productoEditar ? 'Actualizar producto' : 'Guardar producto'; ?></button>
                <?php if ($productoEditar): ?>
                    <a href="productos.php" class="btn secondary">Cancelar edición</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Qué registrar aquí</h2>
        <div class="kpi-list">
            <div class="kpi-row"><strong>Productos</strong><span class="muted">Helados, paletas, postres y similares</span></div>
            <div class="kpi-row"><strong>Insumos</strong><span class="muted">Leche, azúcar, envases, cucharitas, etc.</span></div>
            <div class="kpi-row"><strong>Stock mínimo</strong><span class="muted">Sirve para alertas en el dashboard</span></div>
            <div class="kpi-row"><strong>Mejora nueva</strong><span class="muted">Ahora puedes editar y eliminar productos sin uso previo</span></div>
        </div>
    </section>
</div>

<section class="table-panel" style="margin-top:18px;">
    <h2>Listado de productos</h2>
    <form method="GET" class="filters">
        <div>
            <label>Buscar por nombre, tipo o descripción</label>
            <input type="text" name="buscar" value="<?php echo h($buscar); ?>" placeholder="Ejemplo: helado, insumo, cono">
        </div>
        <div style="flex:0 0 auto; min-width: 150px;">
            <button type="submit">Buscar</button>
        </div>
        <div style="flex:0 0 auto; min-width: 150px;">
            <a href="productos.php" class="btn secondary">Limpiar</a>
        </div>
    </form>

    <?php if ($productos): ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Tipo</th>
                    <th>Precio</th>
                    <th>Stock</th>
                    <th>Mínimo</th>
                    <th>Unidad</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($productos as $producto): ?>
                    <tr>
                        <td><?php echo h($producto['id_producto']); ?></td>
                        <td><?php echo h($producto['nombre']); ?></td>
                        <td><?php echo h(ucfirst($producto['tipo'])); ?></td>
                        <td><?php echo h(money($producto['precio'])); ?></td>
                        <td><?php echo h($producto['stock']); ?></td>
                        <td><?php echo h($producto['stock_minimo']); ?></td>
                        <td><?php echo h($producto['unidad_medida']); ?></td>
                        <td><span class="badge <?php echo $producto['stock'] <= $producto['stock_minimo'] ? 'low' : 'ok'; ?>"><?php echo $producto['stock'] <= $producto['stock_minimo'] ? 'Bajo' : 'Normal'; ?></span></td>
                        <td>
                            <div class="actions actions-inline">
                                <a href="productos.php?editar=<?php echo h($producto['id_producto']); ?>" class="btn secondary btn-small">Editar</a>
                                <form method="POST" onsubmit="return confirm('¿Seguro que deseas eliminar este producto?');">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id_producto" value="<?php echo h($producto['id_producto']); ?>">
                                    <button type="submit" class="btn danger btn-small">Eliminar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">No se encontraron productos con ese criterio.</div>
    <?php endif; ?>
</section>

<?php include 'includes/footer.php'; ?>
