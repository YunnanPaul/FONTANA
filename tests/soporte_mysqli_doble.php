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
