<?php
// Compatibilidad con la instalación original. Mantén tus valores al actualizar.
$servername = getenv('FONTANA_DB_HOST') ?: '127.0.0.1';
$username = getenv('FONTANA_DB_USER') ?: 'root';
$password = getenv('FONTANA_DB_PASSWORD') !== false ? getenv('FONTANA_DB_PASSWORD') : '';
$dbname = getenv('FONTANA_DB_NAME') ?: 'fontana_db';
$port = (int)(getenv('FONTANA_DB_PORT') ?: 3306);
// Opcional: separar credenciales para no tener que editar este archivo al actualizar.
$configFile = __DIR__ . '/config/conexion.php';
if (is_file($configFile)) {
    $dbConfig = require $configFile;
    if (is_array($dbConfig)) {
        $servername = (string)($dbConfig['host'] ?? $servername);
        $username = (string)($dbConfig['user'] ?? $username);
        $password = (string)($dbConfig['password'] ?? $password);
        $dbname = (string)($dbConfig['database'] ?? $dbname);
        $port = (int)($dbConfig['port'] ?? $port);
    }
}
date_default_timezone_set('America/Lima');
if (!extension_loaded('mysqli')) {
    http_response_code(503);
    exit('Fontana necesita la extensión PHP mysqli. Actívala en tu servidor.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($servername, $username, $password, $dbname, $port);
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '-05:00'");
} catch (Throwable $e) {
    error_log('Fontana: conexión MySQL no disponible; código ' . $e->getCode());
    http_response_code(503);
    exit('No se pudo conectar con la base de datos. Revisa conexion.php o config/conexion.php. No importes un respaldo para solucionar una contraseña o ruta incorrecta.');
}
