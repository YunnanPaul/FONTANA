<?php
/** Fixtures de interfaz, solo CLI y sin DB. NUNCA son comprobantes ni pagos reales. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/izipay.php';
$dest=$argv[1] ?? sys_get_temp_dir().'/fontana-vistas';
if (!is_dir($dest) && !mkdir($dest,0700,true)) throw new RuntimeException('No se pudo crear el destino.');
session_start(); date_default_timezone_set('America/Lima');
$_SESSION['usuario']=['id_usuario'=>1,'nombre'=>'Usuario de prueba','usuario'=>'prueba','rol'=>'administrador'];
foreach (['efectivo'=>'Efectivo','yape'=>'Yape','plin'=>'Plin','tarjeta'=>'Tarjeta'] as $slug=>$method) {
    $o=['id_orden'=>101,'id_usuario'=>1,'publico'=>str_repeat('a',32),'entorno'=>$method==='Efectivo' ? 'manual' : 'sandbox',
        'proveedor'=>$method==='Efectivo' ? 'manual' : 'izipay','metodo'=>$method,'total_centimos'=>1750,'estado'=>'pendiente','id_venta'=>null,
        'pago_verificado'=>0,'referencia'=>null,'alerta'=>null,'vuelto_centimos'=>null,'billing_json'=>null,
        'numero_externo'=>'172356538519500','reserva_hasta'=>date('Y-m-d H:i:s',time()+3600)];
    $receipt=['cliente_nombre'=>'CLIENTE DE PRUEBA','tipo_comprobante'=>'boleta','tipo_documento'=>'DNI','numero_documento'=>'12345678'];
    $billing=['firstName'=>'Cliente','lastName'=>'De Prueba','email'=>'prueba@example.com','phoneNumber'=>'999999999','street'=>'Dirección de prueba 100','city'=>'Lima','state'=>'Lima','postalCode'=>'15001','documentType'=>'DNI','document'=>'12345678'];
    $lineas=[['nombre'=>'Producto de prueba A','cantidad'=>2,'precio_centimos'=>500,'subtotal_centimos'=>1000],['nombre'=>'Producto de prueba B','cantidad'=>3,'precio_centimos'=>250,'subtotal_centimos'=>750]];
    $events=[['creado'=>date('Y-m-d H:i:s'),'tipo'=>'orden_creada','nota'=>'VISTA DE PRUEBA: no es una venta ni una operación bancaria.']];
    $status=pagoEstadoPublico($o); $pageTitle='Cobrar pedido #101 · vista de prueba';$activePage='pagos';
    ob_start(); include __DIR__.'/../includes/header.php'; include __DIR__.'/../includes/pago_vista.php'; include __DIR__.'/../includes/footer.php';
    file_put_contents($dest.'/'.$slug.'.html',ob_get_clean());
}
// Fixture de ventas: conserva el HTML/JS del formulario real, sin ejecutar su controlador SQL.
$source=file_get_contents(__DIR__.'/../ventas.php');
$start=strpos($source,"include 'includes/header.php';");
$source=substr($source,$start);
$source=str_replace("include 'includes/header.php';","include ".var_export(__DIR__.'/../includes/header.php',true).";",$source);
$source=str_replace("include 'includes/venta_resultado.php';","include ".var_export(__DIR__.'/../includes/venta_resultado.php',true).";",$source);
$source=str_replace("include 'includes/venta_cobro.php';","include ".var_export(__DIR__.'/../includes/venta_cobro.php',true).";",$source);
$source=str_replace("include 'includes/footer.php';","include ".var_export(__DIR__.'/../includes/footer.php',true).";",$source);
$draft=[];$pagosAcademicosReady=true;$errorConfigPago='';$configPagoVista=pagoConfig();$schemaReady=true;$pagosReady=true;$apiConfigured=true;$cajaAbierta=['id_cierre'=>1];$csrfToken=csrfToken();$formKey=str_repeat('b',64);$metodosPago=paymentMethods();
$productos=[['id_producto'=>1,'nombre'=>'Producto de prueba A','precio'=>'5.00','stock'=>10],['id_producto'=>2,'nombre'=>'Producto de prueba B','precio'=>'2.50','stock'=>20]];
$ultimasVentas=[];$pageTitle='Registrar venta · vista de prueba';$activePage='ventas';
ob_start();eval($source);file_put_contents($dest.'/ventas.html',ob_get_clean());
$pagosReady=false;$pagosAcademicosReady=false;
ob_start();eval($source);file_put_contents($dest.'/ventas_base_original.html',ob_get_clean());
$pagosReady=true;$pagosAcademicosReady=true;
$draft=['id_producto'=>['1','2'],'cantidad'=>['2','3'],'tipo_comprobante'=>'factura','numero_documento'=>'20131312955','cliente_nombre'=>'CLIENTE PRUEBA','cliente_direccion'=>'DIRECCIÓN DE PRUEBA','metodo_pago'=>'Plin','flujo_pago'=>'normal','referencia_pago'=>'TEST123'];
ob_start();eval($source);file_put_contents($dest.'/ventas_borrador.html',ob_get_clean());
// Modal de cobro: importe preparado por servidor y sin ninguna escritura.
foreach (['Yape','Plin','Tarjeta','Efectivo'] as $method) {
    $key=str_repeat('c',64);
    $draft=['id_producto'=>['1','2'],'cantidad'=>['2','3'],'tipo_comprobante'=>'boleta','metodo_pago'=>$method,'cliente_nombre'=>'CONSUMIDOR FINAL'];
    $_SESSION['ventas_por_cobrar'][$key]=['clave'=>$key,'id_usuario'=>1,'id_cierre'=>1,'metodo'=>$method,'total_centimos'=>1750,'vence'=>time()+900,'recibido'=>'','referencia_pago'=>'','borrador'=>$draft];
    $_GET['cobro']=$key;
    ob_start();eval($source);file_put_contents($dest.'/cobro_'.strtolower($method).'.html',ob_get_clean());
}
$_SESSION['ventas_por_cobrar'][$key]['vence']=time()-10;
ob_start();eval($source);file_put_contents($dest.'/cobro_vencido.html',ob_get_clean());
unset($_GET['cobro'],$_SESSION['ventas_por_cobrar']);
$_SESSION['pago_confirmar']=['venta'=>77,'metodo'=>'Yape','total'=>17.50,'referencia'=>'OPER123','recibido_centimos'=>null,'vuelto_centimos'=>null];
ob_start();eval($source);file_put_contents($dest.'/venta_confirmada.html',ob_get_clean());
echo "Vistas de prueba generadas en $dest\n";
