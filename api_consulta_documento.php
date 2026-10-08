<?php
session_start();
require 'includes/helpers.php';
require 'includes/api_documentos.php';
requireLogin();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'La sesión del formulario venció. Recarga la página e inténtalo nuevamente.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$documentType = strtoupper(trim($_POST['tipo'] ?? ''));
$documentNumber = preg_replace('/\D+/', '', (string)($_POST['numero'] ?? '')) ?? '';
$result = consultDocumentApi($documentType, $documentNumber);

if ($result['ok']) {
    $_SESSION['document_lookup'] = [
        'tipo_documento' => $result['data']['tipo_documento'],
        'numero_documento' => $result['data']['numero_documento'],
        'nombre' => $result['data']['nombre'],
        'direccion' => $result['data']['direccion'],
        'proveedor' => $result['data']['proveedor'],
        'expires_at' => time() + 600,
    ];
}

http_response_code((int)$result['status']);
echo json_encode([
    'ok' => $result['ok'],
    'message' => $result['message'],
    'data' => $result['data'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
