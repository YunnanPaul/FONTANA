<?php
session_start();
require 'conexion.php';
require 'includes/helpers.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) { setFlash('error','La sesión del formulario venció. Recarga la página.'); header('Location: login.php'); exit; }
    if (!is_string($_POST['usuario'] ?? '') || !is_string($_POST['clave'] ?? '')) {
        setFlash('error','Credenciales no válidas.'); header('Location: login.php'); exit;
    }
    $usuario = trim($_POST['usuario'] ?? '');
    $clave = $_POST['clave'] ?? '';

    if ($usuario === '' || $clave === '') {
        setFlash('error', 'Ingresa tu usuario y contraseña.');
        header('Location: login.php');
        exit;
    }

    try {
        $stmt = $conn->prepare('SELECT id_usuario, nombre, usuario, clave, rol, estado FROM usuarios WHERE usuario = ? LIMIT 1');
        $stmt->bind_param('s', $usuario); $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
    } catch (mysqli_sql_exception $e) {
        error_log('Fontana: no se pudo consultar usuarios; código '.$e->getCode());
        setFlash('error','No se pudo leer la tabla de usuarios. Revisa la conexión y la estructura de tu base. No restaures un SQL sobre tus datos para resolverlo.');
        header('Location: login.php'); exit;
    }

    if (!$user || $user['estado'] !== 'activo' || !password_verify($clave, $user['clave'])) {
        setFlash('error', 'Usuario o contraseña incorrectos.');
        header('Location: login.php');
        exit;
    }

    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
    $_SESSION['usuario'] = [
        'id_usuario' => $user['id_usuario'],
        'nombre' => $user['nombre'],
        'usuario' => $user['usuario'],
        'rol' => $user['rol'],
    ];

    // Registrar sesión activa en la base de datos
    $sessionToken = session_id();
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    try {
    $stmtSes = $conn->prepare("INSERT INTO sesiones_activas (id_usuario, session_token, ultima_actividad, ip) VALUES (?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE ultima_actividad = NOW(), ip = ?");
    $stmtSes->bind_param('isss', $user['id_usuario'], $sessionToken, $ip, $ip);
    $stmtSes->execute();
    $stmtSes->close();
    } catch (mysqli_sql_exception $e) {
        error_log('Fontana: inicio correcto, seguimiento de sesión no disponible; código '.$e->getCode());
    }

    setFlash('success', 'Bienvenido, ' . $user['nombre'] . '.');
    header('Location: index.php');
    exit;
}

$flash = flashMessage();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Sistema Fontana</title>
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body class="login-body">
    <div class="login-shell">
        <div class="login-card">
            <div class="login-brand">
                <img src="img/logo_fontana1.png" alt="Logo Fontana">
                <h1>Sistema Fontana</h1>
                <p>Stock, ventas, inventario y caja diaria</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert <?php echo h($flash['type']); ?>"><?php echo h($flash['message']); ?></div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                <div>
                    <label>Usuario</label>
                    <input type="text" name="usuario" placeholder="Ingresa tu usuario" required>
                </div>
                <div>
                    <label>Contraseña</label>
                    <input type="password" name="clave" placeholder="Ingresa tu contraseña" required>
                </div>
                <button type="submit" class="btn btn-login">Ingresar al sistema</button>
            </form>
        </div>
    </div>
</body>
</html>