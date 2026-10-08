<?php
session_start(); require 'conexion.php'; require_once 'includes/izipay.php'; requireLogin();
header('Cache-Control: no-store, private'); header('Referrer-Policy: same-origin');
if (!pagoSchemaReady($conn)) { header('Location: pagos.php'); exit; }
try { $o=pagoOrden($conn,(string)($_GET['orden'] ?? ''),currentUser()); }
catch(InvalidArgumentException $e) { setFlash('error',$e->getMessage()); header('Location: pagos.php'); exit; }
$lineas=pagoTodos($conn,'SELECT * FROM pagos_lineas WHERE id_orden=? ORDER BY id_linea',[(int)$o['id_orden']]);
$events=pagoTodos($conn,'SELECT * FROM pagos_eventos WHERE id_orden=? ORDER BY id_evento DESC LIMIT 12',[(int)$o['id_orden']]);
$receipt=json_decode($o['comprobante_json'],true,32,JSON_THROW_ON_ERROR);
$billing=$o['billing_json'] ? json_decode($o['billing_json'],true,32,JSON_THROW_ON_ERROR) : [];
if (!$billing && $receipt['tipo_documento']==='DNI') $billing=['documentType'=>'DNI','document'=>$receipt['numero_documento']];
$status=pagoEstadoPublico($o); $pageTitle='Cobrar pedido #'.$o['id_orden']; $activePage='pagos';
include 'includes/header.php';
include 'includes/pago_vista.php';
include 'includes/footer.php';
