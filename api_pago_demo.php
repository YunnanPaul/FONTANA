<?php
/** Endpoint público de capacidades: exclusivamente simulaciones académicas. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private'); header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
function demoRespuesta(array $data,int $code=200): void { http_response_code($code); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') demoRespuesta(['ok'=>false,'message'=>'Usa POST.'],405);
require 'conexion.php'; require_once 'includes/demo_pagos.php';
try {
    $raw=file_get_contents('php://input',false,null,0,8193);
    if ($raw===false || strlen($raw)>8192) demoRespuesta(['ok'=>false,'message'=>'Solicitud demasiado grande.'],413);
    $input=json_decode($raw,true,10,JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_string($input['token'] ?? null)) throw new InvalidArgumentException('Falta el enlace de prueba.');
    if (!pagoAcademicoSchemaReady($conn)) demoRespuesta(['ok'=>false,'message'=>'El servicio de pago no está disponible todavía.'],503);
    $o=demoOrdenPorToken($conn,$input['token']);
    $action=$input['accion'] ?? 'consultar';
    if ($action==='resolver') {
        // La capacidad aleatoria funciona como autorización/anti-CSRF de alcance mínimo.
        $o=demoResolver($conn,$o,pagoTexto($input['resultado'] ?? '',20,true),null,$input);
    } elseif ($action!=='consultar') throw new InvalidArgumentException('Acción no válida.');
    demoRespuesta(['ok'=>true,'orden'=>demoEstadoMovil($o)]);
} catch (mysqli_sql_exception $e) { error_log('Fontana demo: SQL '.$e->getCode()); demoRespuesta(['ok'=>false,'message'=>'No se pudo consultar la base de datos de pruebas.'],503); }
catch (InvalidArgumentException|RuntimeException $e) { demoRespuesta(['ok'=>false,'message'=>$e->getMessage()],409); }
catch (JsonException|TypeError $e) { demoRespuesta(['ok'=>false,'message'=>'Formato de solicitud no válido.'],400); }
catch(Throwable $e) { error_log('Fontana demo: '.get_class($e)); demoRespuesta(['ok'=>false,'message'=>'No se pudo completar la prueba. Consulta al cajero antes de repetirla.'],503); }
