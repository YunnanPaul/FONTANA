<?php
/** Regression of the actual sales service, using an EXPLICIT in-memory mysqli double.
 * This is NOT a MySQL engine, a concurrency test, or a substitute for integracion_mysql.php.
 * Run php -n tests/regresion_ventas.php (mysqli must be unloaded).
 */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
if (extension_loaded('mysqli')) { echo "SKIP: execute php -n tests/regresion_ventas.php to load the isolated DB double.\n"; exit(77); }
define('MYSQLI_ASSOC',1);
if (!extension_loaded('mysqli')) {
class mysqli {
    public array $products=[1=>['id_producto'=>1,'nombre'=>'A','tipo'=>'producto','precio'=>'5.00','stock'=>10],2=>['id_producto'=>2,'nombre'=>'B','tipo'=>'producto','precio'=>'2.50','stock'=>20]];
    public array $box=['id_cierre'=>1,'estado'=>'abierta','es_hoy'=>1];
    public array $sales=[], $detail=[], $movements=[], $queries=[], $columns=[];
    public int $insert_id=0, $commits=0, $rollbacks=0;
    public ?string $fail=null; private ?array $snapshot=null;
    public function prepare(string $sql): mysqli_stmt { return new mysqli_stmt($this,$sql); }
    public function query(string $sql): bool { if ($sql!=='SET TRANSACTION ISOLATION LEVEL READ COMMITTED') throw new RuntimeException('Unsupported SQL double: '.$sql); return true; }
    public function begin_transaction(): bool { $this->snapshot=[$this->products,$this->sales,$this->detail,$this->movements]; return true; }
    public function commit(): bool { $this->commits++;$this->snapshot=null;return true; }
    public function rollback(): bool { $this->rollbacks++; if ($this->snapshot!==null) [$this->products,$this->sales,$this->detail,$this->movements]=$this->snapshot; $this->snapshot=null;return true; }
    public function run(string $sql,array $args,string $types): array {
        $this->queries[]=[$sql,$args,$types];
        if ($this->fail!==null && str_starts_with($sql,$this->fail)) throw new RuntimeException('INJECTED WRITE FAILURE');
        if (str_starts_with($sql,'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.columns')) return [$this->columns,0];
        if (str_starts_with($sql,'SELECT *, fecha=CURDATE() AS es_hoy FROM cierres_caja')) return [$args[0]===1?[$this->box]:[],0];
        if (str_starts_with($sql,'SELECT * FROM productos WHERE id_producto=')) return [isset($this->products[$args[0]])?[$this->products[$args[0]]]:[],0];
        if (str_starts_with($sql,'SELECT id_venta FROM ventas WHERE id_venta=')) return [isset($this->sales[$args[0]])?[['id_venta'=>$args[0]]]:[],0];
        if (str_starts_with($sql,'INSERT INTO ventas(')) { $this->insert_id++;$this->sales[$this->insert_id]=$args;return [[],1]; }
        if (str_starts_with($sql,'INSERT INTO detalle_venta(')) { $this->detail[]=$args;return [[],1]; }
        if (str_starts_with($sql,'INSERT INTO movimientos_inventario(')) { $this->movements[]=$args;return [[],1]; }
        if (str_starts_with($sql,'UPDATE productos SET stock=stock-?')) {
            [$qty,$id,$min]=$args;
            if (!isset($this->products[$id]) || $this->products[$id]['stock']<$min) return [[],0];
            $this->products[$id]['stock']-=$qty;return [[],1];
        }
        throw new RuntimeException('Unsupported SQL double: '.$sql);
    }
}
class mysqli_stmt {
    public int $affected_rows=0;private array $args=[],$rows=[];private string $types='';
    public function __construct(private mysqli $db,private string $sql) {}
    public function bind_param(string $types, &...$args): void { $this->types=$types;$this->args=$args; }
    public function execute(): bool { [$this->rows,$this->affected_rows]=$this->db->run($this->sql,$this->args,$this->types);return true; }
    public function get_result(): object { return new class($this->rows) {
        public function __construct(private array $rows) {}
        public function fetch_assoc(): ?array { return array_shift($this->rows); }
        public function fetch_all(int $mode): array { return $this->rows; }
    }; }
    public function close(): void {}
}
}
require_once __DIR__.'/../includes/ventas_core.php';
$_SESSION=[];$pass=0;
function check(bool $ok,string $name): void { global $pass; if (!$ok) throw new RuntimeException('FAIL '.$name);$pass++;echo 'OK '.$name."\n"; }
function rejected(callable $run,string $name): void { try { $run(); } catch (Throwable $e) { check(true,$name);return; } check(false,$name); }
$receipt=['tipo_comprobante'=>'boleta','tipo_documento'=>'SIN_DOCUMENTO','numero_documento'=>'','cliente_nombre'=>'CONSUMIDOR FINAL','cliente_direccion'=>'','documento_consultado'=>0,'api_proveedor'=>null];
$db=new mysqli();$key=pagoNuevaClave();$input=['confirmado'=>'1','recibido'=>'20.00'];
$result=ventaRegistrarHabitual($db,pagoLineasValidas(['1','2'],['2','3']),'Efectivo',$receipt,1,1,$key,$input);
check(count($db->sales)===1 && $result['total']===17.5,'Registro normal inserta venta aunque no existan tablas de pagos');
check($db->sales[1][0]==='17.50','Importe DECIMAL se envía como texto exacto');
check($db->products[1]['stock']===8 && $db->products[2]['stock']===17,'Se descuenta el stock de ambas líneas');
check(count($db->detail)===2 && count($db->movements)===2,'Detalle e inventario acompañan a la venta');
check($result['vuelto_centimos']===250,'Vuelto exacto: S/ 2.50');
check($db->movements[0][3]==='Venta #1','El motivo se conserva como texto');
$movementQuery=array_values(array_filter($db->queries,fn($q)=>str_starts_with($q[0],'INSERT INTO movimientos_inventario')))[0];
check($movementQuery[2]==='isisii','Tipos de parámetros de inventario correctos');
check($db->commits===1 && $db->rollbacks===0,'Venta confirmada una vez');
$again=ventaRegistrarHabitual($db,[1=>2,2=>3],'Efectivo',$receipt,1,1,$key,$input);
check(count($db->sales)===1 && $again===$result && $db->commits===1,'Reenvío dentro de la misma sesión recupera la venta sin duplicarla');
check(!array_filter($db->queries,fn($q)=>str_contains($q[0],'INSERT INTO pagos_')),'El flujo habitual no crea pagos de prueba ni consulta una API');
foreach (['Yape','Plin','Tarjeta'] as $method) {
    $d=new mysqli();$r=ventaRegistrarHabitual($d,[1=>1],$method,$receipt,1,1,pagoNuevaClave(),['confirmado'=>'1','referencia_pago'=>'OPER-123']);
    check(count($d->sales)===1 && $d->sales[1][1]===$method && $d->sales[1][2]==='OPER-123' && $r['recibido_centimos']===null,'Registro habitual '.$method.' conserva referencia sin simulación automática');
}
foreach (['stock','dinero','caja','producto','confirmacion','clave','detalle','movimiento'] as $scenario) {
    $d=new mysqli();$in=$input;$k=pagoNuevaClave();$lines=[1=>2,2=>3];
    if ($scenario==='stock') $d->products[2]['stock']=1;
    if ($scenario==='dinero') $in['recibido']='1.00';
    if ($scenario==='caja') $d->box['estado']='cerrada';
    if ($scenario==='producto') unset($d->products[2]);
    if ($scenario==='confirmacion') $in['confirmado']='0';
    if ($scenario==='clave') $k=str_repeat('0',64);
    if ($scenario==='detalle') $d->fail='INSERT INTO detalle_venta';
    if ($scenario==='movimiento') $d->fail='INSERT INTO movimientos_inventario';
    $before=$d->products;
    rejected(fn()=>ventaRegistrarHabitual($d,$lines,'Efectivo',$receipt,1,1,$k,$in),'Rechazo controlado: '.$scenario);
    check(!$d->sales && !$d->detail && !$d->movements && $d->products===$before,'Sin escritura parcial: '.$scenario);
}
check(pagoLineasValidas(['2','1','1'],['1','2','3'])===[1=>5,2=>1],'Productos repetidos se agrupan y ordenan');
rejected(fn()=>validarCamposTexto(['nombre'=>['x']],['nombre']),'Campos manipulados como arrays se rechazan antes de trim');
check(!pagoSchemaReady(new mysqli()),'Una ampliación vacía o parcial no se declara lista');
echo "RESULTADO: $pass comprobaciones aprobadas con DOBLE EN MEMORIA, sin MySQL ni pasarela.\n";
