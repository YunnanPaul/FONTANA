<?php
/** Simulador PROPIO de Fontana. Nunca contacta Yape, Plin ni un banco. */
require_once __DIR__.'/pagos.php';

function demoExigirOrden(array $o): void {
    if (($o['proveedor'] ?? '')!=='demo' || !in_array($o['entorno'] ?? '',['demo','demo_venta'],true))
        throw new InvalidArgumentException('Esta orden no pertenece al modo de prueba.');
}
function demoBaseUrl(string $base): string {
    $base=rtrim(trim($base),'/');
    if ($base==='') return '';
    if (strlen($base)>400 || !filter_var($base,FILTER_VALIDATE_URL) ||
        !in_array(parse_url($base,PHP_URL_SCHEME),['http','https'],true) ||
        parse_url($base,PHP_URL_USER)!==null || parse_url($base,PHP_URL_PASS)!==null ||
        parse_url($base,PHP_URL_QUERY)!==null || parse_url($base,PHP_URL_FRAGMENT)!==null)
        throw new InvalidArgumentException('La URL del proyecto debe comenzar con http:// o https:// y no contener credenciales, parámetros ni fragmentos.');
    return $base;
}
function demoPreparar(mysqli $db,array $pre,int $user): array {
    demoExigirOrden($pre);
    $base=demoBaseUrl((string)pagoConfig()['base_url']);
    $token=$_SESSION['demo_tokens'][$pre['publico']] ?? bin2hex(random_bytes(32));
    pagoBegin($db);
    try {
        [$o,$box]=pagoBloquear($db,$pre);
        if (!in_array($o['estado'],['pendiente','en_proceso'],true) || strtotime($o['reserva_hasta'])<=time())
            throw new RuntimeException('Esta prueba terminó o venció. Crea una nueva orden.');
        $old=pagoUno($db,'SELECT token_hash FROM pagos_demo WHERE id_orden=?',[(int)$o['id_orden']]);
        $same=$old && hash_equals($old['token_hash'],hash('sha256',$token));
        pagoExec($db,'INSERT INTO pagos_demo(id_orden,token_hash,expira) VALUES(?,?,?) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),expira=VALUES(expira)',
            [(int)$o['id_orden'],hash('sha256',$token),$o['reserva_hasta']]);
        pagoExec($db,"UPDATE pagos_ordenes SET estado='en_proceso' WHERE id_orden=?",[(int)$o['id_orden']]);
        if (!$same) pagoEvento($db,(int)$o['id_orden'],'qr_academico','Enlace de simulación creado. El nuevo QR reemplaza cualquier enlace anterior. No es un QR bancario.',$user);
        $db->commit();
        $_SESSION['demo_tokens'][$o['publico']]=$token;
        if (count($_SESSION['demo_tokens'])>50) array_shift($_SESSION['demo_tokens']);
        return ['url'=>($base ? $base.'/' : '').'pago_demo.php#t='.$token,
            'total_centimos'=>(int)$o['total_centimos'],'vence_en'=>max(0,strtotime($o['reserva_hasta'])-time())];
    } catch(Throwable $e) { $db->rollback(); throw $e; }
}
function demoOrdenPorToken(mysqli $db,string $token): array {
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new InvalidArgumentException('Enlace de prueba no válido.');
    $r=pagoUno($db,'SELECT o.* FROM pagos_demo d JOIN pagos_ordenes o ON o.id_orden=d.id_orden WHERE d.token_hash=?',[hash('sha256',$token)]);
    if (!$r) throw new InvalidArgumentException('El enlace no existe o fue reemplazado. Solicita un nuevo QR al cajero.');
    demoExigirOrden($r); return $r;
}
function demoEstadoMovil(array $o): array {
    // El enlace NO permite leer DNI, RUC, nombre, domicilio ni datos de caja.
    $s=pagoEstadoPublico($o);
    return ['id_orden'=>(int)$o['id_orden'],'estado'=>$s['estado'],'etiqueta'=>$s['etiqueta'],
        'total_centimos'=>$s['total_centimos'],'metodo'=>$s['metodo'],'referencia'=>$s['referencia'],
        'vence_en'=>$s['vence_en'],'id_venta'=>$s['id_venta'],'vuelto_centimos'=>$s['vuelto_centimos']];
}
function demoResolver(mysqli $db,array $pre,string $result,?int $user=null,array $input=[]): array {
    demoExigirOrden($pre);
    if (!in_array($result,['aprobado','rechazado','pendiente','cancelado'],true))
        throw new InvalidArgumentException('Resultado de prueba no válido.');
    pagoBegin($db);
    try {
        [$o,$box]=pagoBloquear($db,$pre); $id=(int)$o['id_orden'];
        if ($user===null) {
            $cap=pagoUno($db,'SELECT token_hash FROM pagos_demo WHERE id_orden=? FOR UPDATE',[$id]);
            $provided=$input['token'] ?? '';
            if (!is_string($provided) || !$cap || !hash_equals($cap['token_hash'],hash('sha256',$provided)))
                throw new InvalidArgumentException('El enlace fue reemplazado. Solicita el QR vigente.');
        }
        if (in_array($o['estado'],['prueba','pagado'],true) && $result==='aprobado') { $db->commit(); return $o; }
        if (in_array($o['estado'],['rechazado','cancelado'],true) && $o['estado']===$result) { $db->commit(); return $o; }
        if (!in_array($o['estado'],['pendiente','en_proceso'],true) || strtotime($o['reserva_hasta'])<=time())
            throw new RuntimeException('La prueba terminó o venció. No puede cambiarse su resultado.');
        // No se usan importes enviados por el cliente: el monto está en la orden.
        if ($result==='aprobado') {
            $received=$o['metodo']==='Efectivo' ? pagoCentimos($input['recibido'] ?? '') : (int)$o['total_centimos'];
            if ($received<(int)$o['total_centimos']) throw new InvalidArgumentException('El efectivo de prueba es menor que el total.');
            $ref='DEMO-'.strtoupper(substr($o['publico'],0,16));
            pagoExec($db,'UPDATE pagos_ordenes SET pago_verificado=1,referencia=?,autorizacion=?,metodo_proveedor=?,recibido_centimos=?,vuelto_centimos=? WHERE id_orden=?',
                [$ref,'DEMO-'.$o['publico'],'SIMULACION',$received,$received-(int)$o['total_centimos'],$id]);
            $o['pago_verificado']=1; $o['referencia']=$ref;
            pagoMaterializar($db,$o,$box);
        } else {
            $state=$result==='pendiente' ? 'en_proceso' : $result;
            pagoExec($db,'UPDATE pagos_ordenes SET estado=?,alerta=NULL WHERE id_orden=?',[$state,$id]);
        }
        pagoEvento($db,$id,'simulacion_'.$result,'Resultado elegido en el simulador '.($user===null ? 'desde el enlace móvil' : 'por el cajero').'. No es una confirmación bancaria.',$user,hash('sha256','demo:'.$id.':'.$result));
        $db->commit(); return pagoOrden($db,$o['publico']);
    } catch(Throwable $e) { $db->rollback(); throw $e; }
}
