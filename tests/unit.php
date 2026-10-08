<?php
/** Pruebas locales sin red, sin base y sin credenciales reales. Ejecutar: php tests/unit.php */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/izipay.php';
date_default_timezone_set('America/Lima');
$passed=0; $failed=0;
function check(string $name, callable $test): void {
    global $passed,$failed;
    try { $test(); echo "OK  $name\n"; $passed++; }
    catch(Throwable $e) { echo "FAIL $name: ".$e->getMessage()."\n"; $failed++; }
}
function eq($actual,$expected): void {
    if ($actual!==$expected) throw new RuntimeException('Resultado inesperado: '.var_export($actual,true));
}
function rejects(callable $fn): void {
    try { $fn(); } catch(InvalidArgumentException|JsonException $e) { return; }
    throw new RuntimeException('Se esperaba rechazo.');
}
foreach (['0'=>0,'0.01'=>1,'1.5'=>150,'17.50'=>1750,'99999999.99'=>9999999999] as $input=>$expected) check('Importe exacto '.$input,fn()=>eq(pagoCentimos((string)$input),$expected));
foreach (['-1','1.001','1e3','17,50','01.50','','NaN','1 000','100000000','+1','1.'] as $input) check('Rechaza importe '.$input,fn()=>rejects(fn()=>pagoCentimos($input)));
check('Rechaza float monetario',fn()=>rejects(fn()=>pagoCentimos(1.2)));
check('Decimales sin error binario',fn()=>eq(pagoCentimos('0.10')+pagoCentimos('0.20'),30));
check('Formato de importe',fn()=>eq(pagoDecimal(1750),'17.50'));
check('Agrupa duplicados y ordena bloqueos',fn()=>eq(pagoLineasValidas(['2','1','2'],['1','2','3']),[1=>2,2=>4]));
foreach ([['1'],['0'],['-1'],['1.9'],['1e2'],['100000']] as $q) {
    if($q===['1']) continue;
    check('Rechaza cantidad '.json_encode($q),fn()=>rejects(fn()=>pagoLineasValidas(['1'],$q)));
}
check('Rechaza listas disparejas',fn()=>rejects(fn()=>pagoLineasValidas(['1','2'],['1'])));
check('Rechaza producto vacío',fn()=>rejects(fn()=>pagoLineasValidas([''],['1'])));
check('Rechaza cantidad anidada',fn()=>rejects(fn()=>pagoLineasValidas(['1'],[['1']])));
check('Rechaza carrito vacío',fn()=>rejects(fn()=>pagoLineasValidas([],[])));
check('Unicode no se corta por bytes',fn()=>eq(pagoTexto(str_repeat('é',180),180),str_repeat('é',180)));
check('Longitud máxima de texto',fn()=>rejects(fn()=>pagoTexto(str_repeat('é',181),180)));
$secret='solo-prueba-no-es-una-credencial';
$order=['transaccion'=>'172356538519500','numero_externo'=>'1723565385195','merchant_code'=>'TESTMERCHANT','total_centimos'=>1750,'metodo'=>'Yape'];
$payload=['code'=>'00','transactionId'=>$order['transaccion'],'response'=>['payMethod'=>'QR','merchant'=>['merchantCode'=>'TESTMERCHANT'],'order'=>[['orderNumber'=>$order['numero_externo'],'currency'=>'PEN','amount'=>'17.50','payMethodAuthorization'=>'QR','uniqueId'=>'TEST-UNIQUE-001','referenceNumber'=>'TEST-REF-001']]]];
$raw=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$envelope=['payloadHttp'=>$raw,'signature'=>base64_encode(hash_hmac('sha256',$raw,$secret,true))];
check('HMAC válida sobre bytes originales',fn()=>eq(izipayPayloadFirmado($envelope,$secret),$payload));
check('QR aprobado y total exacto',fn()=>eq(izipayValidarAprobacion($payload,$order)['metodo'],'QR'));
check('Plin usa canal QR',function()use($payload,$order){ $o=$order;$o['metodo']='Plin';eq(izipayValidarAprobacion($payload,$o)['metodo'],'QR'); });
check('Tarjeta valida canal CARD',function()use($payload,$order){ $o=$order;$o['metodo']='Tarjeta';$p=$payload;$p['response']['payMethod']='CARD';$p['response']['order'][0]['payMethodAuthorization']='CARD';eq(izipayValidarAprobacion($p,$o)['metodo'],'CARD'); });
check('Firma errónea no confirma',function()use($envelope,$secret){$e=$envelope;$e['signature']='falsa';rejects(fn()=>izipayPayloadFirmado($e,$secret));});
check('Modificar importe firmado invalida HMAC',function()use($envelope,$secret){$e=$envelope;$e['payloadHttp']=str_replace('17.50','0.01',$e['payloadHttp']);rejects(fn()=>izipayPayloadFirmado($e,$secret));});
check('Cambiar espacios firmados invalida HMAC',function()use($envelope,$secret){$e=$envelope;$e['payloadHttp'].=' ';rejects(fn()=>izipayPayloadFirmado($e,$secret));});
check('No acepta secreto vacío',fn()=>rejects(fn()=>izipayPayloadFirmado($envelope,'')));
check('Campos externos no sustituyen datos firmados',function()use($envelope,$secret,$payload){$e=$envelope;$e['code']='99';$e['transactionId']='OTRA_TRANSACCION';$e['response']=['order'=>[['amount'=>'0.01']]];eq(izipayPayloadFirmado($e,$secret),$payload);});
foreach (['amount'=>'17.49','currency'=>'USD','orderNumber'=>'OTRA','uniqueId'=>'','payMethodAuthorization'=>'CARD'] as $field=>$value) {
    check('Rechaza aprobación incompatible: '.$field,function()use($payload,$order,$field,$value){$p=$payload;$p['response']['order'][0][$field]=$value;rejects(fn()=>izipayValidarAprobacion($p,$order));});
}
check('Rechaza otro comercio',function()use($payload,$order){$p=$payload;$p['response']['merchant']['merchantCode']='OTRO';rejects(fn()=>izipayValidarAprobacion($p,$order));});
check('Rechaza otra transacción',function()use($payload,$order){$p=$payload;$p['transactionId']='OTRA';rejects(fn()=>izipayValidarAprobacion($p,$order));});
check('Rechazo firmado no es aprobación',function()use($payload,$order){$p=$payload;$p['code']='05';rejects(fn()=>izipayValidarAprobacion($p,$order));});
check('Rechaza autorización múltiple',function()use($payload,$order){$p=$payload;$p['response']['order'][]=$p['response']['order'][0];rejects(fn()=>izipayValidarAprobacion($p,$order));});
check('Rechaza transacción ausente en JSON firmado',function()use($payload,$secret){$p=$payload;unset($p['transactionId']);$r=json_encode($p);rejects(fn()=>izipayPayloadFirmado(['payloadHttp'=>$r,'signature'=>base64_encode(hash_hmac('sha256',$r,$secret,true))],$secret));});
$state=['estado'=>'pendiente','reserva_hasta'=>date('Y-m-d H:i:s',time()-5),'total_centimos'=>1750,'id_venta'=>null,'pago_verificado'=>0,'referencia'=>null,'alerta'=>null,'metodo'=>'Yape','entorno'=>'sandbox','vuelto_centimos'=>null];
check('Reserva pendiente vencida no aparece cobrable',fn()=>eq(pagoEstadoPublico($state)['estado'],'vencido'));
check('No confunde vencimiento local con cancelación del QR',function()use($state){$s=$state;$s['estado']='en_proceso';eq(pagoEstadoPublico($s)['estado'],'en_proceso');});
check('Estado de prueba no es venta real',function()use($state){$s=$state;$s['estado']='prueba';eq(pagoEstadoPublico($s)['id_venta'],null);});
check('Dominios del SDK limitados',fn()=>rejects(fn()=>izipayHosts('https://sitio-ajeno.invalid')));
check('Modo configurado reconocido',fn()=>eq(in_array(pagoConfig()['mode'],['demo','mercadopago_sandbox','manual','sandbox','production'],true),true));
check('DNI y RUC originales disponibles',fn()=>eq(isValidPeruvianRuc('20100070970'),true));
echo "\nRESULTADO: $passed aprobadas; $failed fallidas. No se llamó al banco ni a MySQL.\n";
exit($failed ? 1 : 0);
