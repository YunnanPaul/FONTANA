<?php
session_start();
require 'conexion.php';
require_once 'includes/pagos.php';
requireLogin();
requireAdmin();

$editarId = (int)($_GET['editar'] ?? 0);
$usuarioEditar = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) { setFlash('error','La sesión del formulario venció. Recarga la página.'); header('Location: usuarios.php'); exit; }
    try {
    validarCamposTexto($_POST, ['accion','id_usuario','nombre','usuario','clave','rol']);
    $accion = $_POST['accion'] ?? 'guardar';
    if (!in_array($accion,['guardar','actualizar','toggle_estado'],true)) throw new InvalidArgumentException('Acción no válida.');

    if ($accion === 'toggle_estado') {
        $idUsuario = (int)($_POST['id_usuario'] ?? 0);
        $usuario = currentUser();
        if ($idUsuario === (int)$usuario['id_usuario']) {
            setFlash('error', 'No puedes desactivar tu propia cuenta.');
            header('Location: usuarios.php');
            exit;
        }
        $stmt = $conn->prepare("UPDATE usuarios SET estado = IF(estado='activo','inactivo','activo') WHERE id_usuario = ?");
        $stmt->bind_param('i', $idUsuario);
        $stmt->execute();
        $stmt->close();
        setFlash('success', 'Estado del usuario actualizado.');
        header('Location: usuarios.php');
        exit;
    }

    $idUsuario   = (int)($_POST['id_usuario'] ?? 0);
    $nombre      = trim($_POST['nombre'] ?? '');
    $usuarioName = trim($_POST['usuario'] ?? '');
    $clave       = $_POST['clave'] ?? '';
    $rol         = $_POST['rol'] ?? 'cajero';
    $rolesValidos = ['administrador', 'cajero'];
    if (strlen($clave)>72 || str_contains($clave,"\0")) throw new InvalidArgumentException('La contraseña debe tener como máximo 72 bytes y no contener caracteres nulos.');
    if ($accion==='actualizar' && ($idUsuario<=0 || !pagoUno($conn,'SELECT id_usuario FROM usuarios WHERE id_usuario=?',[$idUsuario]))) throw new InvalidArgumentException('El usuario que intentas editar ya no existe.');

    if ($nombre === '' || $usuarioName === '' || !in_array($rol, $rolesValidos, true)) {
        setFlash('error', 'Completa correctamente todos los campos.');
        header('Location: usuarios.php' . ($idUsuario > 0 ? '?editar=' . $idUsuario : ''));
        exit;
    }

    if ($accion==='actualizar' && $idUsuario===(int)currentUser()['id_usuario'] && $rol!=='administrador') {
        setFlash('error','No puedes quitarte tu propio acceso de administrador. Usa otra cuenta administradora para gestionar ese cambio.');
        header('Location: usuarios.php'); exit;
    }
    if (pagoUno($conn,'SELECT id_usuario FROM usuarios WHERE usuario=? AND id_usuario<>?',[$usuarioName,$idUsuario])) {
        setFlash('error','Ese nombre de usuario ya está registrado. Elige otro.'); header('Location: usuarios.php'); exit;
    }
    try { pagoTexto($nombre,120,true); pagoTexto($usuarioName,60,true); }
    catch(InvalidArgumentException $e) { setFlash('error','Nombre o usuario demasiado largos.'); header('Location: usuarios.php'); exit; }

    if ($accion === 'actualizar' && $idUsuario > 0) {
        if ($clave !== '') {
            $hash = password_hash($clave, PASSWORD_BCRYPT);
            $stmt = $conn->prepare('UPDATE usuarios SET nombre=?, usuario=?, clave=?, rol=? WHERE id_usuario=?');
            $stmt->bind_param('ssssi', $nombre, $usuarioName, $hash, $rol, $idUsuario);
        } else {
            $stmt = $conn->prepare('UPDATE usuarios SET nombre=?, usuario=?, rol=? WHERE id_usuario=?');
            $stmt->bind_param('sssi', $nombre, $usuarioName, $rol, $idUsuario);
        }
        $stmt->execute();
        $stmt->close();
        setFlash('success', 'Usuario actualizado correctamente.');
        header('Location: usuarios.php');
        exit;
    }

    if ($clave === '') {
        setFlash('error', 'La contraseña es obligatoria al crear un usuario.');
        header('Location: usuarios.php');
        exit;
    }

    $hash = password_hash($clave, PASSWORD_BCRYPT);
    $stmt = $conn->prepare('INSERT INTO usuarios (nombre, usuario, clave, rol) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $nombre, $usuarioName, $hash, $rol);
    $stmt->execute();
    $stmt->close();
    setFlash('success', 'Usuario creado correctamente.');
    header('Location: usuarios.php');
    exit;
    } catch (Throwable $e) {
        setFlash('error',$e instanceof mysqli_sql_exception ? 'No se pudo guardar el usuario. Verifica que el login no esté duplicado.' : $e->getMessage());
        header('Location: usuarios.php'); exit;
    }
}

$pageTitle  = 'Gestión de usuarios';
$activePage = 'usuarios';

if ($editarId > 0) {
    $stmt = $conn->prepare('SELECT * FROM usuarios WHERE id_usuario = ? LIMIT 1');
    $stmt->bind_param('i', $editarId);
    $stmt->execute();
    $usuarioEditar = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$formData = $usuarioEditar ?: ['id_usuario' => 0, 'nombre' => '', 'usuario' => '', 'rol' => 'cajero'];
$usuarios = fetchAllAssoc($conn, 'SELECT * FROM usuarios ORDER BY id_usuario ASC');
$usuariosEnLinea = getActiveUsers($conn);

include 'includes/header.php';
?>

<div class="grid-two">
    <!-- Panel: usuarios en línea ahora mismo -->
    <section class="panel" style="grid-column: 1 / -1; margin-bottom: 0;">
        <h2>🟢 Usuarios en línea ahora
            <span style="font-size:0.8rem;font-weight:normal;color:var(--muted);margin-left:8px;">
                (activos en los últimos 5 minutos — se actualiza al recargar)
            </span>
        </h2>
        <?php if ($usuariosEnLinea): ?>
            <div class="kpi-list">
                <?php foreach ($usuariosEnLinea as $enLinea): ?>
                    <div class="kpi-row">
                        <strong>
                            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#22c55e;margin-right:6px;"></span>
                            <?php echo h($enLinea['nombre']); ?>
                            <span style="font-weight:normal;color:var(--muted);font-size:0.85rem;">
                                (<?php echo h(ucfirst($enLinea['rol'])); ?>)
                            </span>
                        </strong>
                        <span class="muted" style="font-size:0.85rem;">
                            Última actividad: <?php echo fmtFecha($enLinea['ultima_actividad']); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">Ningún usuario activo en este momento.</div>
        <?php endif; ?>
    </section>
</div>

<div class="grid-two" style="margin-top:18px;">
    <section class="form-panel">
        <h2><?php echo $usuarioEditar ? 'Editar usuario' : 'Nuevo usuario'; ?></h2>
        <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
            <input type="hidden" name="accion" value="<?php echo $usuarioEditar ? 'actualizar' : 'guardar'; ?>">
            <input type="hidden" name="id_usuario" value="<?php echo h($formData['id_usuario']); ?>">
            <div class="form-grid">
                <div>
                    <label>Nombre completo</label>
                    <input type="text" name="nombre" value="<?php echo h($formData['nombre']); ?>" required>
                </div>
                <div>
                    <label>Usuario (login)</label>
                    <input type="text" name="usuario" value="<?php echo h($formData['usuario']); ?>" required>
                </div>
                <div>
                    <label>Contraseña <?php echo $usuarioEditar ? '(dejar vacío para no cambiar)' : ''; ?></label>
                    <input type="password" name="clave" <?php echo !$usuarioEditar ? 'required' : ''; ?>>
                </div>
                <div>
                    <label>Rol</label>
                    <select name="rol" required>
                        <option value="administrador" <?php echo $formData['rol'] === 'administrador' ? 'selected' : ''; ?>>Administrador</option>
                        <option value="cajero" <?php echo $formData['rol'] === 'cajero' ? 'selected' : ''; ?>>Cajero</option>
                    </select>
                </div>
            </div>
            <div class="actions">
                <button type="submit"><?php echo $usuarioEditar ? 'Actualizar usuario' : 'Crear usuario'; ?></button>
                <?php if ($usuarioEditar): ?>
                    <a href="usuarios.php" class="btn secondary">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Roles del sistema</h2>
        <div class="kpi-list">
            <div class="kpi-row"><strong>Administrador</strong><span class="muted">Acceso completo a todos los módulos</span></div>
            <div class="kpi-row"><strong>Cajero</strong><span class="muted">Ventas, pagos propios y caja diaria</span></div>
        </div>
    </section>
</div>

<section class="table-panel" style="margin-top:18px;">
    <h2>Usuarios registrados</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Usuario</th>
                <th>Rol</th>
                <th>Estado</th>
                <th>Fecha registro</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><?php echo h($u['id_usuario']); ?></td>
                    <td><?php echo h($u['nombre']); ?></td>
                    <td><?php echo h($u['usuario']); ?></td>
                    <td><?php echo h(ucfirst($u['rol'])); ?></td>
                    <td><span class="badge <?php echo $u['estado'] === 'activo' ? 'open' : 'closed'; ?>"><?php echo h(ucfirst($u['estado'])); ?></span></td>
                    <td><?php echo fmtFecha($u['fecha_registro']); ?></td>
                    <td>
                        <div class="actions actions-inline">
                            <a href="usuarios.php?editar=<?php echo h($u['id_usuario']); ?>" class="btn secondary btn-small">Editar</a>
                            <form method="POST" onsubmit="return confirm('¿Cambiar estado de este usuario?');">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                <input type="hidden" name="accion" value="toggle_estado">
                                <input type="hidden" name="id_usuario" value="<?php echo h($u['id_usuario']); ?>">
                                <button type="submit" class="btn <?php echo $u['estado'] === 'activo' ? 'warning' : 'success'; ?> btn-small">
                                    <?php echo $u['estado'] === 'activo' ? 'Desactivar' : 'Activar'; ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php include 'includes/footer.php'; ?>
