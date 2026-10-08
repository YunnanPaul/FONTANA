<?php
/** Ampliación aditiva y repetible. Nunca restaura datos ni cambia DATABASE(). */
require_once __DIR__.'/pagos.php';

function fontanaInstalarPagos(mysqli $db): array {
    $locked=false;
    try {
        $lock=pagoUno($db,"SELECT GET_LOCK(CONCAT('fontana:',MD5(DATABASE()),':pagos_install'),10) acquired");
        $locked=(int)($lock['acquired'] ?? 0)===1;
        if (!$locked) throw new RuntimeException('Otra instalación está en curso. No se modificó la base en este intento.');
        $required=[
            'usuarios'=>['id_usuario','nombre','usuario','clave','rol','estado'],
            'productos'=>['id_producto','nombre','tipo','precio','stock','stock_minimo','unidad_medida'],
            'ventas'=>['id_venta','fecha','total','metodo_pago'],
            'detalle_venta'=>['id_detalle','id_venta','id_producto','cantidad','precio_unitario','subtotal'],
            'movimientos_inventario'=>['id_movimiento','id_producto','tipo_movimiento','cantidad','motivo','stock_anterior','stock_nuevo'],
            'cierres_caja'=>['id_cierre','fecha','estado','monto_inicial','total_ventas','monto_final'],
        ];
        if (!pagoVerificarColumnas($db,$required)) throw new RuntimeException('La base seleccionada no coincide con las tablas originales requeridas. No se eliminará ni reconstruirá ninguna tabla. Revisa el diagnóstico y la conexión.');
        $tables=pagoTodos($db,"SELECT TABLE_NAME,ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('usuarios','productos','ventas','detalle_venta','movimientos_inventario','cierres_caja')");
        foreach ($tables as $t) if (strtoupper((string)$t['ENGINE'])!=='INNODB') throw new RuntimeException('La tabla '.$t['TABLE_NAME'].' no usa InnoDB. Se detuvo la ampliación antes de crear tablas; revisa una conversión con respaldo.');
        // Match parent identifier types without changing the user's existing primary keys.
        $types=[];
        foreach (['id_usuario'=>'usuarios','id_producto'=>'productos','id_venta'=>'ventas','id_cierre'=>'cierres_caja'] as $col=>$table) {
            $row=pagoUno($db,'SELECT COLUMN_TYPE FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$col]);
            $type=strtoupper((string)($row['COLUMN_TYPE'] ?? ''));
            if (!preg_match('/^(?:INT|INTEGER)(?:\([0-9]+\))?(?: UNSIGNED)?$/D',$type)) throw new RuntimeException('Tipo de identificador no compatible en '.$table.'.'.$col.'. No se alterará esa tabla.');
            $types[$col]=str_contains($type,'UNSIGNED') ? 'INT UNSIGNED' : 'INT';
        }
        if (!ensureSalesDocumentSchema($db)) throw new RuntimeException('No se pudieron verificar las columnas de comprobante. Revisa permisos y conserva tus datos.');
        foreach (['migracion_pagos_api.sql','migracion_pagos_academicos.sql'] as $name) {
            $sql=file_get_contents(__DIR__.'/../sql/'.$name);
            if ($sql===false) throw new RuntimeException('Falta el archivo de ampliación '.$name.'.');
            $sql=preg_replace('/^\s*--.*$/m','',$sql);
            foreach ($types as $column=>$type) $sql=preg_replace('/\b'.preg_quote($column,'/').' INT(?=\s)/',$column.' '.$type,$sql);
            foreach (explode(';',$sql) as $statement) {
                $statement=trim($statement); if ($statement==='') continue;
                if (!preg_match('/^CREATE TABLE IF NOT EXISTS pagos_[a-z_]+\s*\(/i',$statement)) throw new RuntimeException('La migración contiene una instrucción no autorizada. Restaura ese archivo desde el ZIP.');
                $db->query($statement);
            }
        }
        if (!pagoSchemaReady($db) || !pagoAcademicoSchemaReady($db)) throw new RuntimeException('Las tablas de pagos están incompletas o corresponden a otra versión. No se borraron: revisa el diagnóstico antes de continuar.');
        $paymentTables=pagoTodos($db,"SELECT TABLE_NAME,ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('pagos_ordenes','pagos_lineas','pagos_eventos','pagos_demo','pagos_mercadopago')");
        foreach ($paymentTables as $t) if (strtoupper((string)$t['ENGINE'])!=='INNODB') throw new RuntimeException('La tabla '.$t['TABLE_NAME'].' requiere InnoDB. No se cambiará automáticamente.');
        $column=pagoUno($db,"SELECT COUNT(*) n FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cierres_caja' AND COLUMN_NAME='total_efectivo'");
        if (!(int)$column['n']) $db->query('ALTER TABLE cierres_caja ADD COLUMN total_efectivo DECIMAL(10,2) NULL AFTER total_ventas');
        $base=pagoUno($db,'SELECT DATABASE() nombre');
        return ['database'=>$base['nombre'],'payments_ready'=>true,'academic_ready'=>true];
    } finally {
        if ($locked) $db->query("SELECT RELEASE_LOCK(CONCAT('fontana:',MD5(DATABASE()),':pagos_install'))");
    }
}
