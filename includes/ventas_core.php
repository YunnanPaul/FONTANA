<?php
/** Registro habitual, compatible con las tablas originales. No necesita pagos_*. */
require_once __DIR__ . '/pagos.php';

function ventaRegistrarHabitual(mysqli $db, array $lines, string $method, array $receipt,
    int $user, int $cash, string $key, array $input): array {
    if (!preg_match('/^[a-f0-9]{64}$/D', $key) || !isset($_SESSION['pago_formularios'][$key])) {
        throw new InvalidArgumentException('El formulario venció. Recarga Ventas antes de continuar.');
    }
    if (!in_array($method, paymentMethods(), true) || !$lines) {
        throw new InvalidArgumentException('Selecciona productos y un método de pago válido.');
    }
    if (($input['confirmado'] ?? '') !== '1') {
        throw new InvalidArgumentException('Confirma la recepción del pago antes de registrar la venta.');
    }
    $previous = $_SESSION['ventas_finalizadas'][$key] ?? null;
    if (is_array($previous) && (int)($previous['id_usuario'] ?? 0) === $user) {
        $exists = pagoUno($db, 'SELECT id_venta FROM ventas WHERE id_venta=?', [(int)$previous['venta']]);
        if ($exists) return $previous;
        throw new RuntimeException('Esta operación ya fue procesada y su registro cambió. Revisa el historial antes de repetirla.');
    }
    $reference = $method === 'Efectivo' ? '' : pagoTexto($input['referencia_pago'] ?? '', 50);
    $received = $method === 'Efectivo' ? pagoCentimos($input['recibido'] ?? '') : null;
    ksort($lines, SORT_NUMERIC);
    pagoBegin($db);
    try {
        // El mismo orden de bloqueos que el módulo de pagos: caja y luego productos.
        $box = pagoUno($db, 'SELECT *, fecha=CURDATE() AS es_hoy FROM cierres_caja WHERE id_cierre=? FOR UPDATE', [$cash]);
        if (!$box || $box['estado'] !== 'abierta' || !(int)$box['es_hoy']) {
            throw new RuntimeException('La caja del día ya no está abierta. No se registró la venta.');
        }
        $total = 0; $products = [];
        foreach ($lines as $id => $qty) {
            $id = (int)$id; $qty = (int)$qty;
            if ($id < 1 || $qty < 1 || $qty > 99999) throw new InvalidArgumentException('Cantidad no válida.');
            $product = pagoUno($db, 'SELECT * FROM productos WHERE id_producto=? FOR UPDATE', [$id]);
            if (!$product || $product['tipo'] !== 'producto') {
                throw new RuntimeException('Uno de los productos seleccionados ya no está disponible para venta.');
            }
            $available = (int)$product['stock'] - pagoReservado($db, $id);
            if ($qty > $available) throw new RuntimeException('Stock insuficiente para ' . $product['nombre'] . '. Disponible: ' . max(0, $available) . '.');
            $price = pagoCentimos((string)$product['precio']);
            if ($price <= 0) throw new RuntimeException('Revisa el precio de ' . $product['nombre'] . '.');
            if (isset($input['_precios_esperados']) && ($input['_precios_esperados'][$id] ?? null) !== $price) {
                throw new RuntimeException('El precio cambió desde que abriste el cobro. Vuelve al pedido para revisar el importe antes de confirmar.');
            }
            $subtotal = $price * $qty; $total += $subtotal;
            if ($total > 9999999999) throw new RuntimeException('El total supera el tamaño permitido en ventas.');
            $products[] = [$product, $qty, $price, $subtotal];
        }
        if (isset($input['_total_esperado']) && (int)$input['_total_esperado'] !== $total) {
            throw new RuntimeException('El importe del pedido cambió. No se registró la venta; revisa el pedido antes de continuar.');
        }
        if ($received !== null && $received < $total) {
            throw new InvalidArgumentException('El monto recibido es menor al total de la venta.');
        }
        pagoExec($db, 'INSERT INTO ventas(fecha,total,metodo_pago,referencia_pago,tipo_comprobante,tipo_documento,numero_documento,cliente_nombre,cliente_direccion,documento_consultado,api_proveedor) VALUES(NOW(),?,?,?,?,?,?,?,?,?,?)', [
            pagoDecimal($total), $method, $reference ?: null, $receipt['tipo_comprobante'],
            $receipt['tipo_documento'], $receipt['numero_documento'] ?: null,
            $receipt['cliente_nombre'], $receipt['cliente_direccion'] ?: null,
            (int)$receipt['documento_consultado'], $receipt['api_proveedor'] ?: null,
        ]);
        $sale = (int)$db->insert_id;
        foreach ($products as [$product, $qty, $price, $subtotal]) {
            $id = (int)$product['id_producto']; $old = (int)$product['stock']; $new = $old - $qty;
            pagoExec($db, 'INSERT INTO detalle_venta(id_venta,id_producto,cantidad,precio_unitario,subtotal) VALUES(?,?,?,?,?)', [$sale, $id, $qty, pagoDecimal($price), pagoDecimal($subtotal)]);
            if (pagoExec($db, 'UPDATE productos SET stock=stock-? WHERE id_producto=? AND stock>=?', [$qty, $id, $qty]) !== 1) {
                throw new RuntimeException('El stock cambió. No se registró una venta incompleta.');
            }
            pagoExec($db, 'INSERT INTO movimientos_inventario(id_producto,tipo_movimiento,cantidad,motivo,stock_anterior,stock_nuevo) VALUES(?,?,?,?,?,?)', [$id, 'salida', $qty, 'Venta #' . $sale, $old, $new]);
        }
        $db->commit();
        // La sesión permanece bloqueada durante el POST: doble clic / pestañas de
        // la misma sesión recuperan el resultado en vez de insertar otra venta.
        $result = ['venta' => $sale, 'metodo' => $method, 'total' => $total / 100,
            'referencia' => $reference, 'id_usuario' => $user,
            'recibido_centimos' => $received, 'vuelto_centimos' => $received === null ? null : $received - $total];
        $_SESSION['ventas_finalizadas'][$key] = $result;
        if (count($_SESSION['ventas_finalizadas']) > 100) array_shift($_SESSION['ventas_finalizadas']);
        return $result;
    } catch (Throwable $e) {
        $db->rollback(); throw $e;
    }
}
