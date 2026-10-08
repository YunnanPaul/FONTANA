<?php
if (!isset($pageTitle)) {
    $pageTitle = 'Sistema Fontana';
}
if (!isset($activePage)) {
    $activePage = 'dashboard';
}
$flash = flashMessage();
$usuarioActual = currentUser();

// Actualizar actividad y limpiar sesiones viejas en cada carga
if (isset($conn) && isLoggedIn()) {
    refreshSessionActivity($conn);
    // Limpieza ocasional (1 de cada 10 peticiones)
    if (rand(1, 10) === 1) {
        cleanStaleSessions($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($pageTitle); ?></title>
    <link rel="stylesheet" href="css/estilos.css">
    <?php if (in_array($activePage, ['ventas', 'pagos', 'caja'], true)): ?>
    <link rel="stylesheet" href="css/pagos.css?v=documento-auto-20260909-1">
    <?php endif; ?>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">
            <img src="img/logo_fontana1.png" alt="Logo Fontana">
        </div>

        <?php if ($usuarioActual): ?>
            <div class="sidebar-user">
                <strong><?php echo h($usuarioActual['nombre']); ?></strong>
                <span><?php echo h(ucfirst($usuarioActual['rol'])); ?></span>
            </div>
        <?php endif; ?>

        <nav class="sidebar-nav">
            <a class="nav-item <?php echo $activePage === 'dashboard' ? 'active' : ''; ?>" href="index.php">Dashboard</a>
            <?php if (isAdmin()): ?>
            <a class="nav-item <?php echo $activePage === 'productos' ? 'active' : ''; ?>" href="productos.php">Productos</a>
            <a class="nav-item <?php echo $activePage === 'inventario' ? 'active' : ''; ?>" href="inventario.php">Inventario</a>
            <a class="nav-item <?php echo $activePage === 'reposicion' ? 'active' : ''; ?>" href="reposicion.php">Reposición</a>
            <?php endif; ?>
            <a class="nav-item <?php echo $activePage === 'ventas' ? 'active' : ''; ?>" href="ventas.php">Ventas</a>
            <a class="nav-item <?php echo $activePage === 'pagos' ? 'active' : ''; ?>" href="pagos.php">Pagos y cobros</a>
            <a class="nav-item <?php echo $activePage === 'caja' ? 'active' : ''; ?>" href="caja.php">Caja diaria</a>
            <?php if (isAdmin()): ?>
            <a class="nav-item <?php echo $activePage === 'reportes' ? 'active' : ''; ?>" href="reportes.php">Reportes</a>
            <a class="nav-item <?php echo $activePage === 'usuarios' ? 'active' : ''; ?>" href="usuarios.php">Usuarios</a>
            <?php endif; ?>
            <a class="nav-item logout-link" href="logout.php">Cerrar sesión</a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div>
                <h1><?php echo h($pageTitle); ?></h1>
                <p class="subline">Sistema web de stock, ventas, insumos y caja diaria</p>
            </div>
            <div class="topbar-right">
                <?php if ($usuarioActual): ?>
                    <div class="topbar-user-card">
                        <span class="user-name"><?php echo h($usuarioActual['nombre']); ?></span>
                        <span class="user-role"><?php echo h(ucfirst($usuarioActual['rol'])); ?></span>
                    </div>
                <?php endif; ?>
                <div class="topbar-badge">Uso interno</div>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="alert <?php echo h($flash['type']); ?>"><?php echo h($flash['message']); ?></div>
        <?php endif; ?>
