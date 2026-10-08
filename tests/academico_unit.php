<?php
/** Tests puros: sin red, sin MySQL, sin credenciales personales. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/demo_pagos.php';
require_once __DIR__.'/../includes/mercadopago.php';
date_default_timezone_set('America/Lima');
$passed=0; $failed=0;
function test(string $name,callable $fn): void { global $passed,$failed; try { $fn(); $passed++; echo "OK  $name\n"; } catch(Throwable $e) { $failed++; echo "FAIL $name: ".$e->getMessage()."\n"; } }
function equal($a,$b): void { if ($a!==$b) throw new RuntimeException('Resultado distinto: '.var_export($a,true)); }
function denied(callable $fn): void { try { $fn(); } catch(InvalidArgumentException|RuntimeException $e) { return; } throw new Exception('Se esperaba rechazo.'); }
$o=['id_orden'=>12,'publico'=>str_repeat('a',32),'proveedor'=>'mercadopago','entorno'=>'sandbox','metodo'=>'Yape','total_centimos'=>1750,
    'estado'=>'pendiente','reserva_hasta'=>date('Y-m-d H:i:s',time()+600),'id_venta'=>null,'pago_verificado'=>0,'referencia'=>null,'alerta'=>null,'vuelto_centimos'=>null];
$form=['token'=>str_repeat('b',32),'payment_method_id'=>'yape','installments'=>1,'payer'=>['email'=>'test_user_fontana@testuser.com']];
$payment=['id'=>12345,'live_mode'=>false,'external_reference'=>$o['publico'],'currency_id'=>'PEN','transaction_amount'=>17.5,
    'status'=>'approved','status_detail'=>'accredited','payment_method_id'=>'yape','payment_type_id'=>'debit_card'];
test('Modo inicial demo sin llaves',fn()=>equal(pagoConfig()['mode'],'demo'));
foreach(['Yape','Plin','Tarjeta','Efectivo'] as $method) test('Ruta demo '.$method,fn()=>equal(pagoRuta($method,['mode'=>'demo']),['demo','demo']));
foreach(['Yape','Tarjeta'] as $method) test('Ruta API externa '.$method,fn()=>equal(pagoRuta($method,['mode'=>'mercadopago_sandbox']),['mercadopago','sandbox']));
foreach(['Plin','Efectivo'] as $method) test('Ruta explícitamente simulada '.$method,fn()=>equal(pagoRuta($method,['mode'=>'mercadopago_sandbox']),['demo','demo']));
test('Modo manual conserva efectivo',fn()=>equal(pagoRuta('Efectivo',['mode'=>'production']),['manual','manual']));
test('Modo Izipay compatible',fn()=>equal(pagoRuta('Yape',['mode'=>'sandbox']),['izipay','sandbox']));
test('API usa importe del servidor',function()use($o,$form){$f=$form;$f['transaction_amount']=0.01;$f['total_centimos']=1;equal(mpPayload($o,$f)['transaction_amount'],17.5);});
test('API referencia la orden inmutable',fn()=>equal(mpPayload($o,$form)['external_reference'],$o['publico']));
test('Metadata identifica prueba',fn()=>equal(mpPayload($o,$form)['metadata']['academic'],true));
test('No usa estado inyectado en formulario',function()use($o,$form){$f=$form;$f['status']='approved';equal(array_key_exists('status',mpPayload($o,$f)),false);});
foreach(['card_number','cardNumber','cvv','security_code','expiration_month','expiration_year'] as $field)
    test('Bloquea datos bancarios crudos: '.$field,function()use($o,$form,$field){$f=$form;$f[$field]='sensitive';denied(fn()=>mpPayload($o,$f));});
foreach(['persona@gmail.com','otro@example.com','test_user@testuser.com.evil','bad'] as $email)
    test('Exige correo de prueba '.$email,function()use($o,$form,$email){$f=$form;$f['payer']['email']=$email;denied(fn()=>mpPayload($o,$f));});
foreach([0,13,'1.1',[],true] as $value) test('Valida cuotas '.json_encode($value),function()use($o,$form,$value){$f=$form;$f['installments']=$value;denied(fn()=>mpPayload($o,$f));});
test('Yape exige una cuota',function()use($o,$form){$f=$form;$f['installments']=2;denied(fn()=>mpPayload($o,$f));});
test('No permite Plin en API no integrada',function()use($o,$form){$a=$o;$a['metodo']='Plin';denied(fn()=>mpPayload($a,$form));});
test('No cruza método Yape y tarjeta',function()use($o,$form){$f=$form;$f['payment_method_id']='visa';denied(fn()=>mpPayload($o,$f));});
test('Token no puede contener HTML',function()use($o,$form){$f=$form;$f['token']='<script>danger</script>';denied(fn()=>mpPayload($o,$f));});
test('Credenciales TEST aceptadas',fn()=>equal(mpClaves(['access_token'=>'TEST-1234567890abcdefgh','public_key'=>'TEST-abcdefgh1234567890'])['public_key'],'TEST-abcdefgh1234567890'));
foreach(['APP_USR-12345678901234','','Bearer TEST-abcd1234567890'] as $key)
    test('Credencial no TEST bloqueada '.substr($key,0,7),fn()=>denied(fn()=>mpClaves(['access_token'=>$key,'public_key'=>'TEST-12345678901234'])));
test('Credencial pública producción bloqueada',fn()=>denied(fn()=>mpClaves(['access_token'=>'TEST-12345678901234','public_key'=>'APP_USR-12345678901234'])));
test('Aprobación test correcta',fn()=>equal(mpValidarRespuesta($payment,$o)['status'],'approved'));
foreach(['live_mode'=>true,'currency_id'=>'USD','transaction_amount'=>0.01,'external_reference'=>str_repeat('c',32),'id'=>'bad','payment_method_id'=>'visa','status'=>'success'] as $field=>$val)
    test('Rechaza respuesta incompatible '.$field,function()use($payment,$o,$field,$val){$p=$payment;$p[$field]=$val;denied(fn()=>mpValidarRespuesta($p,$o));});
test('live_mode ausente NO es sandbox',function()use($payment,$o){$p=$payment;unset($p['live_mode']);denied(fn()=>mpValidarRespuesta($p,$o));});
test('live_mode cero no sustituye booleano false',function()use($payment,$o){$p=$payment;$p['live_mode']=0;denied(fn()=>mpValidarRespuesta($p,$o));});
foreach(['pending','in_process','authorized','in_mediation','rejected','cancelled','refunded','charged_back'] as $status)
    test('Reconoce estado '.$status,function()use($payment,$o,$status){$p=$payment;$p['status']=$status;equal(mpValidarRespuesta($p,$o)['status'],$status);});
test('Respuesta tarjeta coherente',function()use($payment,$o){$p=$payment;$a=$o;$a['metodo']='Tarjeta';$p['payment_method_id']='visa';$p['payment_type_id']='credit_card';equal(mpValidarRespuesta($p,$a)['method'],'visa');});
test('Tarjeta no acepta ticket',function()use($payment,$o){$p=$payment;$a=$o;$a['metodo']='Tarjeta';$p['payment_method_id']='ticket';$p['payment_type_id']='ticket';denied(fn()=>mpValidarRespuesta($p,$a));});
foreach([17.501,NAN,INF,-0.1] as $amount) test('Rechaza importe API inválido '.var_export($amount,true),fn()=>denied(fn()=>mpImporteCentimos($amount)));
test('Decimal API sin pérdida de centavos',fn()=>equal(mpImporteCentimos(0.1+0.2),30));
$secret='test-only-secret';$ts='1760000000000';$rid='request-123';$id='12345';$hash=hash_hmac('sha256',"id:$id;request-id:$rid;ts:$ts;",$secret);$sig="ts=$ts,v1=$hash";
test('Firma webhook válida',fn()=>equal(mpVerificarFirma($sig,$rid,$id,$secret),true));
test('Firma admite reintentos antiguos idempotentes',fn()=>equal(mpVerificarFirma($sig,$rid,$id,$secret),true));
test('Firma con id alterado rechazada',fn()=>equal(mpVerificarFirma($sig,$rid,'12346',$secret),false));
test('Firma con request-id alterado rechazada',fn()=>equal(mpVerificarFirma($sig,'other',$id,$secret),false));
test('Firma con secreto incorrecto rechazada',fn()=>equal(mpVerificarFirma($sig,$rid,$id,'wrong'),false));
test('Firma sin secreto rechazada',fn()=>equal(mpVerificarFirma($sig,$rid,$id,''),false));
test('Firma vacía rechazada',fn()=>equal(mpVerificarFirma('',$rid,$id,$secret),false));
test('Firma con timestamp duplicado rechazada',fn()=>equal(mpVerificarFirma($sig.',ts=176',$rid,$id,$secret),false));
test('Firma inválida no acepta JSON aprobado',fn()=>equal(mpVerificarFirma('approved',$rid,$id,$secret),false));
test('Múltiples v1 permiten rotación de firma',fn()=>equal(mpVerificarFirma($sig.',v1='.str_repeat('0',64),$rid,$id,$secret),true));
foreach(['javascript:alert(1)','https://user:pass@example.com','https://example.com?a=b','https://example.com#token','not a url'] as $url)
    test('Rechaza URL de QR insegura '.$url,fn()=>denied(fn()=>demoBaseUrl($url)));
test('Admite IP de red local para celular',fn()=>equal(demoBaseUrl('http://192.168.1.20/fontana/'),'http://192.168.1.20/fontana'));
test('URL automática no necesita Internet',fn()=>equal(demoBaseUrl(''),''));
test('No simula una orden de API',fn()=>denied(fn()=>demoExigirOrden($o)));
foreach(['production','manual','sandbox'] as $env) test('Simulador bloquea entorno '.$env,function()use($o,$env){$a=$o;$a['proveedor']='demo';$a['entorno']=$env;denied(fn()=>demoExigirOrden($a));});
test('Vista móvil no expone PII',function()use($o){$a=$o;$a['cliente_nombre']='PRIVATE';$a['numero_documento']='PRIVATE';$s=demoEstadoMovil($a);equal(strpos(json_encode($s),'PRIVATE'),false);equal(isset($s['publico']),false);});
test('QR demo en proceso caduca',function()use($o){$a=$o;$a['proveedor']='demo';$a['entorno']='demo';$a['estado']='en_proceso';$a['reserva_hasta']=date('Y-m-d H:i:s',time()-1);equal(pagoEstadoPublico($a)['estado'],'vencido');});
test('Pago MP pendiente no se invalida por vencimiento local',function()use($o){$a=$o;$a['estado']='en_proceso';$a['reserva_hasta']=date('Y-m-d H:i:s',time()-1);equal(pagoEstadoPublico($a)['estado'],'en_proceso');});
test('Venta de base separada se identifica como prueba',function()use($o){$a=$o;$a['entorno']='sandbox_venta';$a['estado']='pagado';$a['id_venta']=1;equal(pagoEstadoPublico($a)['etiqueta'],'Venta de prueba registrada');});
echo "\nRESULTADO: $passed aprobadas; $failed fallidas. No se probó MySQL ni la cuenta de Mercado Pago.\n";
exit($failed ? 1 : 0);
