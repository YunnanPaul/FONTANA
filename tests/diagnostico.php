<?php
/** Diagnóstico de lectura, no ejecuta migraciones ni cobros. Solo CLI. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$issues=0;
echo "Fontana · Diagnóstico previo (sin cobros ni escritura de datos)\n";
foreach (['mysqli','json','openssl'] as $ext) {
    $ok=extension_loaded($ext); echo ($ok ? 'OK ' : 'FALTA ').$ext."\n"; if (!$ok) $issues++;
}
if (PHP_VERSION_ID<80000 || PHP_INT_SIZE<8) { echo "FALTA PHP 8.0+ de 64 bits\n"; $issues++; }
require_once __DIR__.'/../includes/izipay.php';
try {
    $config=pagoConfig(); echo 'Modo: '.$config['mode']." (no se muestran llaves)\n";
    foreach (pagoConfigErrors() as $error) { echo 'REVISAR '.$error."\n"; $issues++; }
} catch(Throwable $e) { echo "REVISAR configuración de pagos\n"; $issues++; }
if (extension_loaded('mysqli')) {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    try {
        $cfg=['host'=>getenv('FONTANA_DB_HOST') ?: '127.0.0.1','user'=>getenv('FONTANA_DB_USER') ?: 'root','password'=>getenv('FONTANA_DB_PASSWORD') ?: '', 'database'=>getenv('FONTANA_DB_NAME') ?: 'fontana_db','port'=>(int)(getenv('FONTANA_DB_PORT') ?: 3306)];
        $local=__DIR__.'/../config/conexion.php';
        if(is_file($local)) { $custom=require $local; if(is_array($custom))$cfg=array_replace($cfg,$custom); }
        $db=new mysqli($cfg['host'],$cfg['user'],$cfg['password'],$cfg['database'],(int)$cfg['port']);
        echo 'Base seleccionada: '.$cfg['database']."\n";
        $db->set_charset('utf8mb4');
        $ready=pagoSchemaReady($db) && pagoAcademicoSchemaReady($db); echo $ready ? "OK esquema ampliado\n" : "AVISO: pagos avanzados opcionales no instalados o incompletos; las ventas habituales no los exigen\n";
        $tables=pagoTodos($db,"SELECT TABLE_NAME,ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('productos','ventas','detalle_venta','movimientos_inventario','cierres_caja','pagos_ordenes','pagos_lineas','pagos_eventos')");
        foreach ($tables as $t) { $ok=strtoupper($t['ENGINE'])==='INNODB'; echo ($ok ? 'OK ' : 'REVISAR ').$t['TABLE_NAME'].' / '.$t['ENGINE']."\n"; if(!$ok)$issues++; }
        $db->close();
    } catch(Throwable $e) { echo 'REVISAR conexión/permisos MySQL; código '.$e->getCode()."\n"; $issues++; }
}
echo "Resultado: $issues punto(s) pendientes. El simulador local no necesita cURL ni credenciales. La API externa sí necesita cURL, Internet y llaves TEST-.\n";
exit($issues ? 1 : 0);
