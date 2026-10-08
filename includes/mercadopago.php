<?php
/** Mercado Pago Perú / Payments API. SOLO TEST-. No procesa producción.
 * El SDK del proveedor tokeniza tarjetas; este servidor no recibe PAN ni CVV.
 * Contrato: docs/FUENTES_PAGOS.md. No requiere Composer.
 */
require_once __DIR__.'/pagos.php';

final class MpHttpException extends RuntimeException {
    public int $httpStatus;
    public function __construct(string $message,int $status=0) { parent::__construct($message); $this->httpStatus=$status; }
}
function mpExigirOrden(array $o): void {
    if (($o['proveedor'] ?? '')!=='mercadopago' || !in_array($o['entorno'] ?? '',['sandbox','sandbox_venta'],true) ||
        !in_array($o['metodo'] ?? '',['Yape','Tarjeta'],true))
        throw new InvalidArgumentException('Esta orden no pertenece a Mercado Pago de pruebas.');
}
function mpClaves(?array $keys=null): array {
    $keys=$keys ?? pagoConfig()['mercadopago'];
    foreach (['access_token','public_key'] as $k)
        if (!preg_match('/^TEST-[A-Za-z0-9_-]{10,250}$/D',(string)($keys[$k] ?? '')))
            throw new RuntimeException('Faltan credenciales TEST- de Checkout API (Payments API). Esta versión bloquea las claves de producción y de Orders API.');
    return $keys;
}
function mpConfiguracionPublica(array $o): array {
    mpExigirOrden($o); $k=mpClaves();
    return ['public_key'=>$k['public_key'],'sdk'=>'https://sdk.mercadopago.com/js/v2',
        'amount'=>pagoDecimal((int)$o['total_centimos']),'metodo'=>$o['metodo'],'test_only'=>true];
}
/** Único cliente HTTP saliente. Origen fijo, TLS, sin redirects, sin secretos en logs. */
function mpHttp(string $method,string $path,?array $payload=null,?string $idempotency=null): array {
    $keys=mpClaves();
    if (!extension_loaded('curl')) throw new RuntimeException('Activa cURL en PHP para conectar con Mercado Pago.');
    if (!in_array($method,['GET','POST'],true) || !preg_match('~^/v1/payments(?:/[0-9]+|/search\?[A-Za-z0-9_.%=&-]+)?$~D',$path))
        throw new InvalidArgumentException('Ruta de API no permitida.');
    $headers=['Accept: application/json','Content-Type: application/json','Authorization: Bearer '.$keys['access_token']];
    if ($idempotency!==null) {
        if (!preg_match('/^[A-Za-z0-9-]{16,64}$/D',$idempotency)) throw new InvalidArgumentException('Idempotencia no válida.');
        $headers[]='X-Idempotency-Key: '.$idempotency;
    }
    $ch=curl_init('https://api.mercadopago.com'.$path);
    if ($ch===false) throw new RuntimeException('No se pudo iniciar la conexión segura.');
    $response=''; $tooBig=false;
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>max(8,min(18,(int)($keys['timeout_seconds'] ?? 18))),
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_USERAGENT=>'Fontana-Academico/1.0',
        CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$response,&$tooBig):int {
            if (strlen($response)+strlen($chunk)>1048576) { $tooBig=true; return 0; }
            $response.=$chunk; return strlen($chunk);
        }]);
    if ($payload!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $ok=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($ok===false || $tooBig) throw new MpHttpException('No se obtuvo respuesta segura. El resultado es desconocido; usa Consultar API antes de crear otra prueba.');
    try { $data=json_decode($response,true,64,JSON_THROW_ON_ERROR); }
    catch(JsonException $e) { throw new MpHttpException('Mercado Pago devolvió una respuesta no interpretable. Consulta el estado.', $code); }
    if (!is_array($data)) throw new MpHttpException('Respuesta incompleta del proveedor.', $code);
    if ($code<200 || $code>=300) {
        $messages=[400=>'El proveedor rechazó los datos de prueba. Revisa token, tarjeta/OTP y correo @testuser.com.',
            401=>'Mercado Pago no aceptó las credenciales de prueba.',403=>'La aplicación no tiene acceso a este recurso. Revisa país Perú, producto Payments API y credenciales TEST-.',
            404=>'El pago no se encontró en el proveedor.',409=>'La petición ya está en proceso o su idempotencia entró en conflicto.',
            422=>'El proveedor no aceptó los datos de prueba.',429=>'El proveedor limitó temporalmente las consultas. Espera antes de consultar de nuevo.'];
        throw new MpHttpException($messages[$code] ?? 'El proveedor no completó la solicitud. Consulta el estado antes de iniciar otra prueba.',$code);
    }
    return $data;
}
function mpImporteCentimos($value): int {
    if (is_float($value)) {
        if (!is_finite($value) || $value<0 || abs($value*100-round($value*100))>0.00001)
            throw new InvalidArgumentException('Importe del proveedor no válido.');
        $value=number_format($value,2,'.','');
    }
    return pagoCentimos($value);
}
function mpPayload(array $o,array $form): array {
    mpExigirOrden($o);
    foreach (['card_number','cardNumber','cvv','security_code','expiration_month','expiration_year'] as $forbidden)
        if (array_key_exists($forbidden,$form)) throw new InvalidArgumentException('Fontana no acepta datos de tarjeta sin tokenizar.');
    $token=pagoTexto($form['token'] ?? '',256,true);
    if (!preg_match('/^[A-Za-z0-9_-]{10,256}$/D',$token)) throw new InvalidArgumentException('Token de prueba no válido.');
    $method=pagoTexto($form['payment_method_id'] ?? '',30,true);
    if (!preg_match('/^[a-zA-Z0-9_-]{2,30}$/D',$method) || ($o['metodo']==='Yape' && $method!=='yape') || ($o['metodo']==='Tarjeta' && $method==='yape'))
        throw new InvalidArgumentException('El medio tokenizado no coincide con el elegido.');
    $payer=$form['payer'] ?? [];
    if (!is_array($payer)) throw new InvalidArgumentException('Pagador de prueba no válido.');
    $email=pagoTexto($payer['email'] ?? '',120,true);
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || !preg_match('/@testuser\.com$/iD',$email))
        throw new InvalidArgumentException('Para esta integración académica utiliza un correo de prueba terminado en @testuser.com.');
    $installments=$form['installments'] ?? 1;
    if ((!is_int($installments) && !is_string($installments)) || !preg_match('/^[1-9][0-9]?$/D',(string)$installments) || (int)$installments>12 || ($o['metodo']==='Yape' && (int)$installments!==1))
        throw new InvalidArgumentException('Número de cuotas de prueba no válido.');
    $p=['transaction_amount'=>(float)pagoDecimal((int)$o['total_centimos']), 'token'=>$token,
        'description'=>'FONTANA - PRUEBA ACADEMICA #'.$o['id_orden'], 'installments'=>(int)$installments,
        'payment_method_id'=>$method,'payer'=>['email'=>$email],'external_reference'=>$o['publico'],
        'metadata'=>['fontana_order'=>$o['publico'],'academic'=>true]];
    if ($o['metodo']==='Tarjeta' && !empty($form['issuer_id'])) {
        $issuer=pagoTexto($form['issuer_id'],20,true);
        if (!ctype_digit($issuer)) throw new InvalidArgumentException('Emisor no válido.');
        $p['issuer_id']=$issuer;
    }
    if ($o['metodo']==='Tarjeta' && isset($payer['identification'])) {
        $ident=$payer['identification'];
        if (!is_array($ident)) throw new InvalidArgumentException('Documento del pagador no válido.');
        $type=pagoTexto($ident['type'] ?? '',20,true); $num=pagoTexto($ident['number'] ?? '',20,true);
        if (!in_array($type,['DNI','CE','RUC','PAS','OTRO'],true) || !preg_match('/^[A-Za-z0-9]{4,20}$/D',$num))
            throw new InvalidArgumentException('Documento de prueba no válido.');
        $p['payer']['identification']=['type'=>$type,'number'=>$num];
    }
    // El webhook es opcional: localhost funciona consultando desde el backend.
    $base=(string)pagoConfig()['base_url'];
    if ($base!=='' && parse_url($base,PHP_URL_SCHEME)==='https' && !empty(pagoConfig()['mercadopago']['webhook_secret'])) {
        require_once __DIR__.'/demo_pagos.php';
        $p['notification_url']=demoBaseUrl($base).'/webhook_mercadopago.php';
    }
    return $p;
}
/** Solo recibe respuestas obtenidas por el servidor, nunca un status del navegador. */
function mpValidarRespuesta(array $p,array $o): array {
    mpExigirOrden($o);
    if (!array_key_exists('live_mode',$p) || $p['live_mode']!==false)
        throw new InvalidArgumentException('La respuesta no está identificada como sandbox (live_mode=false). Se bloqueó su registro.');
    if (($p['external_reference'] ?? '')!==$o['publico'] || ($p['currency_id'] ?? '')!=='PEN' ||
        mpImporteCentimos($p['transaction_amount'] ?? '')!==(int)$o['total_centimos'])
        throw new InvalidArgumentException('La orden, moneda o importe no coincide con el registro de Fontana.');
    $id=pagoTexto($p['id'] ?? '',40,true);
    if (!preg_match('/^[1-9][0-9]{0,30}$/D',$id)) throw new InvalidArgumentException('Identificador del pago no válido.');
    $method=pagoTexto($p['payment_method_id'] ?? '',30,true);
    if (($o['metodo']==='Yape' && $method!=='yape') || ($o['metodo']==='Tarjeta' &&
        ($method==='yape' || !in_array($p['payment_type_id'] ?? '',['credit_card','debit_card','prepaid_card'],true))))
        throw new InvalidArgumentException('El método confirmado no coincide con la orden.');
    $status=pagoTexto($p['status'] ?? '',40,true);
    if (!in_array($status,['approved','pending','in_process','authorized','in_mediation','rejected','cancelled','refunded','charged_back'],true))
        throw new InvalidArgumentException('Estado de pago no reconocido. Requiere revisión.');
    return ['id'=>$id,'status'=>$status,'detail'=>pagoTexto($p['status_detail'] ?? '',120),'method'=>$method];
}
function mpAplicarRespuesta(mysqli $db,array $pre,array $payment,string $source): array {
    $verified=mpValidarRespuesta($payment,$pre);
    pagoBegin($db);
    try {
        [$o,$box]=pagoBloquear($db,$pre); $id=(int)$o['id_orden'];
        $attempt=pagoUno($db,'SELECT * FROM pagos_mercadopago WHERE id_orden=? FOR UPDATE',[$id]);
        if (!$attempt) throw new InvalidArgumentException('No existe un intento de pago para esta orden.');
        if ($attempt['pago_id']!==null && $attempt['pago_id']!==$verified['id']) throw new InvalidArgumentException('Otro pago está vinculado a esta orden.');
        $status=$verified['status'];
        // Una respuesta vieja pending/rejected no revierte una prueba aprobada.
        if ((int)$o['pago_verificado'] && !in_array($status,['refunded','charged_back'],true)) { $db->commit(); return $o; }
        pagoExec($db,'UPDATE pagos_mercadopago SET pago_id=?,estado=?,detalle=?,consultado=NOW() WHERE id_orden=?',[$verified['id'],$status,$verified['detail'],$id]);
        pagoEvento($db,$id,'mp_'.$status,'Mercado Pago de PRUEBAS consultado por '.$source.'. Pago #'.$verified['id'].'; '.$verified['detail'].'.',null,
            hash('sha256','mp:'.$id.':'.$verified['id'].':'.$status.':'.$verified['detail']));
        if ($status==='approved') {
            if ($o['estado']==='cancelado') {
                pagoExec($db,'UPDATE pagos_ordenes SET alerta=? WHERE id_orden=?',['El proveedor aprobó una prueba después de su cancelación local. No se creó ninguna venta. Revisa el panel de pruebas.',$id]);
            } else {
                $ref='MP-TEST-'.$verified['id'];
                pagoExec($db,'UPDATE pagos_ordenes SET pago_verificado=1,referencia=?,autorizacion=?,metodo_proveedor=?,alerta=NULL WHERE id_orden=?',[$ref,'MP-'.$verified['id'],$verified['method'],$id]);
                $o['pago_verificado']=1; $o['referencia']=$ref;
                pagoMaterializar($db,$o,$box);
            }
        } elseif (in_array($status,['refunded','charged_back'],true)) {
            pagoExec($db,'UPDATE pagos_ordenes SET alerta=? WHERE id_orden=?',['El proveedor informó una reversión de PRUEBA. No se modifica inventario automáticamente.',$id]);
        } elseif ($o['estado']!=='cancelado') {
            $state=$status==='rejected' ? 'rechazado' : ($status==='cancelled' ? 'cancelado' : 'en_proceso');
            $note=$status==='rejected' ? 'El proveedor rechazó la prueba: '.$verified['detail'].'. Prepara una nueva orden para otro intento.' : null;
            pagoExec($db,'UPDATE pagos_ordenes SET estado=?,alerta=? WHERE id_orden=?',[$state,$note,$id]);
        }
        $db->commit(); return pagoOrden($db,$o['publico']);
    } catch(Throwable $e) { $db->rollback(); throw $e; }
}
/** $transport es inyección exclusivamente PHP para pruebas automáticas; nunca HTTP. */
function mpCrearPago(mysqli $db,array $pre,array $form,int $user,?callable $transport=null): array {
    mpExigirOrden($pre); mpClaves();
    $payload=mpPayload($pre,$form); $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
    $key='fontana-'.$pre['publico'];
    pagoBegin($db);
    try {
        [$o,$box]=pagoBloquear($db,$pre); $id=(int)$o['id_orden'];
        $attempt=pagoUno($db,'SELECT * FROM pagos_mercadopago WHERE id_orden=? FOR UPDATE',[$id]);
        if ($attempt) { $db->commit(); return pagoOrden($db,$o['publico']); } // Un único POST al proveedor por orden.
        if ($o['estado']!=='pendiente' || strtotime($o['reserva_hasta'])<=time()) throw new RuntimeException('La orden ya no admite un nuevo pago de prueba.');
        if ($box['estado']!=='abierta' || !$box['es_hoy']) throw new RuntimeException('La caja del día debe estar abierta.');
        pagoExec($db,'INSERT INTO pagos_mercadopago(id_orden,idempotencia,payload_hash) VALUES(?,?,?)',[$id,$key,$hash]);
        pagoExec($db,"UPDATE pagos_ordenes SET estado='en_proceso',alerta=NULL WHERE id_orden=?",[$id]);
        pagoEvento($db,$id,'mp_envio','Solicitud TEST preparada con importe del servidor e idempotencia estable. No se guardan tarjeta, CVV ni token.',$user);
        $db->commit();
    } catch(Throwable $e) { $db->rollback(); throw $e; }
    // No mantener bloqueos SQL ni de sesión durante la conexión de red.
    $send=$transport ?? 'mpHttp';
    try {
        $payment=$send('POST','/v1/payments',$payload,$key);
        return mpAplicarRespuesta($db,$o,$payment,'respuesta API');
    } catch(Throwable $e) {
        $badResponse=$e instanceof InvalidArgumentException;
        $note=$badResponse ? $e->getMessage() : ($e instanceof MpHttpException ? $e->getMessage() : 'No se pudo guardar o verificar el resultado. Usa Consultar API; no se repetirá el POST.');
        pagoExec($db,"UPDATE pagos_ordenes SET alerta=?,estado=IF(?=1,'revision',estado) WHERE id_orden=? AND pago_verificado=0 AND estado<>'cancelado'",[$note,$badResponse ? 1 : 0,$id]);
        pagoEvento($db,$id,'mp_resultado_desconocido',pagoTexto($note,450),$user);
        throw new RuntimeException($note);
    }
}
function mpConsultar(mysqli $db,array $pre,?callable $transport=null): array {
    mpExigirOrden($pre); mpClaves();
    $a=pagoUno($db,'SELECT * FROM pagos_mercadopago WHERE id_orden=?',[(int)$pre['id_orden']]);
    if (!$a) return pagoOrden($db,$pre['publico']);
    // Limitar consultas desde varias pestañas y notificaciones repetidas.
    $claimed=pagoExec($db,'UPDATE pagos_mercadopago SET consultado=NOW() WHERE id_orden=? AND (consultado IS NULL OR consultado<DATE_SUB(NOW(),INTERVAL 5 SECOND))',[(int)$pre['id_orden']]);
    if (!$claimed) return pagoOrden($db,$pre['publico']);
    $send=$transport ?? 'mpHttp';
    if ($a['pago_id']) $p=$send('GET','/v1/payments/'.rawurlencode($a['pago_id']),null,null);
    else {
        $data=$send('GET','/v1/payments/search?external_reference='.rawurlencode($pre['publico']).'&sort=date_created&criteria=desc&limit=2',null,null);
        if (!isset($data['results']) || !is_array($data['results'])) throw new RuntimeException('La búsqueda del proveedor no devolvió una lista válida.');
        if (!$data['results']) {
            throw new RuntimeException('La API aún no encuentra un pago para esta orden. No se repetirá automáticamente el cobro; consulta de nuevo o cancela esta prueba.');
        }
        if (count($data['results'])>1) throw new RuntimeException('Se encontraron varios pagos para la misma referencia. Revisa el panel de pruebas antes de continuar.');
        $p=$data['results'][0];
    }
    return mpAplicarRespuesta($db,$pre,$p,'consulta de estado');
}
/** Firma del webhook: timestamp y request-id se firman; repetición es idempotente.
 * No se impone caducidad al timestamp: el proveedor puede reintentar notificaciones.
 */
function mpVerificarFirma(string $signature,string $requestId,string $dataId,string $secret): bool {
    if ($secret==='' || strlen($secret)>500 || !preg_match('/^[0-9]{1,31}$/D',$dataId) ||
        !preg_match('/^[A-Za-z0-9_-]{1,200}$/D',$requestId) || strlen($signature)>1000) return false;
    $ts=null; $hashes=[];
    foreach (explode(',',$signature) as $part) {
        $kv=explode('=',trim($part),2); if (count($kv)!==2) continue;
        if ($kv[0]==='ts') { if ($ts!==null) return false; $ts=$kv[1]; }
        if ($kv[0]==='v1') $hashes[]=$kv[1];
    }
    if ($ts===null || !preg_match('/^[0-9]{1,16}$/D',$ts)) return false;
    $expected=hash_hmac('sha256','id:'.strtolower($dataId).';request-id:'.$requestId.';ts:'.$ts.';',$secret);
    foreach ($hashes as $hash) if (preg_match('/^[a-fA-F0-9]{64}$/D',$hash) && hash_equals($expected,strtolower($hash))) return true;
    return false;
}
