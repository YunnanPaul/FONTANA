<?php
/** Preparación del cobro en sesión: solo lecturas, ninguna tabla nueva. */
require_once __DIR__ . '/ventas_core.php';

function ventaPrepararCobro(mysqli $db, array $lines, string $method, array $receipt,
    int $user, int $cash, string $key, array $draft): array {
    if (!preg_match('/^[a-f0-9]{64}$/D', $key) || !isset($_SESSION['pago_formularios'][$key])) {
        throw new InvalidArgumentException('El formulario venció. Recarga Ventas antes de continuar.');
    }
    if (!$lines || !in_array($method, paymentMethods(), true)) throw new InvalidArgumentException('Selecciona productos y un método de pago válido.');
    if (isset($_SESSION['ventas_finalizadas'][$key])) throw new RuntimeException('Esta venta ya fue registrada. Revisa el historial antes de repetirla.');
    ksort($lines, SORT_NUMERIC);
    $existing = $_SESSION['ventas_por_cobrar'][$key] ?? null;
    if (is_array($existing)) {
        if ((int)$existing['id_usuario'] !== $user || (int)$existing['id_cierre'] !== $cash ||
            $existing['lineas'] !== $lines || $existing['metodo'] !== $method || $existing['comprobante'] !== $receipt) {
            throw new RuntimeException('Este pedido ya tiene un cobro abierto. Vuelve al pedido desde esa ventana antes de cambiarlo.');
        }
        // Una preparación ya mostrada no cambia de importe bajo la misma clave.
        return $existing;
    }
    pagoBegin($db);
    try {
        $box = pagoUno($db, 'SELECT *, fecha=CURDATE() AS es_hoy FROM cierres_caja WHERE id_cierre=? FOR UPDATE', [$cash]);
        if (!$box || $box['estado'] !== 'abierta' || !(int)$box['es_hoy']) throw new RuntimeException('Debes abrir la caja del día antes de continuar.');
        $total = 0; $prices = []; $items = [];
        foreach ($lines as $id => $qty) {
            if ((int)$id < 1 || (int)$qty < 1 || (int)$qty > 99999) throw new InvalidArgumentException('Cantidad no válida.');
            $product = pagoUno($db, 'SELECT * FROM productos WHERE id_producto=? FOR UPDATE', [(int)$id]);
            if (!$product || $product['tipo'] !== 'producto') throw new RuntimeException('Uno de los productos ya no está disponible.');
            $available = (int)$product['stock'] - pagoReservado($db, (int)$id);
            if ($qty > $available) throw new RuntimeException('Stock insuficiente para ' . $product['nombre'] . '. Disponible: ' . max(0, $available) . '.');
            $price = pagoCentimos((string)$product['precio']);
            if ($price <= 0) throw new RuntimeException('Revisa el precio de ' . $product['nombre'] . '.');
            $total += $price * $qty;
            if ($total > 9999999999) throw new RuntimeException('El total supera el tamaño permitido.');
            $prices[(int)$id] = $price;
            $items[] = ['nombre' => $product['nombre'], 'cantidad' => $qty, 'subtotal_centimos' => $price * $qty];
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); throw $e; }
    $pending = ['clave' => $key, 'id_usuario' => $user, 'id_cierre' => $cash, 'lineas' => $lines,
        'precios' => $prices, 'metodo' => $method, 'comprobante' => $receipt, 'total_centimos' => $total,
        'items' => $items, 'borrador' => $draft, 'vence' => time() + 900,
        'referencia_pago' => (string)($draft['referencia_pago'] ?? ''), 'recibido' => (string)($draft['recibido'] ?? '')];
    foreach ($_SESSION['ventas_por_cobrar'] ?? [] as $id => $entry) {
        if ((int)($entry['vence'] ?? 0) < time() - 86400) unset($_SESSION['ventas_por_cobrar'][$id]);
    }
    $_SESSION['ventas_por_cobrar'][$key] = $pending;
    if (count($_SESSION['ventas_por_cobrar']) > 100) array_shift($_SESSION['ventas_por_cobrar']);
    return $pending;
}

function ventaConfirmarPreparada(mysqli $db, string $key, int $user, array $input): array {
    $pending = $_SESSION['ventas_por_cobrar'][$key] ?? null;
    if (!is_array($pending) || (int)$pending['id_usuario'] !== $user) {
        throw new InvalidArgumentException('No hay un pedido preparado para confirmar. Vuelve a seleccionar los productos.');
    }
    if (!isset($_SESSION['ventas_finalizadas'][$key]) && (int)$pending['vence'] < time()) {
        throw new RuntimeException('Este cobro venció. Vuelve al pedido para verificar el total y las existencias.');
    }
    // Nunca se aceptan precios, cliente o líneas enviados desde la ventana de confirmación.
    $input['_precios_esperados'] = $pending['precios'];
    $input['_total_esperado'] = $pending['total_centimos'];
    return ventaRegistrarHabitual($db, $pending['lineas'], $pending['metodo'], $pending['comprobante'],
        $user, (int)$pending['id_cierre'], $key, $input);
}

/** Regresa al carrito sin escrituras; invalida la ventana vieja para todas las pestañas. */
function ventaVolverAlPedido(string $key, int $user): bool {
    $pending = $_SESSION['ventas_por_cobrar'][$key] ?? null;
    if (!is_array($pending) || (int)$pending['id_usuario'] !== $user) return false;
    if (!isset($_SESSION['ventas_finalizadas'][$key])) {
        $draft = $pending['borrador'];
        $draft['idempotencia'] = pagoNuevaClave();
        $_SESSION['venta_borrador'] = $draft;
        unset($_SESSION['pago_formularios'][$key]);
    }
    unset($_SESSION['ventas_por_cobrar'][$key]);
    return true;
}
