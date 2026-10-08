"""Pruebas opcionales de interfaz; respuestas ficticias, sin cobros ni MySQL.
Requiere Python 3 y Playwright con Chromium instalado.
"""
import argparse,base64,json,os,re,shutil,subprocess,tempfile
from pathlib import Path
from playwright.sync_api import sync_playwright
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--salida',default=str(Path(tempfile.gettempdir())/'fontana-pruebas-ui'))
parser.add_argument('--chromium',default=shutil.which('chromium') or shutil.which('chromium-browser'))
parser.add_argument('--php',default='php')
args=parser.parse_args()
SYSTEM=Path(__file__).resolve().parent.parent
OUT=Path(args.salida).resolve(); OUT.mkdir(parents=True,exist_ok=True)
ROOT=OUT/'vistas'
subprocess.run([args.php,str(SYSTEM/'tests'/'generar_vistas.php'),str(ROOT)],check=True)
# Entorno de pruebas en memoria: no se navega ni se llama a servicios externos.
MOCK=r'''<script>
window.__calls=[];window.__phase='pendiente';window.__method='Efectivo';
window.fetch=async function(url,options={}){
 const d=options.body?JSON.parse(options.body):{};window.__calls.push(d);
 const base={estado:window.__phase,etiqueta:'Pendiente de cobro',total_centimos:1750,id_venta:null,pago_verificado:false,referencia:null,alerta:null,metodo:window.__method,entorno:window.__method==='Efectivo'?'manual':'sandbox',vuelto_centimos:null};
 let result;
 if(d.accion==='confirmar_manual'){result={ok:true,orden:{...base,estado:'pagado',etiqueta:'Pago confirmado · venta registrada',id_venta:77,pago_verificado:true,vuelto_centimos:250}};}
 else if(d.accion==='preparar'){window.__phase='en_proceso';result={ok:true,checkout:{sdk:'https://sandbox-checkout.izipay.pe/payments/v1/js/index.js',authorization:'MOCK_TOKEN',keyRSA:'MOCK_PUBLIC_KEY',config:{transactionId:'TEST12345',order:{amount:'17.50',currency:'PEN',payMethod:window.__method==='Tarjeta'?'CARD':'QR'}}}};}
 else {if(d.accion==='callback')window.__phase='prueba';result={ok:true,orden:{...base,estado:window.__phase,etiqueta:window.__phase==='prueba'?'Prueba aprobada · sin venta real':window.__phase==='en_proceso'?'Esperando confirmación de Izipay':'Pendiente de cobro',pago_verificado:window.__phase==='prueba'}};}
 return new Response(JSON.stringify(result),{status:200,headers:{'Content-Type':'application/json'}});
};
const originalAppend=Element.prototype.appendChild;
Element.prototype.appendChild=function(child){
 if(child.tagName==='SCRIPT'&&child.src==='https://sandbox-checkout.izipay.pe/payments/v1/js/index.js'){
  window.Izipay=class{constructor(x){window.testIziConfig=x.config;}LoadForm(p){window.testIziCallback=p.callbackResponse;}};
  setTimeout(()=>child.onload(),0);return child;
 }
 return originalAppend.call(this,child);
};
</script>'''
def html(name):
    s=(ROOT/name).read_text()
    s=re.sub(r'<link rel="stylesheet" href="([^"]+)">',lambda m:'<style>'+ (SYSTEM/m[1].split('?',1)[0]).read_text()+'</style>',s)
    s=re.sub(r'<script src="([^"]+)" defer></script>',lambda m:'<script>'+ (SYSTEM/m[1].split('?',1)[0]).read_text()+'</script>',s)
    s=re.sub(r'src="(img/[^"]+)"',lambda m:'src="data:image/png;base64,'+base64.b64encode((SYSTEM/m[1].split('?',1)[0]).read_bytes()).decode()+'"',s)
    return s.replace('</head>',MOCK+'</head>')
results=[]
def ok(name): results.append(name); print('OK ',name)
with sync_playwright() as p:
    launch={'headless':True}
    if args.chromium: launch['executable_path']=args.chromium
    if hasattr(os,'geteuid') and os.geteuid()==0: launch['args']=['--no-sandbox']
    browser=p.chromium.launch(**launch)
    page=browser.new_page(viewport={'width':1440,'height':1100},device_scale_factor=1)
    js_errors=[];page.on('pageerror',lambda e: js_errors.append(str(e)))
    def load(name):
        # Fresh page avoids poll timers from prior fixture. No HTTP/file URL needed.
        global page
        page.close();page=browser.new_page(viewport={'width':1440,'height':1100});page.on('pageerror',lambda e:js_errors.append(str(e)));page.set_content(html(name),wait_until='load')
    load('ventas.html')
    page.locator('#sale-lines select').select_option('1'); page.locator('#sale-lines input[name="cantidad[]"]').fill('2')
    assert page.locator('#cart-total').inner_text()=='S/ 10.00'
    page.locator('#add-line').click();page.locator('#sale-lines select').nth(1).select_option('2');page.locator('#sale-lines input[name="cantidad[]"]').nth(1).fill('3')
    assert page.locator('#cart-total').inner_text()=='S/ 17.50';ok('Total y subtotales: 2×5 + 3×2.50 = 17.50')
    page.screenshot(path=str(OUT/'01_ventas_escritorio.png'),full_page=True)
    page.locator('#sale-lines .remove-line').nth(1).click();page.wait_for_timeout(50);assert page.locator('#cart-total').inner_text()=='S/ 10.00'
    page.locator('#sale-lines .remove-line').click();page.wait_for_timeout(50);assert page.locator('#cart-total').inner_text()=='S/ 0.00';ok('Quitar líneas y limpiar última línea actualiza total')
    load('efectivo.html');page.locator('#recibido').fill('20');assert page.locator('#cash-change').inner_text()=='S/ 2.50';ok('Vuelto calculado antes de registrar venta')
    page.screenshot(path=str(OUT/'02_pago_efectivo_escritorio.png'),full_page=True)
    assert page.locator('#payment-result').is_hidden();page.locator('[name="confirmado"]').check();page.locator('#confirm-manual').click();page.wait_for_timeout(100)
    assert 'Venta #77' in page.locator('#result-detail').inner_text();assert page.locator('#manual-payment-form').is_hidden()
    calls=page.evaluate('window.__calls'); assert calls[0]['recibido']=='20' and calls[0]['confirmado']=='1' and 'total' not in calls[0];ok('Venta manual aparece después de respuesta simulada; no se envía total del navegador')
    load('yape.html');page.evaluate("window.__method='Yape'");page.locator('#launch-checkout').click();page.wait_for_function('window.testIziConfig !== undefined')
    assert page.evaluate('window.testIziConfig.order.amount')=='17.50';assert page.evaluate('window.testIziConfig.order.payMethod')=='QR';assert page.locator('#payment-result').is_hidden()
    calls=page.evaluate('window.__calls');assert calls[0]['billing']['email']=='prueba@example.com' and 'amount' not in calls[0];ok('Checkout simulado recibe QR/PEN/17.50 sin marcar pago al abrirse')
    page.screenshot(path=str(OUT/'03_pago_qr_preparacion.png'),full_page=True)
    page.evaluate("window.testIziCallback({payloadHttp:'FIRMADO_DE_PRUEBA',signature:'FIRMA_DE_PRUEBA'})");page.wait_for_timeout(100)
    assert 'sin venta real' in page.locator('#result-title').inner_text();assert any(c.get('accion')=='callback' for c in page.evaluate('window.__calls'));ok('Callback se remite a backend; sandbox se muestra separado de ventas')
    load('yape.html');page.evaluate("window.__method='Yape'");page.locator('#launch-checkout').click();page.wait_for_function('window.testIziCallback !== undefined');page.evaluate("window.testIziCallback({code:'00'})");page.wait_for_timeout(100)
    assert not any(c.get('accion')=='callback' for c in page.evaluate('window.__calls'));assert page.locator('#payment-result').is_hidden();ok('Code 00 sin firma no confirma el pago')
    load('tarjeta.html');page.evaluate("window.__method='Tarjeta'");page.locator('#launch-checkout').click();page.wait_for_function('window.testIziConfig !== undefined');assert page.evaluate('window.testIziConfig.order.payMethod')=='CARD';assert page.locator('input[name=cardNumber],input[name=cvv],input[autocomplete=cc-number]').count()==0;ok('Tarjetas usan checkout CARD y no campos locales de tarjeta/CVV')
    mobile=browser.new_page(viewport={'width':390,'height':844},device_scale_factor=1)
    mobile.set_content(html('efectivo.html'));mobile.screenshot(path=str(OUT/'04_pago_movil.png'),full_page=True)
    overflow=mobile.evaluate('({width:innerWidth, scroll:document.documentElement.scrollWidth})');print('MOBILE_WIDTH',overflow)
    assert overflow['scroll']<=overflow['width']+1;ok('Vista móvil a 390 px sin desbordamiento horizontal')
    assert not js_errors,js_errors;ok('Sin errores JavaScript en los recorridos probados')
    browser.close()
(OUT/'resultados.json').write_text(json.dumps({'passed':len(results),'tests':results,'note':'Pruebas de interfaz en memoria con API/SDK simulados. No prueban MySQL ni operaciones reales.'},ensure_ascii=False,indent=2))
print('RESULTADO',len(results),'pruebas de interfaz aprobadas')
