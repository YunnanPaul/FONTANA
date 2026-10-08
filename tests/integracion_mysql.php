<?php
/** Real, opt-in integration test. NEVER uses or alters FONTANA_DB_NAME.
 * Creates its own random fontana_test_..._pruebas database, then drops ONLY that database.
 * Run against a LOCAL TEST SERVER, not a production server:
 * FONTANA_RUN_MYSQL_TESTS=1 php tests/integracion_mysql.php
 * Connection: FONTANA_TEST_DB_HOST, _PORT, _USER, _PASSWORD (defaults local root).
 */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
if (!extension_loaded('mysqli')) { echo "SKIP: mysqli no está instalado; NO se ejecutó MySQL/MariaDB.\n"; exit(77); }
if (getenv('FONTANA_RUN_MYSQL_TESTS')!=='1') { echo "SKIP: requiere FONTANA_RUN_MYSQL_TESTS=1 y servidor de pruebas.\n"; exit(77); }
require_once __DIR__.'/../includes/ventas_core.php';
require_once __DIR__.'/../includes/instalacion_pagos.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Lima'); $_SESSION=[];
$created=false;$db=null;$name='fontana_test_'.bin2hex(random_bytes(6)).'_pruebas';$passed=0;
function assertTest(bool $ok,string $label): void { global $passed; if (!$ok) throw new RuntimeException($label); $passed++; echo 'OK '.$label."\n"; }
function scriptSQL(mysqli $db,string $path): void {
    $sql=file_get_contents($path);if ($sql===false) throw new RuntimeException('Missing SQL fixture');
    $db->multi_query($sql);
    do { if ($r=$db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
}
function snap(mysqli $db): string {
    $out=[];foreach (['usuarios','productos','ventas','detalle_venta','movimientos_inventario','cierres_caja'] as $table) {
        // total_efectivo is additive; compare existing business data, not the new column.
        $rows=pagoTodos($db,'SELECT * FROM '.$table.' ORDER BY 1');
        foreach ($rows as &$row) if ($table==='cierres_caja') unset($row['total_efectivo']);unset($row);
        $out[$table]=$rows;
    }return hash('sha256',json_encode($out));
}
try {
    $db=new mysqli(getenv('FONTANA_TEST_DB_HOST') ?: '127.0.0.1',getenv('FONTANA_TEST_DB_USER') ?: 'root',getenv('FONTANA_TEST_DB_PASSWORD') ?: '',null,(int)(getenv('FONTANA_TEST_DB_PORT') ?: 3306));
    $db->query('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');$created=true;
    $db->select_db($name);$db->set_charset('utf8mb4');$db->query("SET time_zone='-05:00'");
    scriptSQL($db,__DIR__.'/fixtures/base_original.sql');
    $hash=password_hash(bin2hex(random_bytes(16)),PASSWORD_BCRYPT);
    pagoExec($db,"INSERT INTO usuarios(nombre,usuario,clave,rol) VALUES('TEST','test',?,'administrador')",[$hash]);
    pagoExec($db,"INSERT INTO productos(nombre,tipo,precio,stock,stock_minimo) VALUES('A','producto','5.00',10,1),('B','producto','2.50',20,1)");
    pagoExec($db,"INSERT INTO cierres_caja(fecha,monto_inicial,estado,id_usuario_apertura) VALUES(CURDATE(),'20.00','abierta',1)");
    assertTest(ensureSalesDocumentSchema($db),'Esquema original admite columnas de comprobantes sin borrar filas');
    assertTest(!pagoSchemaReady($db),'Base original sin ampliación de pagos');
    $receipt=['tipo_comprobante'=>'boleta','tipo_documento'=>'SIN_DOCUMENTO','numero_documento'=>'','cliente_nombre'=>'CONSUMIDOR FINAL','cliente_direccion'=>'','documento_consultado'=>0,'api_proveedor'=>null];
    $key=pagoNuevaClave();$sale=ventaRegistrarHabitual($db,[1=>2,2=>3],'Efectivo',$receipt,1,1,$key,['confirmado'=>'1','recibido'=>'20.00']);
    assertTest($sale['total']==17.5 && $sale['vuelto_centimos']===250,'Venta habitual exacta S/17.50 y vuelto S/2.50, sin pagos_*');
    assertTest((int)pagoUno($db,'SELECT stock FROM productos WHERE id_producto=1')['stock']===8,'Stock actualizado en MySQL');
    $again=ventaRegistrarHabitual($db,[1=>2,2=>3],'Efectivo',$receipt,1,1,$key,['confirmado'=>'1','recibido'=>'20.00']);
    assertTest($again['venta']===$sale['venta'] && (int)pagoUno($db,'SELECT COUNT(*) n FROM ventas')['n']===1,'Reenvío de la misma sesión no duplica venta');
    $snapshot=snap($db);$failed=false;
    try { ventaRegistrarHabitual($db,[1=>999],'Yape',$receipt,1,1,pagoNuevaClave(),['confirmado'=>'1']); } catch (RuntimeException $e) { $failed=true; }
    assertTest($failed && snap($db)===$snapshot,'Stock insuficiente no altera ninguna tabla del negocio');
    fontanaInstalarPagos($db);
    assertTest(pagoSchemaReady($db) && pagoAcademicoSchemaReady($db),'Ampliación completa ejecutada en MySQL');
    assertTest(snap($db)===$snapshot,'Instalar conserva usuarios, productos, ventas, detalle, stock y caja');
    fontanaInstalarPagos($db);
    assertTest(snap($db)===$snapshot,'Repetir la ampliación conserva los datos');
    scriptSQL($db,__DIR__.'/../fontana_db.sql');
    assertTest(snap($db)===$snapshot,'SQL base no destructivo no reinserta semillas ni borra datos al repetirse');
    $after=pagoUno($db,"SELECT COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cierres_caja' AND COLUMN_NAME='total_efectivo'");
    assertTest((bool)$after,'Columna de efectivo añadida de forma aditiva');
    echo 'RESULTADO: '.$passed." pruebas MySQL aprobadas. No se llamó a ninguna pasarela.\n";
} catch (Throwable $e) {
    fwrite(STDERR,'FAIL ('.$e->getCode().'): '.$e->getMessage()."\n");$failedRun=true;
} finally {
    if ($db instanceof mysqli && $created) {
        // This identifier is exclusively generated here, never read from user configuration.
        if (preg_match('/^fontana_test_[a-f0-9]{12}_pruebas$/D',$name)) $db->query('DROP DATABASE `'.$name.'`');
    }
    if ($db instanceof mysqli) $db->close();
}
exit(!empty($failedRun) ? 1 : 0);
