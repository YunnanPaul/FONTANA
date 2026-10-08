<?php
/** Real template rendering with fixtures; no controllers, SQL, provider or network. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/pagos.php';
session_start();date_default_timezone_set('America/Lima');
$_SESSION['usuario']=['id_usuario'=>1,'nombre'=>'Administrador prueba','usuario'=>'test','rol'=>'administrador'];
set_error_handler(function($severity,$message,$file,$line) { throw new ErrorException($message,0,$severity,$file,$line); });
$dest=$argv[1] ?? sys_get_temp_dir().'/fontana-modulos';if(!is_dir($dest))mkdir($dest,0700,true);
function renderFixture(string $file,string $name,array $vars): void {
    global $dest;
    extract($vars,EXTR_SKIP);
    $activePage=$file==='index.php' ? 'dashboard' : ($file==='configurar_pagos.php' ? 'pagos' : basename($file,'.php'));
    $source=file_get_contents(__DIR__.'/../'.$file);
    $start=strpos($source,"include 'includes/header.php';");
    if ($start===false) throw new RuntimeException('Missing template marker: '.$file);
    $source=substr($source,$start);
    foreach (['header','footer','venta_resultado'] as $include) $source=str_replace("include 'includes/".$include.".php';",'include '.var_export(__DIR__.'/../includes/'.$include.'.php',true).';',$source);
    ob_start();try { eval($source);$html=ob_get_clean(); } catch(Throwable $e) { ob_end_clean();throw $e; }
    if (!str_contains($html,'</html>'))throw new RuntimeException('Incomplete HTML: '.$name);
    file_put_contents($dest.'/'.$name.'.html',$html);echo 'OK '.$name."\n";
}
$base=['pageTitle'=>'Prueba de plantilla','activePage'=>'dashboard','cajaAbierta'=>null,'schemaReady'=>true,'totalProductos'=>0,'totalInsumos'=>0,'stockBajo'=>0,'ventasHoy'=>0,'ingresosHoy'=>0,'unidadesStock'=>0,'productosCriticos'=>[],'movimientosHoy'=>0,'ventasMes'=>0,'ultimasVentas'=>[]];
$cases=[];
$cases[]=['index.php','dashboard',$base];
$prod=['id_producto'=>1,'nombre'=>'Producto prueba','descripcion'=>'Descripción','tipo'=>'producto','precio'=>'5.00','stock'=>10,'stock_minimo'=>2,'unidad_medida'=>'unidad','categoria'=>'general'];
$cases[]=['productos.php','productos_alta',$base+['tiposValidos'=>['producto','insumo'],'editarId'=>0,'productoEditar'=>null,'formData'=>array_replace($prod,['id_producto'=>0,'nombre'=>'','precio'=>'']),'buscar'=>'','productos'=>[]]];
$cases[]=['productos.php','productos_edicion',$base+['tiposValidos'=>['producto','insumo'],'editarId'=>1,'productoEditar'=>$prod,'formData'=>$prod,'buscar'=>'','productos'=>[$prod]]];
$cases[]=['inventario.php','inventario',$base+['productos'=>[$prod],'movimientos'=>[]]];
$cases[]=['reposicion.php','reposicion',$base+['esAdmin'=>true,'productosStockBajo'=>[],'todosProductos'=>[$prod],'solicitudes'=>[]]];
$cases[]=['usuarios.php','usuarios',$base+['editarId'=>0,'usuarioEditar'=>null,'formData'=>['id_usuario'=>0,'nombre'=>'','usuario'=>'','rol'=>'cajero'],'usuarios'=>[],'usuariosEnLinea'=>[]]];
$cases[]=['reportes.php','reportes',$base+['desde'=>'2026-09-01','hasta'=>'2026-09-06','totalPeriodo'=>0,'cantidadVentas'=>0,'ventas'=>[],'topProductos'=>[]]];
$box=['id_cierre'=>1,'fecha'=>'2026-09-06','estado'=>'abierta','monto_inicial'=>'20.00','total_ventas'=>'17.50','monto_final'=>'37.50','diferencia'=>'0.00','observacion'=>'PRUEBA','nombre_usuario_apertura'=>'Admin','nombre_usuario_cierre'=>null];
$cases[]=['caja.php','caja_original_sin_apertura',$base+['tieneTotalEfectivo'=>false,'efectivoHoy'=>0,'ultimoCierre'=>[]]];
$cases[]=['caja.php','caja_original_abierta',array_replace($base,['cajaAbierta'=>$box,'tieneTotalEfectivo'=>false,'ventasHoy'=>17.50,'efectivoHoy'=>10.00,'ultimoCierre'=>[$box]])];
$cases[]=['caja.php','caja_ampliada_abierta',array_replace($base,['cajaAbierta'=>$box,'tieneTotalEfectivo'=>true,'ventasHoy'=>17.50,'efectivoHoy'=>10.00,'ultimoCierre'=>[$box]])];
$cases[]=['pagos.php','pagos_sin_instalar',$base+['ready'=>false]];
$cases[]=['pagos.php','pagos_instalado_vacio',$base+['ready'=>true,'states'=>['todos','pendiente'],'filter'=>'todos','orders'=>[],'page'=>1,'offset'=>0,'count'=>0]];
$cases[]=['configurar_pagos.php','configuracion',$base+['ready'=>false,'config'=>pagoConfig(),'errors'=>[],'currentDatabase'=>'fontana_pruebas']];
foreach ($cases as [$file,$name,$vars]) renderFixture($file,$name,$vars);
echo 'RESULTADO: '.count($cases)." plantillas renderizadas sin avisos PHP con DATOS FIXTURE, sin SQL.\n";
