<?php
/** Adaptador Izipay Punto Web; no confundir con la API REST de Lyra.
 * Fuentes oficiales: https://developers.izipay.pe/web-core/quickstart/
 * https://developers.izipay.pe/web-core/modalidades/firma-validation/
 * https://developers.izipay.pe/web-core/notifications/
 */
require_once __DIR__ . '/pagos.php';
function izipayHosts(string $env): array {
    if ($env==='sandbox') return ['api'=>'https://sandbox-api-pw.izipay.pe', 'sdk'=>'https://sandbox-checkout.izipay.pe/payments/v1/js/index.js'];
    if ($env==='production') return ['api'=>'https://api-pw.izipay.pe','sdk'=>'https://checkout.izipay.pe/payments/v1/js/index.js'];
    throw new InvalidArgumentException('Entorno no permitido.');
}
function izipayBilling(array $input): array {
    $out=[];
    foreach (['firstName'=>50,'lastName'=>50,'email'=>100,'phoneNumber'=>15,'street'=>100,'city'=>50,'state'=>50,'postalCode'=>10,'document'=>15] as $field=>$max) {
        try { $out[$field]=pagoTexto($input[$field] ?? '',$max,true); }
        catch(InvalidArgumentException $e) { throw new InvalidArgumentException('Completa correctamente el dato del pagador: '.$field.'.'); }
    }
    if (!filter_var($out['email'],FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Ingresa un correo válido del pagador.');
    if (!preg_match('/^[0-9]{7,15}$/D',$out['phoneNumber'])) throw new InvalidArgumentException('El teléfono debe contener entre 7 y 15 dígitos.');
    $out['documentType']=pagoTexto($input['documentType'] ?? 'DNI',10,true);
    $patterns=['DNI'=>'/^[0-9]{8}$/D','RUC'=>'/^[0-9]{11}$/D','CE'=>'/^[a-zA-Z0-9]{9,12}$/D','PASAPORTE'=>'/^[a-zA-Z0-9]{5,15}$/D'];
    if (!isset($patterns[$out['documentType']]) || !preg_match($patterns[$out['documentType']],$out['document'])) throw new InvalidArgumentException('Documento del pagador no válido.');
    if ($out['documentType']==='RUC' && !isValidPeruvianRuc($out['document'])) throw new InvalidArgumentException('RUC del pagador no válido.');
    $out['country']='PE'; return $out;
}
/** La única llamada REST inicia una sesión; los datos de tarjeta se capturan en el SDK. */
function izipayGenerarToken(array $o): string {
    $keys=pagoCredenciales($o['entorno']);
    if (!extension_loaded('curl')) throw new RuntimeException('La extensión cURL no está disponible.');
    $body=json_encode(['requestSource'=>'ECOMMERCE','merchantCode'=>$o['merchant_code'],
        'orderNumber'=>$o['numero_externo'],'publicKey'=>$keys['api_key'],'amount'=>pagoDecimal((int)$o['total_centimos'])],JSON_THROW_ON_ERROR);
    $ch=curl_init(izipayHosts($o['entorno'])['api'].'/security/v1/Token/Generate');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','transactionId: '.$o['transaccion']],
        CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $error=curl_errno($ch); curl_close($ch);
    // No registrar cuerpo, cabeceras, token o credenciales en los logs.
    if ($error || !is_string($raw) || $status<200 || $status>=300) throw new RuntimeException('Izipay no entregó una sesión válida (HTTP '.$status.'). Verifica la operación en el panel antes de reintentar.');
    $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if ((string)($data['code'] ?? '') !== '00' || !is_string($data['response']['token'] ?? null) || $data['response']['token']==='') {
        $code=preg_replace('/[^a-zA-Z0-9_-]/','',(string)($data['code'] ?? 'sin_codigo'));
        throw new RuntimeException('Izipay rechazó la creación de sesión (código '.$code.'). Revisa las credenciales y habilitaciones de tu comercio.');
    }
    return $data['response']['token'];
}
function izipayPreparar(mysqli $db,array $pre,array $billing,int $user): array {
    if ($pre['proveedor'] !== 'izipay') throw new InvalidArgumentException('Esta orden usa verificación manual.');
    $errors=pagoConfigErrors($pre['entorno']);
    if ($errors) throw new RuntimeException(implode(' ',$errors));
    if (!hash_equals((string)$pre['merchant_code'],(string)pagoCredenciales($pre['entorno'])['merchant_code'])) throw new RuntimeException('El comercio configurado cambió. No se puede cobrar esta orden con otras credenciales.');
    pagoBegin($db); $needsToken=false;
    try {
        [$o,$box]=pagoBloquear($db,$pre);
        if ($o['estado']==='en_proceso' && $o['token_sesion']) {
            // Mismo identificador siempre: nunca crear otra orden por recargar.
            if (strtotime($o['token_creado']) < time()-600) throw new RuntimeException('La sesión de pago ya no se reabre por seguridad. Verifica su resultado en Pagos antes de crear otro cobro.');
            $billing=json_decode($o['billing_json'],true,32,JSON_THROW_ON_ERROR);
        } elseif ($o['estado']==='pendiente') {
            if (strtotime($o['reserva_hasta'])<=time()) throw new RuntimeException('La reserva venció. Crea una nueva orden; no se inició el cobro.');
            if ($box['estado'] !== 'abierta' || !$box['es_hoy']) throw new RuntimeException('La caja del día ya no está abierta.');
            $billing=izipayBilling($billing);
            pagoExec($db,"UPDATE pagos_ordenes SET estado='en_proceso',billing_json=?,alerta=NULL WHERE id_orden=?",[json_encode($billing,JSON_THROW_ON_ERROR),(int)$o['id_orden']]);
            pagoEvento($db,(int)$o['id_orden'],'sesion_solicitada','Solicitud de sesión; esto todavía no demuestra un pago.',$user);
            $needsToken=true;
        } else throw new RuntimeException('Esta orden ya está en proceso o resuelta. Consulta su estado, no repitas el cobro.');
        $db->commit();
    } catch(Throwable $e) { $db->rollback(); throw $e; }
    if ($needsToken) {
        try {
            $token=izipayGenerarToken($o);
            $n=pagoExec($db,"UPDATE pagos_ordenes SET token_sesion=?,token_creado=NOW() WHERE id_orden=? AND estado='en_proceso' AND pago_verificado=0",[$token,(int)$o['id_orden']]);
            if ($n!==1) throw new RuntimeException('La orden cambió durante la solicitud. Actualiza su estado.');
            $o['token_sesion']=$token;
        } catch(Throwable $e) {
            pagoExec($db,"UPDATE pagos_ordenes SET alerta=? WHERE id_orden=? AND estado='en_proceso' AND pago_verificado=0",['No se completó la preparación de la sesión. Revisión del administrador antes de otro intento.',(int)$o['id_orden']]);
            throw $e;
        }
    }
    $config=pagoConfig(); $keys=pagoCredenciales($o['entorno']);
    return ['sdk'=>izipayHosts($o['entorno'])['sdk'],'authorization'=>$o['token_sesion'],'keyRSA'=>$keys['rsa_public_key'],
        'config'=>[
            'transactionId'=>$o['transaccion'],'action'=>'pay','merchantCode'=>$o['merchant_code'],
            'order'=>['orderNumber'=>$o['numero_externo'],'currency'=>'PEN','amount'=>pagoDecimal((int)$o['total_centimos']),
                'payMethod'=>$o['metodo']==='Tarjeta' ? 'CARD' : 'QR','processType'=>'AT',
                'merchantBuyerId'=>'FNT'.substr($o['publico'],0,12),'dateTimeTransaction'=>$o['instante_ms']],
            'billing'=>$billing,'urlIPN'=>$config['base_url'].'/webhook_izipay.php',
        ]];
}
/** Firma sobre los bytes originales: nunca reconstruir payloadHttp. */
function izipayPayloadFirmado(array $envelope,string $secret): array {
    $payload=$envelope['payloadHttp'] ?? null; $signature=$envelope['signature'] ?? null;
    if (!is_string($payload) || !is_string($signature) || $secret==='' || strlen($payload)>200000 ||
        !hash_equals(base64_encode(hash_hmac('sha256',$payload,$secret,true)),$signature)) {
        throw new InvalidArgumentException('Firma de pago no válida.');
    }
    $data=json_decode($payload,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($data) || !is_string($data['transactionId'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{5,40}$/D',$data['transactionId'])) {
        throw new InvalidArgumentException('La respuesta firmada no contiene una transacción válida.');
    }
    return $data;
}
/** Pura: usada también por las pruebas de manipulación e importes. */
function izipayValidarAprobacion(array $data,array $o): array {
    if (($data['code'] ?? '') !== '00') throw new InvalidArgumentException('El proveedor no ha aprobado este pago.');
    $r=$data['response'] ?? []; $orders=$r['order'] ?? [];
    if (!is_array($orders) || count($orders)!==1 || !isset($orders[0]) || !is_array($orders[0])) throw new InvalidArgumentException('Detalle de autorización no válido.');
    $auth=$orders[0];
    foreach (['transactionId'=>$o['transaccion']] as $key=>$expected) if (($data[$key] ?? null)!==$expected) throw new InvalidArgumentException('La transacción no coincide.');
    if (($r['merchant']['merchantCode'] ?? null)!==$o['merchant_code']) throw new InvalidArgumentException('El comercio no coincide.');
    if (($auth['orderNumber'] ?? null)!==$o['numero_externo']) throw new InvalidArgumentException('La orden autorizada no coincide.');
    if (($auth['currency'] ?? null)!=='PEN') throw new InvalidArgumentException('La moneda autorizada no coincide.');
    if (pagoCentimos($auth['amount'] ?? null)!==(int)$o['total_centimos']) throw new InvalidArgumentException('El importe autorizado no coincide.');
    $method=(string)($auth['payMethodAuthorization'] ?? $r['payMethod'] ?? '');
    $expected=$o['metodo']==='Tarjeta' ? 'CARD' : 'QR';
    if ($method!==$expected || (($r['payMethod'] ?? $method)!==$expected)) throw new InvalidArgumentException('El método autorizado no coincide. Revisa el cobro en el panel; no cobres otra vez.');
    $uid=pagoTexto($auth['uniqueId'] ?? '',100,true);
    $reference=pagoTexto($auth['referenceNumber'] ?? $auth['codeAuth'] ?? $uid,100,true);
    return ['autorizacion'=>$uid,'referencia'=>$reference,'metodo'=>$method];
}
function izipayRecibir(mysqli $db,array $envelope,string $source,?string $expectedPublic=null,?string $headerTransaction=null): array {
    $config=pagoConfig(); $signed=null; $env=null;
    foreach (['sandbox','production'] as $candidate) {
        try { $signed=izipayPayloadFirmado($envelope,(string)$config['environments'][$candidate]['hash_key']); $env=$candidate; break; }
        catch(InvalidArgumentException|JsonException $e) { /* Ningún dato no firmado autoriza el pago. */ }
    }
    if (!$signed || !$env) throw new InvalidArgumentException('No se pudo autenticar la notificación de pago.');
    if ($headerTransaction!==null && $headerTransaction!=='' && !hash_equals($signed['transactionId'],$headerTransaction)) throw new InvalidArgumentException('Cabecera de transacción incompatible.');
    $pre=pagoUno($db,'SELECT * FROM pagos_ordenes WHERE transaccion=?',[$signed['transactionId']]);
    if (!$pre || $pre['entorno']!==$env || $pre['proveedor']!=='izipay' || ($expectedPublic!==null && $pre['publico']!==$expectedPublic)) throw new InvalidArgumentException('La notificación no corresponde a esta orden o entorno.');
    $fingerprint=hash('sha256',$envelope['payloadHttp']);
    pagoBegin($db);
    try {
        [$o,$box]=pagoBloquear($db,$pre); $id=(int)$o['id_orden'];
        // Callback e IPN repetidos comparten huella. No volver a descontar stock.
        $seen=pagoUno($db,'SELECT id_evento FROM pagos_eventos WHERE huella=?',[$fingerprint]);
        if ($seen) { $db->commit(); return pagoOrden($db,$o['publico']); }
        if (($signed['code'] ?? '')!=='00') {
            $code=pagoTexto($signed['code'] ?? 'sin_codigo',20);
            pagoEvento($db,$id,'respuesta_no_aprobada','Respuesta firmada no aprobada ('.$code.'). Mantener revisión hasta confirmar que la operación no puede pagarse.',null,$fingerprint);
            if (!$o['id_venta'] && !(int)$o['pago_verificado']) pagoExec($db,'UPDATE pagos_ordenes SET alerta=? WHERE id_orden=?',['Izipay no confirmó un pago aprobado ('.$code.'). No entregar el pedido; comprobar el estado antes de reintentar.',$id]);
        } else {
            try { $verified=izipayValidarAprobacion($signed,$o); }
            catch(InvalidArgumentException $e) {
                $note='Notificación auténtica requiere revisión: '.$e->getMessage();
                pagoEvento($db,$id,'aprobacion_incompatible',$note,null,$fingerprint);
                pagoExec($db,"UPDATE pagos_ordenes SET estado=IF(id_venta IS NULL,'revision',estado),alerta=? WHERE id_orden=?",[$note,$id]);
                $db->commit(); return pagoOrden($db,$o['publico']);
            }
            if ($o['autorizacion']!==null && $o['autorizacion']!==$verified['autorizacion']) {
                $note='Posible doble cobro: llegó otra autorización para esta orden. Revisar ambas operaciones en Izipay y gestionar devolución; no se creó otra venta.';
                pagoEvento($db,$id,'posible_doble_cobro',$note,null,$fingerprint);
                pagoExec($db,'UPDATE pagos_ordenes SET alerta=? WHERE id_orden=?',[$note,$id]);
            } else {
                $other=pagoUno($db,'SELECT id_orden FROM pagos_ordenes WHERE entorno=? AND merchant_code=? AND autorizacion=? AND id_orden<>?',[$o['entorno'],$o['merchant_code'],$verified['autorizacion'],$id]);
                if ($other) throw new InvalidArgumentException('La autorización ya pertenece a otra orden.');
                pagoEvento($db,$id,'aprobacion_firmada','Autorización verificada por '.$source.'. No se guardan datos de tarjeta.',null,$fingerprint);
                $o['pago_verificado']=1; $o['referencia']=$verified['referencia'];
                pagoExec($db,'UPDATE pagos_ordenes SET pago_verificado=1,autorizacion=?,referencia=?,metodo_proveedor=? WHERE id_orden=?',[$verified['autorizacion'],$verified['referencia'],$verified['metodo'],$id]);
                pagoMaterializar($db,$o,$box);
            }
        }
        $db->commit(); return pagoOrden($db,$o['publico']);
    } catch(Throwable $e) { $db->rollback(); throw $e; }
}
/** Reubicar una venta YA pagada pero pendiente de registrar. No cobra ni reembolsa. */
function pagoConciliar(mysqli $db,array $pre,array $user,string $note): array {
    if (($user['rol'] ?? '')!=='administrador' || strlen(trim($note))<15) throw new InvalidArgumentException('Solo el administrador puede conciliar, con una nota explicativa.');
    $new=getTodayOpenCashBox($db);
    if (!$new) throw new RuntimeException('Abre una caja del día para conciliar.');
    pagoBegin($db);
    try {
        // Todas las cajas involucradas se bloquean en orden ascendente.
        $ids=array_unique([(int)$pre['id_cierre'],(int)$new['id_cierre']]); sort($ids);
        foreach ($ids as $id) pagoUno($db,'SELECT id_cierre FROM cierres_caja WHERE id_cierre=? FOR UPDATE',[$id]);
        $o=pagoUno($db,'SELECT * FROM pagos_ordenes WHERE id_orden=? FOR UPDATE',[(int)$pre['id_orden']]);
        if (!in_array((int)$o['id_cierre'],$ids,true)) throw new RuntimeException('La caja cambió. Actualiza y reintenta.');
        if ($o['id_venta']) { $db->commit(); return $o; }
        if (!(int)$o['pago_verificado'] || $o['estado']!=='revision' || !in_array($o['entorno'],['production','demo_venta','sandbox_venta'],true)) throw new RuntimeException('Esta orden no tiene un pago verificado pendiente de registrar en su entorno correspondiente.');
        $box=pagoUno($db,'SELECT *,fecha=CURDATE() AS es_hoy FROM cierres_caja WHERE id_cierre=?',[(int)$new['id_cierre']]);
        $o['id_cierre']=$new['id_cierre'];
        pagoExec($db,'UPDATE pagos_ordenes SET id_cierre=? WHERE id_orden=?',[(int)$new['id_cierre'],(int)$o['id_orden']]);
        pagoEvento($db,(int)$o['id_orden'],'conciliacion','Caja original #'.$pre['id_cierre'].'; caja de registro #'.$new['id_cierre'].'. '.pagoTexto($note,350),(int)$user['id_usuario']);
        pagoMaterializar($db,$o,$box); $db->commit(); return pagoOrden($db,$o['publico']);
    } catch(Throwable $e) { $db->rollback(); throw $e; }
}
