<?php
session_start();
require 'conexion.php';

// Eliminar sesión activa de la base de datos al cerrar sesión
$sessionToken = session_id();
if ($sessionToken && isset($conn)) {
    try {
    $stmt = $conn->prepare("DELETE FROM sesiones_activas WHERE session_token = ?");
    $stmt->bind_param('s', $sessionToken);
    $stmt->execute();
    $stmt->close();
    } catch (mysqli_sql_exception $e) { error_log('Fontana: seguimiento de cierre no disponible; código '.$e->getCode()); }
}

$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
