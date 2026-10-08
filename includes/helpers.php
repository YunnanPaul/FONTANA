<?php
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($amount) {
    return 'S/ ' . number_format((float)$amount, 2);
}

/**
 * Formatea fechas/horas de la base a un formato legible en español.
 * Acepta DATETIME ('2026-07-15 14:30:00') o DATE ('2026-07-15').
 * Si el valor viene vacío o no es una fecha válida, lo devuelve tal cual.
 */
function fmtFecha($valor, bool $conHora = true): string {
    $valor = trim((string)$valor);
    if ($valor === '' || $valor === '0000-00-00' || $valor === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($valor);
    if ($ts === false) {
        return h($valor);
    }
    // Detecta si el valor original traía hora
    $traeHora = strpos($valor, ':') !== false;
    if ($conHora && $traeHora) {
        return date('d/m/Y h:i a', $ts);
    }
    return date('d/m/Y', $ts);
}

function scalar(mysqli $conn, string $sql) {
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }
    $row = $result->fetch_row();
    return $row[0] ?? 0;
}

function fetchAllAssoc(mysqli $conn, string $sql): array {
    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

function getTodayOpenCashBox(mysqli $conn) {
    $sql = "SELECT * FROM cierres_caja WHERE fecha = CURDATE() AND estado = 'abierta' ORDER BY id_cierre DESC LIMIT 1";
    $result = $conn->query($sql);
    return $result ? $result->fetch_assoc() : null;
}

function flashMessage(): ?array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function setFlash(string $type, string $message): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function paymentMethods(): array {
    return ['Efectivo', 'Yape', 'Plin', 'Tarjeta'];
}

function normalizeProductLines(array $productIds, array $quantities): array {
    $lines = [];
    $count = max(count($productIds), count($quantities));

    for ($i = 0; $i < $count; $i++) {
        $idProducto = (int)($productIds[$i] ?? 0);
        $cantidad = (int)($quantities[$i] ?? 0);

        if ($idProducto <= 0 || $cantidad <= 0) {
            continue;
        }

        if (!isset($lines[$idProducto])) {
            $lines[$idProducto] = 0;
        }
        $lines[$idProducto] += $cantidad;
    }

    return $lines;
}

function ensureSessionStarted(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function isLoggedIn(): bool {
    ensureSessionStarted();
    return !empty($_SESSION['usuario']);
}

function currentUser(): ?array {
    ensureSessionStarted();
    return $_SESSION['usuario'] ?? null;
}

function requireLogin(): void {
    ensureSessionStarted();
    global $conn;
    if (!empty($_SESSION['usuario']) && isset($conn) && $conn instanceof mysqli) {
        $id = (int)$_SESSION['usuario']['id_usuario'];
        $stmt = $conn->prepare('SELECT id_usuario,nombre,usuario,rol,estado FROM usuarios WHERE id_usuario=?');
        $stmt->bind_param('i',$id); $stmt->execute(); $actual=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$actual || $actual['estado']!=='activo') unset($_SESSION['usuario']);
        else { unset($actual['estado']); $_SESSION['usuario']=$actual; }
    }
    if (empty($_SESSION['usuario'])) {
        setFlash('error', 'Debes iniciar sesión para ingresar al sistema.');
        header('Location: login.php');
        exit;
    }
}

function requireAdmin(): void {
    ensureSessionStarted();
    $usuario = $_SESSION['usuario'] ?? null;
    if (!$usuario || $usuario['rol'] !== 'administrador') {
        setFlash('error', 'Solo los administradores pueden acceder a esta sección.');
        header('Location: index.php');
        exit;
    }
}

function isAdmin(): bool {
    ensureSessionStarted();
    $usuario = $_SESSION['usuario'] ?? null;
    return $usuario && $usuario['rol'] === 'administrador';
}

/**
 * Actualiza la última actividad de la sesión actual en la BD.
 * Se llama en el header para mantener "en línea" al usuario.
 */
function refreshSessionActivity(mysqli $conn): void {
    ensureSessionStarted();
    $sessionToken = session_id();
    if (!$sessionToken) return;
    try {
        $stmt=$conn->prepare('UPDATE sesiones_activas SET ultima_actividad=NOW() WHERE session_token=?');
        $stmt->bind_param('s',$sessionToken); $stmt->execute(); $stmt->close();
    } catch (mysqli_sql_exception $e) { error_log('Fontana: seguimiento de sesión no disponible; código '.$e->getCode()); }
}

/**
 * Retorna usuarios con sesión activa en los últimos 5 minutos.
 */
function getActiveUsers(mysqli $conn): array {
    $sql = "SELECT u.id_usuario, u.nombre, u.usuario, u.rol, s.ultima_actividad, s.ip
            FROM sesiones_activas s
            INNER JOIN usuarios u ON u.id_usuario = s.id_usuario
            WHERE s.ultima_actividad >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ORDER BY s.ultima_actividad DESC";
    try {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    } catch (mysqli_sql_exception $e) {
        error_log('Fontana: listado de sesiones no disponible; código '.$e->getCode()); return [];
    }
}

/**
 * Limpia sesiones inactivas (más de 30 min sin actividad).
 */
function cleanStaleSessions(mysqli $conn): void {
    try { $conn->query("DELETE FROM sesiones_activas WHERE ultima_actividad < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"); }
    catch (mysqli_sql_exception $e) { error_log('Fontana: limpieza de sesiones no disponible; código '.$e->getCode()); }
}

/**
 * Agrega las columnas usadas para identificar al cliente y el comprobante
 * cuando el proyecto se instala sobre una base de datos existente.
 */
function ensureSalesDocumentSchema(mysqli $conn): bool {

    try {
    $definitions = [
        'referencia_pago' => "ADD COLUMN referencia_pago VARCHAR(50) NULL AFTER metodo_pago",
        'tipo_comprobante' => "ADD COLUMN tipo_comprobante ENUM('boleta','factura') NOT NULL DEFAULT 'boleta' AFTER metodo_pago",
        'tipo_documento' => "ADD COLUMN tipo_documento ENUM('SIN_DOCUMENTO','DNI','RUC') NOT NULL DEFAULT 'SIN_DOCUMENTO' AFTER tipo_comprobante",
        'numero_documento' => "ADD COLUMN numero_documento VARCHAR(20) NULL AFTER tipo_documento",
        'cliente_nombre' => "ADD COLUMN cliente_nombre VARCHAR(180) NOT NULL DEFAULT 'CONSUMIDOR FINAL' AFTER numero_documento",
        'cliente_direccion' => "ADD COLUMN cliente_direccion VARCHAR(255) NULL AFTER cliente_nombre",
        'documento_consultado' => "ADD COLUMN documento_consultado TINYINT(1) NOT NULL DEFAULT 0 AFTER cliente_direccion",
        'api_proveedor' => "ADD COLUMN api_proveedor VARCHAR(50) NULL AFTER documento_consultado",
    ];

    $existingColumns = [];
    $result = $conn->query('SHOW COLUMNS FROM ventas');
    if (!$result) {
        error_log('Fontana - no se pudo revisar la tabla ventas: ' . $conn->error);
        $schemaReady = false;
        return false;
    }
    while ($row = $result->fetch_assoc()) {
        $existingColumns[$row['Field']] = true;
    }
    $result->free();

    $missingDefinitions = [];
    foreach ($definitions as $column => $definition) {
        if (!isset($existingColumns[$column])) {
            $missingDefinitions[] = $definition;
        }
    }

    if ($missingDefinitions) {
        $sql = 'ALTER TABLE ventas ' . implode(', ', $missingDefinitions);
        if (!$conn->query($sql)) {
            error_log('Fontana - no se pudo actualizar la tabla ventas: ' . $conn->error);
            $schemaReady = false;
            return false;
        }
    }

    $indexResult = $conn->query("SHOW INDEX FROM ventas WHERE Key_name = 'idx_ventas_documento'");
    $indexExists = $indexResult && $indexResult->num_rows > 0;
    if ($indexResult) {
        $indexResult->free();
    }
    if (!$indexExists) {
        try { $conn->query('CREATE INDEX idx_ventas_documento ON ventas (tipo_documento, numero_documento)'); }
        catch (mysqli_sql_exception $e) { error_log('Fontana: índice opcional de documentos pendiente; código '.$e->getCode()); }
    }

    $schemaReady = true;
    return true;
    } catch (Throwable $e) {
        error_log('Fontana: no se pudo verificar esquema de comprobantes; código '.$e->getCode());
        $schemaReady = false;
        return false;
    }
}

function saleDocumentLabel(string $documentType): string {
    $labels = [
        'SIN_DOCUMENTO' => 'Sin documento',
        'DNI' => 'DNI',
        'RUC' => 'RUC',
    ];
    return $labels[$documentType] ?? $documentType;
}

function saleReceiptLabel(string $receiptType): string {
    return strtolower($receiptType) === 'factura' ? 'Factura' : 'Boleta';
}

function csrfToken(): string {
    ensureSessionStarted();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token): bool {
    ensureSessionStarted();
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function isValidPeruvianRuc(string $ruc): bool {
    if (!preg_match('/^\d{11}$/', $ruc)) {
        return false;
    }

    $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $sum += ((int)$ruc[$i]) * $weights[$i];
    }

    $checkDigit = 11 - ($sum % 11);
    if ($checkDigit === 10) {
        $checkDigit = 0;
    } elseif ($checkDigit === 11) {
        $checkDigit = 1;
    }

    return $checkDigit === (int)$ruc[10];
}

/** Rechaza estructuras manipuladas antes de hacer trim o conversiones numéricas. */
function validarCamposTexto(array $source, array $fields): void {
    foreach ($fields as $field) {
        if (isset($source[$field]) && !is_string($source[$field])) {
            throw new InvalidArgumentException('El formulario contiene un campo no válido: '.$field.'. Recarga e inténtalo otra vez.');
        }
    }
}
