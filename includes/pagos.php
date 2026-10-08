<?php
/** Núcleo de pagos. Importes enteros, reservas, transacciones e idempotencia.
 * Las ventas existentes siguen siendo ventas confirmadas. Nunca se inserta una
 * venta pendiente en la tabla ventas. No recibe ni almacena datos de tarjeta.
 */
require_once __DIR__ . '/helpers.php';

function pagoConfig(): array {
    static $config;
    if ($config === null) {
        $defaults = require __DIR__ . '/../config/pagos.example.php';
        $path = __DIR__ . '/../config/pagos.php';
        $custom = is_file($path) ? require $path : [];
        if (!is_array($custom)) throw new RuntimeException('config/pagos.php debe devolver un arreglo de configuración.');
        $candidate = array_replace_recursive($defaults, $custom);
        if (!in_array($candidate['mode'], ['demo','mercadopago_sandbox','manual','sandbox','production'], true)) {
            throw new RuntimeException('El modo de pagos configurado no es válido.');
        }
        if (!in_array($candidate['checkout_flow'] ?? 'normal', ['normal','integrado'], true)) throw new RuntimeException('La configuración del flujo de cobros no es válida.');
        if (!is_bool($candidate['academic_record_sales'])) throw new RuntimeException('academic_record_sales debe ser true o false, sin comillas.');
        $config = $candidate;
    }
    return $config;
}
function pagoCredenciales(string $env): array {
    $c = pagoConfig();
    if (!isset($c['environments'][$env])) throw new RuntimeException('Entorno de pago no válido.');
    return $c['environments'][$env];
}
function pagoConfigErrors(?string $env = null): array {
    $c = pagoConfig(); $env = $env ?? $c['mode']; $errors = [];
    if (in_array($env, ['manual','demo','demo_venta'], true)) return [];
    if ($env === 'mercadopago_sandbox') {
        foreach (['public_key','access_token'] as $key) {
            $value=(string)($c['mercadopago'][$key] ?? '');
            if (!preg_match('/^TEST-[A-Za-z0-9_-]{10,250}$/D', $value))
                $errors[]='Configura mercadopago.'.$key.' con una credencial TEST- de Checkout API (Payments API).';
        }
        if (!extension_loaded('curl')) $errors[]='Activa la extensión PHP cURL para la API externa.';
        return $errors;
    }
    $keys = pagoCredenciales($env);
    foreach (['merchant_code','api_key','hash_key','rsa_public_key'] as $key) {
        if (trim((string)($keys[$key] ?? '')) === '') $errors[] = 'Falta configurar ' . $key . ' para ' . $env . '.';
    }
    $url = (string)$c['base_url'];
    $host = parse_url($url, PHP_URL_HOST);
    if (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https' ||
        !$host || in_array(strtolower($host), ['localhost','127.0.0.1','::1'], true) ||
        parse_url($url, PHP_URL_QUERY) || parse_url($url, PHP_URL_FRAGMENT) || parse_url($url, PHP_URL_USER)) {
        $errors[] = 'Configura FONTANA_PUBLIC_URL con una URL HTTPS pública y sin parámetros.';
    }
    if (!extension_loaded('curl')) $errors[] = 'Activa la extensión PHP cURL.';
    return $errors;
}
/** Convierte DECIMAL/entrada decimal exacta; no redondea importes manipulados. */
function pagoCentimos($value): int {
    if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('Importe no válido.');
    $value = trim((string)$value);
    if (!preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', $value, $m)) {
        throw new InvalidArgumentException('Usa un importe positivo con hasta dos decimales.');
    }
    return ((int)$m[1] * 100) + (int)str_pad($m[2] ?? '', 2, '0');
}
function pagoDecimal(int $cents): string {
    if ($cents < 0) throw new InvalidArgumentException('Importe negativo.');
    return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}
function pagoMoneda(int $cents): string { return 'S/ ' . number_format($cents / 100, 2); }
function pagoTexto($s, int $max, bool $required = false): string {
    if (!is_scalar($s) && $s !== null) throw new InvalidArgumentException('Texto no válido.');
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s) ?? '');
    $length = preg_match_all('/./us', $s);
    if ($length === false || $length > $max || ($required && $s === '') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $s)) {
        throw new InvalidArgumentException('Texto vacío, demasiado largo o con caracteres no válidos.');
    }
    return $s;
}
function pagoLineasValidas($ids, $quantities): array {
    if (!is_array($ids) || !is_array($quantities) || !$ids || count($ids) !== count($quantities) || count($ids) > 100) {
        throw new InvalidArgumentException('Agrega entre 1 y 100 líneas de productos.');
    }
    $out = [];
    foreach ($ids as $i => $id) {
        $qty = $quantities[$i] ?? null;
        if ((!is_string($id) && !is_int($id)) || (!is_string($qty) && !is_int($qty)) ||
            !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$id) || !preg_match('/^[1-9][0-9]{0,4}$/D', (string)$qty)) {
            throw new InvalidArgumentException('Selecciona cada producto y una cantidad entera mayor que cero.');
        }
        $out[(int)$id] = ($out[(int)$id] ?? 0) + (int)$qty;
        if ($out[(int)$id] > 99999) throw new InvalidArgumentException('Cantidad fuera de rango.');
    }
    ksort($out, SORT_NUMERIC);
    return $out;
}
/** prepare + bind genérico para PHP 8.0; los DECIMAL se envían como strings. */
function pagoQuery(mysqli $db, string $sql, array $args = []): mysqli_stmt {
    $stmt = $db->prepare($sql);
    if ($args) {
        $types = '';
        foreach ($args as $v) $types .= is_int($v) ? 'i' : 's';
        $stmt->bind_param($types, ...$args);
    }
    $stmt->execute(); return $stmt;
}
function pagoUno(mysqli $db, string $sql, array $args = []): ?array {
    $stmt = pagoQuery($db, $sql, $args); $row = $stmt->get_result()->fetch_assoc(); $stmt->close(); return $row;
}
function pagoTodos(mysqli $db, string $sql, array $args = []): array {
    $stmt = pagoQuery($db, $sql, $args); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); return $rows;
}
function pagoExec(mysqli $db, string $sql, array $args = []): int {
    $stmt = pagoQuery($db, $sql, $args); $count = $stmt->affected_rows; $stmt->close(); return $count;
}
function pagoSchemaReady(mysqli $db): bool {
    // No static cache: the installer may create the schema during this request.
    // A partially created payments schema must not be reported as ready.
    $required = [
        'pagos_ordenes' => ['id_orden','publico','idempotencia','id_usuario','id_cierre','total_centimos','moneda','metodo','proveedor','entorno','merchant_code','numero_externo','transaccion','instante_ms','estado','comprobante_json','referencia','autorizacion','metodo_proveedor','pago_verificado','alerta','token_sesion','token_creado','billing_json','recibido_centimos','vuelto_centimos','id_venta','creado','actualizado','reserva_hasta'],
        'pagos_lineas' => ['id_linea','id_orden','id_producto','nombre','cantidad','precio_centimos','subtotal_centimos'],
        'pagos_eventos' => ['id_evento','id_orden','huella','tipo','nota','id_usuario','creado'],
    ];
    return pagoVerificarColumnas($db, $required);
}
function pagoVerificarColumnas(mysqli $db, array $required): bool {
    $tables = array_keys($required);
    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    $rows = pagoTodos($db, "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($placeholders)", $tables);
    $found = [];
    foreach ($rows as $row) $found[$row['TABLE_NAME']][$row['COLUMN_NAME']] = true;
    foreach ($required as $table => $columns) {
        foreach ($columns as $column) if (!isset($found[$table][$column])) return false;
    }
    return true;
}
function pagoBegin(mysqli $db): void {
    $db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $db->begin_transaction();
}
function pagoEvento(mysqli $db, int $id, string $tipo, string $nota, ?int $user = null, ?string $hash = null): void {
    $hash = $hash ?? hash('sha256', random_bytes(32));
    pagoExec($db, 'INSERT INTO pagos_eventos(id_orden, huella, tipo, nota, id_usuario) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE id_evento=id_evento',
        [$id, $hash, $tipo, pagoTexto($nota,500), $user]);
}
/** Llamar con el producto bloqueado FOR UPDATE cuando vaya a modificarse stock. */
function pagoReservado(mysqli $db, int $producto, int $excepto = 0): int {
    if (!pagoSchemaReady($db)) return 0;
    $r = pagoUno($db, "SELECT COALESCE(SUM(l.cantidad),0) n FROM pagos_lineas l JOIN pagos_ordenes o ON o.id_orden=l.id_orden
        WHERE l.id_producto=? AND o.id_orden<>? AND o.id_venta IS NULL AND o.entorno NOT IN ('demo','sandbox')
        AND ((o.estado IN ('en_proceso','revision') AND (o.proveedor<>'demo' OR o.reserva_hasta>NOW())) OR (o.estado='pendiente' AND o.reserva_hasta>NOW()))", [$producto,$excepto]);
    return (int)$r['n'];
}
function pagoOrden(mysqli $db, string $public, ?array $user = null): array {
    if (!preg_match('/^[a-f0-9]{32}$/D', $public)) throw new InvalidArgumentException('Orden no válida.');
    $o = pagoUno($db, 'SELECT * FROM pagos_ordenes WHERE publico=?', [$public]);
    if (!$o || ($user && ($user['rol'] ?? '') !== 'administrador' && (int)$o['id_usuario'] !== (int)$user['id_usuario'])) {
        throw new InvalidArgumentException('Orden no encontrada o sin permiso.');
    }
    return $o;
}
function pagoNuevaClave(): string {
    $key = bin2hex(random_bytes(32));
    $_SESSION['pago_formularios'][$key] = time();
    foreach ($_SESSION['pago_formularios'] as $k=>$t) if ($t < time()-86400) unset($_SESSION['pago_formularios'][$k]);
    if (count($_SESSION['pago_formularios']) > 100) array_shift($_SESSION['pago_formularios']);
    return $key;
}
function pagoCrearOrden(mysqli $db, array $lines, string $method, array $receipt, int $user, int $cash, string $key): array {
    if (!pagoSchemaReady($db)) throw new RuntimeException('Un administrador debe instalar la actualización desde Pagos > Configuración.');
    if (!preg_match('/^[a-f0-9]{64}$/D',$key) || !isset($_SESSION['pago_formularios'][$key])) {
        throw new InvalidArgumentException('El formulario venció. Vuelve a seleccionar los productos.');
    }
    if (!in_array($method,paymentMethods(),true)) throw new InvalidArgumentException('Método de pago no válido.');
    $existing = pagoUno($db,'SELECT * FROM pagos_ordenes WHERE idempotencia=?',[$key]);
    if ($existing) {
        if ((int)$existing['id_usuario'] !== $user) throw new RuntimeException('Orden de otro usuario.');
        return $existing;
    }
    $config = pagoConfig();
    [$provider,$env] = pagoRuta($method,$config);
    if ($provider === 'mercadopago' && pagoConfigErrors('mercadopago_sandbox'))
        throw new RuntimeException('Faltan credenciales TEST- de Mercado Pago o cURL. Revisa Configuración. Puedes usar el modo demo sin credenciales.');
    if ($provider === 'izipay' && pagoConfigErrors($env))
        throw new RuntimeException('La pasarela anterior requiere configuración. Revisa Pagos > Configuración.');
    if (in_array($provider,['demo','mercadopago'],true) && !pagoAcademicoSchemaReady($db))
        throw new RuntimeException('Instala la ampliación académica desde Pagos > Configuración.');
    if (!empty($config['academic_record_sales']) && in_array($provider,['demo','mercadopago'],true)) {
        pagoExigirBaseAcademica($db); $env.='_venta';
    }
    $merchant = $provider === 'izipay' ? (string)pagoCredenciales($env)['merchant_code'] : '';
    $public = bin2hex(random_bytes(16));
    $ms = (string)(int)floor(microtime(true)*1000);
    $external = $ms . (string)random_int(1000,9999);
    $transaction = $external . (string)random_int(1000,9999);
    $minutes = max(5,min(60,(int)$config['reservation_minutes']));
    pagoBegin($db);
    try {
        $box = pagoUno($db,"SELECT *, fecha=CURDATE() AS es_hoy FROM cierres_caja WHERE id_cierre=? FOR UPDATE",[$cash]);
        if (!$box || $box['estado'] !== 'abierta' || !$box['es_hoy']) throw new RuntimeException('La caja del día ya no está abierta.');
        // Revisión bajo bloqueo de caja: protege también doble POST desde pestañas.
        $existing = pagoUno($db,'SELECT * FROM pagos_ordenes WHERE idempotencia=?',[$key]);
        if ($existing) { $db->commit(); return $existing; }
        $total=0; $products=[];
        foreach ($lines as $id=>$qty) {
            $p = pagoUno($db,'SELECT * FROM productos WHERE id_producto=? FOR UPDATE',[(int)$id]);
            if (!$p || $p['tipo'] !== 'producto') throw new RuntimeException('Uno de los productos ya no está disponible para venta.');
            $available = (int)$p['stock'] - pagoReservado($db,(int)$id);
            if ($qty > $available) throw new RuntimeException('Stock disponible insuficiente para ' . $p['nombre'] . ' (disponible: ' . max(0,$available) . ').');
            $price = pagoCentimos((string)$p['precio']);
            if ($price <= 0) throw new RuntimeException('Revisa el precio de ' . $p['nombre'] . '.');
            $subtotal = $price * $qty; $total += $subtotal;
            if ($total > 9999999999) throw new RuntimeException('El total supera la capacidad del registro de ventas.');
            $products[] = [$id,$p['nombre'],$qty,$price,$subtotal];
        }
        if ($total <= 0) throw new RuntimeException('La orden no tiene un total válido.');
        pagoExec($db,"INSERT INTO pagos_ordenes(publico,idempotencia,id_usuario,id_cierre,total_centimos,metodo,proveedor,entorno,merchant_code,numero_externo,transaccion,instante_ms,comprobante_json,reserva_hasta)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL $minutes MINUTE))",
            [$public,$key,$user,$cash,$total,$method,$provider,$env,$merchant,$external,$transaction,$ms,json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $id=(int)$db->insert_id;
        foreach ($products as $p) pagoExec($db,'INSERT INTO pagos_lineas(id_orden,id_producto,nombre,cantidad,precio_centimos,subtotal_centimos) VALUES(?,?,?,?,?,?)',array_merge([$id],$p));
        pagoEvento($db,$id,'orden_creada','Importe calculado en servidor; aún no existe venta ni salida de inventario.',$user);
        $db->commit(); return pagoOrden($db,$public);
    } catch(Throwable $e) { $db->rollback(); throw $e; }
}
/** Orden de bloqueos global: caja -> orden -> productos en orden ascendente. */
function pagoBloquear(mysqli $db, array $pre): array {
    $box = pagoUno($db,'SELECT *, fecha=CURDATE() AS es_hoy FROM cierres_caja WHERE id_cierre=? FOR UPDATE',[(int)$pre['id_cierre']]);
    $o = pagoUno($db,'SELECT * FROM pagos_ordenes WHERE id_orden=? FOR UPDATE',[(int)$pre['id_orden']]);
    if (!$o || !$box) throw new RuntimeException('No se encontró la orden o su caja.');
    // Evita que una conciliación concurrente cambie la caja después de precargar.
    if ((int)$o['id_cierre'] !== (int)$pre['id_cierre']) throw new RuntimeException('La caja de esta orden cambió. Actualiza y vuelve a intentar.');
    return [$o,$box];
}
/** Transacción ya abierta y caja/orden bloqueadas. Retorna false sin perder un cobro verificado. */
function pagoMaterializar(mysqli $db, array $o, array $box): bool {
    $id=(int)$o['id_orden'];
    if ($o['id_venta']) return true;
    if (!(int)$o['pago_verificado']) throw new RuntimeException('No hay un pago verificado.');
    if (in_array($o['entorno'], ['sandbox','demo'], true)) {
        pagoExec($db,"UPDATE pagos_ordenes SET estado='prueba',token_sesion=NULL,billing_json=NULL,alerta=NULL WHERE id_orden=?",[$id]);
        return true;
    }
    if (in_array($o['entorno'], ['sandbox_venta','demo_venta'], true)) pagoExigirBaseAcademica($db);
    $reason = '';
    if ($box['estado'] !== 'abierta' || !(int)$box['es_hoy']) $reason = 'Pago recibido, pero su caja está cerrada o es de otro día. Requiere conciliación del administrador.';
    $lines=pagoTodos($db,'SELECT * FROM pagos_lineas WHERE id_orden=? ORDER BY id_producto',[$id]);
    $products=[];
    foreach ($lines as $l) {
        $p=pagoUno($db,'SELECT * FROM productos WHERE id_producto=? FOR UPDATE',[(int)$l['id_producto']]);
        if (!$p || $p['tipo'] !== 'producto' || (int)$p['stock'] - pagoReservado($db,(int)$l['id_producto'],$id) < (int)$l['cantidad']) {
            $reason='Pago recibido, pero el stock disponible de ' . $l['nombre'] . ' requiere revisión. No volver a cobrar.';
        }
        $products[(int)$l['id_producto']]=$p;
    }
    if (!$lines) $reason='Pago recibido sin líneas de productos. Revisión técnica necesaria.';
    if ($reason !== '') {
        pagoExec($db,"UPDATE pagos_ordenes SET estado='revision', alerta=?,token_sesion=NULL,billing_json=NULL WHERE id_orden=?",[$reason,$id]);
        pagoEvento($db,$id,'requiere_revision',$reason); return false;
    }
    $r=json_decode($o['comprobante_json'],true,32,JSON_THROW_ON_ERROR);
    $reference = substr((string)($o['referencia'] ?? ''),0,50);
    pagoExec($db,'INSERT INTO ventas(fecha,total,metodo_pago,referencia_pago,tipo_comprobante,tipo_documento,numero_documento,cliente_nombre,cliente_direccion,documento_consultado,api_proveedor) VALUES(NOW(),?,?,?,?,?,?,?,?,?,?)',[
        pagoDecimal((int)$o['total_centimos']),$o['metodo'],$reference ?: null,$r['tipo_comprobante'],$r['tipo_documento'],
        $r['numero_documento'] ?: null,$r['cliente_nombre'],$r['cliente_direccion'] ?: null,(int)$r['documento_consultado'],$r['api_proveedor'] ?: null]);
    $sale=(int)$db->insert_id;
    foreach ($lines as $l) {
        $pid=(int)$l['id_producto']; $qty=(int)$l['cantidad']; $old=(int)$products[$pid]['stock']; $new=$old-$qty;
        pagoExec($db,'INSERT INTO detalle_venta(id_venta,id_producto,cantidad,precio_unitario,subtotal) VALUES(?,?,?,?,?)',[$sale,$pid,$qty,pagoDecimal((int)$l['precio_centimos']),pagoDecimal((int)$l['subtotal_centimos'])]);
        if (pagoExec($db,'UPDATE productos SET stock=stock-? WHERE id_producto=? AND stock>=?',[$qty,$pid,$qty]) !== 1) throw new RuntimeException('Conflicto de inventario.');
        pagoExec($db,'INSERT INTO movimientos_inventario(id_producto,tipo_movimiento,cantidad,motivo,stock_anterior,stock_nuevo) VALUES(?,?,?,?,?,?)',[$pid,'salida',$qty,'Venta #'.$sale,$old,$new]);
    }
    pagoExec($db,"UPDATE pagos_ordenes SET estado='pagado',id_venta=?,token_sesion=NULL,billing_json=NULL,alerta=NULL WHERE id_orden=?",[$sale,$id]);
    pagoEvento($db,$id,'venta_confirmada','Venta #'.$sale.' registrada una sola vez; inventario actualizado.');
    return true;
}
function pagoConfirmarManual(mysqli $db, array $pre, array $input, int $user): array {
    if (($input['confirmado'] ?? '') !== '1') throw new InvalidArgumentException('Confirma que recibiste el pago, no solo una captura de pantalla.');
    if ($pre['proveedor'] !== 'manual') throw new InvalidArgumentException('Un pago de pasarela no se puede aprobar manualmente.');
    $ref=pagoTexto($input['referencia'] ?? '',50,$pre['metodo'] !== 'Efectivo');
    $received=$pre['metodo'] === 'Efectivo' ? pagoCentimos($input['recibido'] ?? '') : (int)$pre['total_centimos'];
    if ($received < (int)$pre['total_centimos']) throw new InvalidArgumentException('El efectivo recibido es menor al total.');
    pagoBegin($db);
    try {
        [$o,$box]=pagoBloquear($db,$pre);
        if ($o['estado'] === 'pagado') { $db->commit(); return pagoOrden($db,$o['publico']); }
        if ($o['estado'] !== 'pendiente' || strtotime($o['reserva_hasta']) <= time()) throw new RuntimeException('La reserva ya no está activa. No recibas el pago; crea una nueva orden.');
        if ($box['estado'] !== 'abierta' || !$box['es_hoy']) throw new RuntimeException('La caja del día debe estar abierta.');
        $o['pago_verificado']=1; $o['referencia']=$ref;
        pagoExec($db,'UPDATE pagos_ordenes SET pago_verificado=1,referencia=?,recibido_centimos=?,vuelto_centimos=? WHERE id_orden=?',[$ref,$received,$received-(int)$o['total_centimos'],(int)$o['id_orden']]);
        pagoEvento($db,(int)$o['id_orden'],'confirmacion_manual','Cajero verificó recepción. No es una verificación bancaria automática.',$user);
        if (!pagoMaterializar($db,$o,$box)) throw new RuntimeException('No se pudo completar la venta. Verifica stock y caja antes de recibir dinero.');
        $db->commit(); return pagoOrden($db,$o['publico']);
    } catch(Throwable $e) { $db->rollback(); throw $e; }
}
function pagoCancelar(mysqli $db,array $pre,array $user,string $note='',bool $checked=false): array {
    pagoBegin($db);
    try {
        [$o,$box]=pagoBloquear($db,$pre);
        if ($o['id_venta'] || (int)$o['pago_verificado'] || $o['estado']==='prueba') throw new RuntimeException('Una orden pagada no se cancela aquí. Gestiona cualquier devolución con el proveedor.');
        if ($o['estado'] === 'cancelado') { $db->commit(); return $o; }
        if (in_array($o['estado'],['en_proceso','revision'],true) && !pagoEsAcademico($o)) {
            if (($user['rol'] ?? '') !== 'administrador' || !$checked || strlen(trim($note)) < 15) {
                throw new RuntimeException('El administrador debe comprobar en el proveedor que no hubo cobro y que la operación ya no puede pagarse; luego registrar evidencia.');
            }
        }
        pagoExec($db,"UPDATE pagos_ordenes SET estado='cancelado',token_sesion=NULL,billing_json=NULL WHERE id_orden=?",[(int)$o['id_orden']]);
        pagoEvento($db,(int)$o['id_orden'],'cancelacion_local',pagoTexto($note ?: 'Cancelada antes de iniciar el cobro.',500),(int)$user['id_usuario']);
        $db->commit(); return pagoOrden($db,$o['publico']);
    } catch(Throwable $e) { $db->rollback(); throw $e; }
}
function pagoEstadoPublico(array $o): array {
    $state=$o['estado'];
    if (in_array($state,['pendiente','en_proceso'],true) && strtotime($o['reserva_hasta']) <= time() && ($state==='pendiente' || ($o['proveedor'] ?? '')==='demo')) $state='vencido';
    $labels=['pendiente'=>'Pendiente de cobro','en_proceso'=>'Esperando confirmación del pago','pagado'=>'Pago confirmado · venta registrada',
        'cancelado'=>'Orden cancelada localmente','vencido'=>'Reserva vencida · no cobrar','revision'=>'Requiere revisión · no volver a cobrar','prueba'=>'Prueba aprobada · sin venta real','rechazado'=>'Pago de prueba rechazado'];
    if ($state==='pagado' && pagoEsAcademico($o)) $labels['pagado']='Venta de prueba registrada';
    return ['estado'=>$state,'etiqueta'=>$labels[$state] ?? $state,'total_centimos'=>(int)$o['total_centimos'],
        'id_venta'=>$o['id_venta'] ? (int)$o['id_venta'] : null,'pago_verificado'=>(bool)$o['pago_verificado'],
        'referencia'=>$o['referencia'],'alerta'=>$o['alerta'],'metodo'=>$o['metodo'],'entorno'=>$o['entorno'],
        'proveedor'=>$o['proveedor'] ?? '', 'academico'=>pagoEsAcademico($o), 'vence_en'=>max(0,strtotime($o['reserva_hasta'])-time()),
        'vuelto_centimos'=>$o['vuelto_centimos'] === null ? null : (int)$o['vuelto_centimos']];
}

/** Decide the provider in the server; the browser cannot change it. */
function pagoRuta(string $method, array $config): array {
    $mode=$config['mode'];
    if ($mode==='demo') return ['demo','demo'];
    if ($mode==='mercadopago_sandbox')
        return in_array($method,['Yape','Tarjeta'],true) ? ['mercadopago','sandbox'] : ['demo','demo'];
    $env=$method==='Efectivo' ? 'manual' : $mode;
    return [$env==='manual' ? 'manual' : 'izipay', $env];
}
function pagoEsAcademico(array $o): bool {
    return in_array($o['entorno'] ?? '',['demo','sandbox','demo_venta','sandbox_venta'],true);
}
function pagoAcademicoSchemaReady(mysqli $db): bool {
    return pagoVerificarColumnas($db, [
        'pagos_demo' => ['id_orden','token_hash','expira','creado'],
        'pagos_mercadopago' => ['id_orden','idempotencia','payload_hash','pago_id','estado','detalle','consultado','creado','actualizado'],
    ]);
}
function pagoExigirBaseAcademica(mysqli $db): void {
    $name=(string)pagoUno($db,'SELECT DATABASE() nombre')['nombre'];
    if (!preg_match('/_(demo|academico|pruebas)$/D',$name) && ($name === '' || (pagoConfig()['academic_database'] ?? '') !== $name))
        throw new RuntimeException('Para el ciclo completo conecta una base académica y declara su nombre exacto en academic_database, o usa una copia con sufijo _demo, _academico o _pruebas. El simulador aislado sigue disponible.');
}
