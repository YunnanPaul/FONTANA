<?php
// Endpoint público HTTPS. Sin sesión ni CSRF: autentica exclusivamente la firma HMAC.
require 'conexion.php';
require_once 'includes/izipay.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { http_response_code(405); echo '{"ok":false}'; exit; }
try {
    $raw=file_get_contents('php://input',false,null,0,262145);
    if (!is_string($raw) || strlen($raw)>262144) { http_response_code(413); echo '{"ok":false}'; exit; }
    $body=json_decode($raw,true,40,JSON_THROW_ON_ERROR);
    if (!is_array($body)) throw new InvalidArgumentException('Formato inválido.');
    // Algunos despliegues envían signature también como cabecera. Los bytes a
    // autenticar siguen siendo exactamente payloadHttp, no el cuerpo reconstruido.
    if (!isset($body['signature']) && isset($_SERVER['HTTP_SIGNATURE'])) $body['signature']=$_SERVER['HTTP_SIGNATURE'];
    izipayRecibir($conn,$body,'IPN',null,$_SERVER['HTTP_TRANSACTIONID'] ?? null);
    http_response_code(200); echo '{"ok":true,"code":"00"}';
} catch (InvalidArgumentException|JsonException|TypeError $e) {
    http_response_code(400); echo '{"ok":false,"message":"Notificacion no valida"}';
} catch (Throwable $e) {
    // HTTP 503 permite reintento del proveedor; no registrar información de tarjeta.
    error_log('Fontana: IPN pendiente de persistencia; '.get_class($e).'; código '.$e->getCode());
    http_response_code(503); echo '{"ok":false,"message":"Reintentar notificacion"}';
}
