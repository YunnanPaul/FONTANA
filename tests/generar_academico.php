<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/mercadopago.php';
session_start(); date_default_timezone_set('America/Lima');
$_SESSION['usuario']=['id_usuario'=>1,'nombre'=>'Docente · usuario de prueba','usuario'=>'test','rol'=>'administrador'];
$dest=$argv[1] ?? sys_get_temp_dir().'/fontana-academico';
if (!is_dir($dest)) mkdir($dest,0700,true);
foreach (['demo_yape'=>['demo','Yape'],'demo_plin'=>['demo','Plin'],'demo_tarjeta'=>['demo','Tarjeta'],'demo_efectivo'=>['demo','Efectivo'],'mp_yape'=>['mercadopago','Yape'],'mp_tarjeta'=>['mercadopago','Tarjeta'],'demo_vencido'=>['demo','Yape']] as $file=>$pair) {
    [$provider,$method]=$pair;
    $o=['id_orden'=>101,'id_usuario'=>1,'publico'=>str_repeat('a',32),'entorno'=>$provider==='demo' ? 'demo' : 'sandbox','proveedor'=>$provider,
        'metodo'=>$method,'total_centimos'=>1750,'estado'=>'pendiente','id_venta'=>null,'pago_verificado'=>0,'referencia'=>null,'alerta'=>null,'vuelto_centimos'=>null,
        'billing_json'=>null,'numero_externo'=>'TEST12345','reserva_hasta'=>date('Y-m-d H:i:s',time()+($file==='demo_vencido' ? -30 : 900))];
    $receipt=['cliente_nombre'=>'CONSUMIDOR FINAL','tipo_comprobante'=>'boleta','tipo_documento'=>'SIN_DOCUMENTO','numero_documento'=>''];
    $lineas=[['nombre'=>'Producto de prueba A','cantidad'=>2,'precio_centimos'=>500,'subtotal_centimos'=>1000],['nombre'=>'Producto de prueba B','cantidad'=>3,'precio_centimos'=>250,'subtotal_centimos'=>750]];
    $events=[['creado'=>date('Y-m-d H:i:s'),'tipo'=>'orden_creada','nota'=>'Fixture de interfaz: no hay venta, base de datos ni operación bancaria.']];
    $status=pagoEstadoPublico($o);$billing=[];$pageTitle='Cobrar pedido #101';$activePage='pagos';
    ob_start();include __DIR__.'/../includes/header.php';include __DIR__.'/../includes/pago_vista.php';include __DIR__.'/../includes/footer.php';
    file_put_contents($dest.'/'.$file.'.html',ob_get_clean());
}
ob_start();include __DIR__.'/../pago_demo.php';file_put_contents($dest.'/pago_demo.html',ob_get_clean());
echo "Fixtures académicas: $dest\n";
