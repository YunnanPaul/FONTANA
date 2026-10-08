<?php
/** Pruebas del servicio real con almacenamiento EN MEMORIA. Ejecutar: php -n tests/cobro_unit.php */
require __DIR__.'/soporte_mysqli_doble.php';
require_once __DIR__.'/../includes/venta_cobro_core.php';
$_SESSION=[]; $pass=0;
function testCobro(bool $condition, string $name): void {
    global $pass;
    if (!$condition) throw new RuntimeException('FAIL '.$name);
    $pass++; echo 'OK '.$name."\n";
}
function rechazoCobro(callable $run, string $name): void {
    try { $run(); } catch (Throwable $e) { testCobro(true,$name); return; }
    testCobro(false,$name);
}
$receipt=['tipo_comprobante'=>'boleta','tipo_documento'=>'SIN_DOCUMENTO','numero_documento'=>'','cliente_nombre'=>'CONSUMIDOR FINAL','cliente_direccion'=>'','documento_consultado'=>0,'api_proveedor'=>null];
$d=new mysqli();$k=pagoNuevaClave();$lines=[1=>2,2=>3];
$pending=ventaPrepararCobro($d,$lines,'Yape',$receipt,1,1,$k,['total'=>'0.01']);
testCobro($pending['total_centimos']===1750,'El total sale de productos, no de un campo del navegador');
testCobro(!$d->sales && !$d->detail && !$d->movements,'Preparar el QR no registra una venta ni movimientos');
testCobro($d->products[1]['stock']===10 && $d->products[2]['stock']===20,'Preparar cobro conserva el stock');
testCobro($pending['precios']===[1=>500,2=>250],'Se guardan precios exactos para la comprobación final');
testCobro(isset($_SESSION['ventas_por_cobrar'][$k]),'La preparación queda aislada por clave en sesión');
testCobro($pending['vence']>time(),'La preparación tiene una vigencia limitada');
testCobro(count($pending['items'])===2,'Se conservan las dos líneas del pedido');
$pending2=ventaPrepararCobro($d,$lines,'Yape',$receipt,1,1,$k,[]);
testCobro(!$d->sales && $pending2['clave']===$k,'Repetir preparación no crea ninguna venta');
$result=ventaConfirmarPreparada($d,$k,1,['confirmado'=>'1','referencia_pago'=>'OPER-1','metodo_pago'=>'Tarjeta','total'=>'0.01','id_producto'=>[999],'_total_esperado'=>1]);
testCobro($result['total']===17.5 && $result['metodo']==='Yape','Confirmar ignora importe, método y productos manipulados');
testCobro(count($d->sales)===1 && count($d->detail)===2 && count($d->movements)===2,'Solo la confirmación crea venta, detalle e inventario');
testCobro($d->products[1]['stock']===8 && $d->products[2]['stock']===17,'Se descuenta el stock una sola vez al confirmar');
$again=ventaConfirmarPreparada($d,$k,1,['confirmado'=>'1']);
testCobro($again===$result && count($d->sales)===1,'Reenvío de confirmación devuelve el mismo resultado sin duplicar');
rechazoCobro(fn()=>ventaPrepararCobro($d,$lines,'Yape',$receipt,1,1,$k,[]),'No se vuelve a abrir un cobro ya registrado');
rechazoCobro(fn()=>ventaConfirmarPreparada($d,str_repeat('f',64),1,[]),'Confirmación sin pedido preparado rechazada');
rechazoCobro(fn()=>ventaConfirmarPreparada($d,$k,2,['confirmado'=>'1']),'Otra persona no puede confirmar el pedido de la sesión');
foreach (['precio','stock','caja','vencido','sin_confirmacion','detalle','movimiento'] as $case) {
    $db=new mysqli(); $key=pagoNuevaClave(); ventaPrepararCobro($db,$lines,'Plin',$receipt,1,1,$key,[]);
    $input=['confirmado'=>'1'];
    if ($case==='precio') $db->products[1]['precio']='5.01';
    if ($case==='stock') $db->products[2]['stock']=1;
    if ($case==='caja') $db->box['estado']='cerrada';
    if ($case==='vencido') $_SESSION['ventas_por_cobrar'][$key]['vence']=time()-1;
    if ($case==='sin_confirmacion') $input=[];
    if ($case==='detalle') $db->fail='INSERT INTO detalle_venta';
    if ($case==='movimiento') $db->fail='INSERT INTO movimientos_inventario';
    $before=$db->products;
    rechazoCobro(fn()=>ventaConfirmarPreparada($db,$key,1,$input),'Confirmación rechazada: '.$case);
    testCobro(!$db->sales && !$db->detail && !$db->movements && $db->products===$before,'Sin registros parciales: '.$case);
}
$db=new mysqli();$key=pagoNuevaClave();ventaPrepararCobro($db,$lines,'Efectivo',$receipt,1,1,$key,[]);
rechazoCobro(fn()=>ventaConfirmarPreparada($db,$key,1,['confirmado'=>'1','recibido'=>'17.49']),'No se permite confirmar efectivo insuficiente');
$r=ventaConfirmarPreparada($db,$key,1,['confirmado'=>'1','recibido'=>'20.00']);
testCobro($r['vuelto_centimos']===250,'Vuelto de S/ 2.50 comprobado en servidor');
foreach(['sin_clave','sin_producto','sin_stock','precio_cero','caja_cerrada','cantidad','metodo'] as $case) {
    $db=new mysqli();$key=pagoNuevaClave();$ll=$lines;$method='Yape';
    if($case==='sin_clave')$key=str_repeat('0',64);
    if($case==='sin_producto')unset($db->products[1]);
    if($case==='sin_stock')$db->products[1]['stock']=0;
    if($case==='precio_cero')$db->products[1]['precio']='0.00';
    if($case==='caja_cerrada')$db->box['estado']='cerrada';
    if($case==='cantidad')$ll=[1=>0];
    if($case==='metodo')$method='Otro';
    rechazoCobro(fn()=>ventaPrepararCobro($db,$ll,$method,$receipt,1,1,$key,[]),'Preparación rechazada: '.$case);
    testCobro(!$db->sales && !$db->detail && !$db->movements,'Preparación fallida no escribe: '.$case);
}
// Dos pestañas usan claves diferentes; no se mezclan sus importes ni clientes.
$db=new mysqli();$a=pagoNuevaClave();$b=pagoNuevaClave();
ventaPrepararCobro($db,[1=>1],'Yape',$receipt,1,1,$a,[]);
ventaPrepararCobro($db,[2=>2],'Tarjeta',$receipt,1,1,$b,[]);
$r1=ventaConfirmarPreparada($db,$a,1,['confirmado'=>'1']);$r2=ventaConfirmarPreparada($db,$b,1,['confirmado'=>'1']);
testCobro($r1['metodo']==='Yape' && $r2['metodo']==='Tarjeta' && count($db->sales)===2,'Dos pedidos preparados no se sobreescriben entre pestañas');
// No reemplazar un importe ya mostrado por un segundo formulario con la misma clave.
$db=new mysqli();$key=pagoNuevaClave();
$draft=['id_producto'=>['1'],'cantidad'=>['2'],'metodo_pago'=>'Yape','cliente_nombre'=>'CLIENTE ORIGINAL'];
ventaPrepararCobro($db,[1=>2],'Yape',$receipt,1,1,$key,$draft);
rechazoCobro(fn()=>ventaPrepararCobro($db,[1=>1],'Yape',$receipt,1,1,$key,$draft),'Otra pestaña no sobreescribe el importe de una preparación');
testCobro($_SESSION['ventas_por_cobrar'][$key]['total_centimos']===1000,'El importe mostrado permanece inmutable');
testCobro(!ventaVolverAlPedido($key,2),'Otra persona no cancela ni restaura el pedido');
testCobro(ventaVolverAlPedido($key,1),'Volver al pedido cancela la preparación de la sesión');
testCobro($_SESSION['venta_borrador']['id_producto']===['1'] && $_SESSION['venta_borrador']['cantidad']===['2'] && $_SESSION['venta_borrador']['cliente_nombre']==='CLIENTE ORIGINAL','Volver conserva carrito y cliente');
testCobro(!isset($_SESSION['ventas_por_cobrar'][$key]) && !isset($_SESSION['pago_formularios'][$key]),'Se invalida la ventana anterior, no solo se oculta');
$new=$_SESSION['venta_borrador']['idempotencia'];
testCobro($new!==$key && isset($_SESSION['pago_formularios'][$new]),'El carrito restaurado obtiene una clave nueva');
rechazoCobro(fn()=>ventaConfirmarPreparada($db,$key,1,['confirmado'=>'1']),'Una ventana vieja no confirma después de volver al pedido');
testCobro(!$db->sales && !$db->detail && !$db->movements,'Volver al pedido no crea ni borra movimientos de base');
echo "RESULTADO: $pass comprobaciones del cobro aprobadas con almacenamiento EN MEMORIA, sin MySQL.\n";
