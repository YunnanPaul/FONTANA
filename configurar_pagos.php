<?php
session_start();
require 'conexion.php';
require_once 'includes/izipay.php';
require_once 'includes/instalacion_pagos.php';
requireLogin(); requireAdmin();
header('Cache-Control: no-store, private');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) { setFlash('error','El formulario venció. Recarga esta página.'); header('Location: configurar_pagos.php'); exit; }
    if (($_POST['respaldo'] ?? '')!=='1') { setFlash('error','Confirma que tienes un respaldo antes de instalar.'); header('Location: configurar_pagos.php'); exit; }
    try {
        $installation=fontanaInstalarPagos($conn);
        setFlash('success','Ampliación verificada en '.$installation['database'].'. No se borraron registros. Las ventas habituales siguen disponibles.');
    } catch (Throwable $e) {
        error_log('Fontana: instalación de pagos falló; código '.$e->getCode());
        setFlash('error',$e instanceof mysqli_sql_exception ? 'MySQL no pudo completar la ampliación. Revisa permisos, tipos de columnas y el log. Las operaciones DDL ya completadas permanecen; repite solo tras revisar. No restaures el SQL base.' : $e->getMessage());
    }
    header('Location: configurar_pagos.php'); exit;
}
$pageTitle='Configuración de pagos'; $activePage='pagos';
$ready=pagoSchemaReady($conn) && pagoAcademicoSchemaReady($conn);
try { $config=pagoConfig(); $errors=pagoConfigErrors(); }
catch (Throwable $e) { $config=['mode'=>'ERROR DE CONFIGURACIÓN','base_url'=>'','academic_record_sales'=>false]; $errors=['Revisa config/pagos.php. Su contenido impide usar pagos avanzados; no afecta las ventas habituales.']; }
$currentDatabase=pagoUno($conn,'SELECT DATABASE() nombre')['nombre'];
include 'includes/header.php';
?>
<div class="grid-two">
<section class="form-panel">
    <h2>1. Preparar la base de datos</h2>
    <p><a href="diagnostico_sistema.php">Ver diagnóstico de conexión y tablas (sin migrar)</a></p>
    <p>Base seleccionada: <strong><?php echo h($currentDatabase); ?></strong>. No se cambia ni renombra durante la ampliación.</p>
    <p>Estado: <strong><?php echo $ready ? 'Tablas de pagos instaladas' : 'Ampliación pendiente'; ?></strong>.</p>
    <p>Este instalador añade tablas de órdenes, líneas y eventos. No borra ventas, productos ni usuarios. También añade el total de efectivo para cierres nuevos.</p>
    <div class="pay-notice warning">Realiza primero una copia de archivos y base de datos. Para actualizar utiliza este instalador. El SQL base de esta entrega ya es no destructivo, pero es para instalaciones nuevas; no repara estructuras incompletas ni sustituye un respaldo.</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
      <label class="pay-check"><input type="checkbox" name="respaldo" value="1" required> Tengo un respaldo y estoy instalando sin usuarios operando.</label>
      <button type="submit">Instalar / verificar ampliación</button>
    </form>
</section>
<section class="panel">
    <h2>Operación de cobros</h2>
    <p>Flujo de ventas: <strong><?php echo ($config['checkout_flow'] ?? 'normal') === 'integrado' ? 'Cobro integrado' : 'Confirmación del cajero'; ?></strong>.</p>
    <p>Después de seleccionar los productos, pulsa <strong>Proceder con la venta</strong>. La pantalla del método elegido se abre automáticamente.</p>
    <?php if (in_array($config['mode'], ['demo','mercadopago_sandbox','sandbox'], true) && ($config['checkout_flow'] ?? 'normal') === 'integrado'): ?>
      <p class="payment-test-note"><strong>Modo de prueba · No se realizan cobros reales.</strong></p>
      <p><?php echo !empty($config['academic_record_sales']) ? 'El registro completo está habilitado únicamente para la base de pruebas autorizada.' : 'Las aprobaciones se guardan en pagos, sin modificar ventas ni existencias.'; ?></p>
    <?php endif; ?>
    <?php if ($errors): ?><div class="pay-notice warning">El servicio de cobro requiere configuración. Revisa la guía incluida en el proyecto antes de activarlo.</div><?php endif; ?>
    <p>Las instrucciones de configuración se encuentran en <strong>LEEME_INTERFAZ.md</strong>, dentro de la carpeta del proyecto.</p>
    <a href="pagos.php" class="btn secondary">Ver pedidos</a>
</section>
</div>
<?php include 'includes/footer.php'; ?>
