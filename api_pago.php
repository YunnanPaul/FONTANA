<?php
session_start();
require 'conexion.php';
require_once 'includes/izipay.php';
require_once 'includes/demo_pagos.php';
require_once 'includes/mercadopago.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function pagoRespuesta(array $data,int $status=200): void {
    http_response_code($status); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}
try {
    $user=currentUser();
    if (!$user) pagoRespuesta(['ok'=>false,'message'=>'Tu sesión venció. Inicia sesión para consultar la orden; no vuelvas a cobrar.'],401);
    $active=pagoUno($conn,'SELECT id_usuario,nombre,usuario,rol,estado FROM usuarios WHERE id_usuario=?',[(int)$user['id_usuario']]);
    if (!$active || $active['estado']!=='activo') pagoRespuesta(['ok'=>false,'message'=>'Usuario no autorizado.'],403);
    $user=$active;
    if (!pagoSchemaReady($conn)) pagoRespuesta(['ok'=>false,'message'=>'El módulo de pagos requiere instalación.'],503);
    $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method==='GET') {
        $o=pagoOrden($conn,(string)($_GET['orden'] ?? ''),$user);
        session_write_close(); pagoRespuesta(['ok'=>true,'orden'=>pagoEstadoPublico($o)]);
    }
    if ($method!=='POST') pagoRespuesta(['ok'=>false,'message'=>'Método no permitido.'],405);
    $raw=file_get_contents('php://input',false,null,0,262145);
    if (!is_string($raw) || strlen($raw)>262144) pagoRespuesta(['ok'=>false,'message'=>'Solicitud demasiado grande.'],413);
    $input=json_decode($raw,true,40,JSON_THROW_ON_ERROR);
    if (!is_array($input) || !verifyCsrfToken($input['csrf_token'] ?? null)) pagoRespuesta(['ok'=>false,'message'=>'La sesión del formulario venció. Recarga la página.'],403);
    $o=pagoOrden($conn,pagoTexto($input['orden'] ?? '',32,true),$user);
    if (($input['accion'] ?? '')!=='demo_preparar') session_write_close(); // El QR conserva su enlace en la sesión del cajero; llamadas externas no bloquean la sesión.
    switch ($input['accion'] ?? '') {
        case 'demo_preparar':
            $demo=demoPreparar($conn,$o,(int)$user['id_usuario']);
            pagoRespuesta(['ok'=>true,'demo'=>$demo,'orden'=>pagoEstadoPublico(pagoOrden($conn,$o['publico']))]);
        case 'demo_resolver':
            $o=demoResolver($conn,$o,(string)($input['resultado'] ?? ''),(int)$user['id_usuario'], $input); break;
        case 'mp_configurar':
            pagoRespuesta(['ok'=>true,'checkout'=>mpConfiguracionPublica($o)]);
        case 'mp_pagar':
            if (!is_array($input['formData'] ?? null)) throw new InvalidArgumentException('Faltan los datos de prueba tokenizados.');
            $o=mpCrearPago($conn,$o,$input['formData'],(int)$user['id_usuario']); break;
        case 'mp_consultar':
            $o=mpConsultar($conn,$o); break;
        case 'preparar':
            if (!is_array($input['billing'] ?? null)) throw new InvalidArgumentException('Faltan los datos del pagador.');
            $checkout=izipayPreparar($conn,$o,$input['billing'],(int)$user['id_usuario']);
            pagoRespuesta(['ok'=>true,'checkout'=>$checkout]);
        case 'callback':
            if ($o['proveedor']!=='izipay') throw new InvalidArgumentException('Proveedor no compatible con este callback.');
            if (!is_array($input['respuesta'] ?? null)) throw new InvalidArgumentException('Respuesta de pago no válida.');
            $o=izipayRecibir($conn,$input['respuesta'],'callback',$o['publico']); break;
        case 'confirmar_manual':
            $o=pagoConfirmarManual($conn,$o,$input,(int)$user['id_usuario']); break;
        case 'cancelar':
            $o=pagoCancelar($conn,$o,$user,pagoTexto($input['nota'] ?? '',350),($input['sin_cobro'] ?? false)===true); break;
        case 'conciliar':
            $o=pagoConciliar($conn,$o,$user,pagoTexto($input['nota'] ?? '',350,true)); break;
        default: throw new InvalidArgumentException('Acción no válida.');
    }
    pagoRespuesta(['ok'=>true,'orden'=>pagoEstadoPublico($o)]);
} catch (mysqli_sql_exception $e) {
    error_log('Fontana: error SQL en API de pagos; código '.$e->getCode());
    pagoRespuesta(['ok'=>false,'message'=>'No se pudo guardar o consultar el estado. No repitas el cobro: revisa la orden y el panel del proveedor.'],503);
} catch (InvalidArgumentException|JsonException|TypeError $e) {
    pagoRespuesta(['ok'=>false,'message'=>$e instanceof InvalidArgumentException ? $e->getMessage() : 'La solicitud no tiene un formato válido.'],400);
} catch (RuntimeException $e) {
    pagoRespuesta(['ok'=>false,'message'=>$e->getMessage()],409);
} catch (Throwable $e) {
    error_log('Fontana: error interno de pagos; '.get_class($e));
    pagoRespuesta(['ok'=>false,'message'=>'No fue posible completar esta acción. Consulta el estado antes de volver a cobrar.'],500);
}
