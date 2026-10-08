<?php
/** Webhook de pruebas opcional. Sin firma válida no consulta ni aprueba pagos. */
require 'conexion.php'; require_once 'includes/mercadopago.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
function mpWebhookOut(int $code,string $message): void { http_response_code($code); echo json_encode(['message'=>$message]); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') mpWebhookOut(405,'POST required');
try {
    $secret=(string)pagoConfig()['mercadopago']['webhook_secret'];
    if ($secret==='') mpWebhookOut(503,'Webhook not configured; use authenticated API polling');
    $raw=file_get_contents('php://input',false,null,0,65537);
    if ($raw===false || strlen($raw)>65536) mpWebhookOut(413,'Payload too large');
    $event=json_decode($raw,true,20,JSON_THROW_ON_ERROR);
    if (!is_array($event)) mpWebhookOut(400,'Invalid JSON');
    $id=$_GET['data_id'] ?? ''; // PHP normalizes query key data.id to data_id.
    if (!is_string($id) || !mpVerificarFirma((string)($_SERVER['HTTP_X_SIGNATURE'] ?? ''),(string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''),$id,$secret)) mpWebhookOut(401,'Invalid signature');
    if (($event['type'] ?? '')!=='payment') mpWebhookOut(200,'Topic ignored');
    if ((string)($event['data']['id'] ?? '')!==$id) mpWebhookOut(400,'Inconsistent resource');
    if (($event['live_mode'] ?? false)===true) mpWebhookOut(422,'Only test notifications supported');
    $payment=mpHttp('GET','/v1/payments/'.$id);
    $public=$payment['external_reference'] ?? '';
    if (!is_string($public) || !preg_match('/^[a-f0-9]{32}$/D',$public)) mpWebhookOut(200,'Unrelated payment ignored');
    $o=pagoUno($conn,"SELECT * FROM pagos_ordenes WHERE publico=? AND proveedor='mercadopago'",[$public]);
    if (!$o) mpWebhookOut(200,'Unrelated order ignored');
    mpAplicarRespuesta($conn,$o,$payment,'webhook verificado');
    mpWebhookOut(200,'Test payment synchronized');
} catch (InvalidArgumentException|JsonException|TypeError $e) { mpWebhookOut(400,'Payment notification not accepted'); }
catch(Throwable $e) { error_log('Fontana MP webhook: '.get_class($e)); mpWebhookOut(503,'Retry later'); }
