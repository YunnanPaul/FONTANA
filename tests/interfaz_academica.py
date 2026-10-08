"""Regresión visual/funcional académica con API y SDK simulados explícitamente.
No conecta a MySQL ni Mercado Pago. PHP genera las vistas reales; Chromium
consume los JavaScript reales y se decodifica el QR con OpenCV.
Requisitos solo de desarrollo: Python, Playwright/Chromium, opencv-python, PHP CLI.
"""
import argparse, base64, json, mimetypes, os, re, shutil, subprocess, tempfile
from pathlib import Path
from urllib.parse import urlsplit
import cv2
from playwright.sync_api import sync_playwright

parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--salida',default=str(Path(tempfile.gettempdir())/'fontana-ui-academica'))
parser.add_argument('--php',default='php')
parser.add_argument('--chromium',default=shutil.which('chromium') or shutil.which('chromium-browser'))
args=parser.parse_args()
ROOT=Path(__file__).resolve().parent.parent
OUT=Path(args.salida).resolve(); OUT.mkdir(parents=True,exist_ok=True)
VIEWS=OUT/'vistas'
subprocess.run([args.php,str(ROOT/'tests/generar_academico.php'),str(VIEWS)],check=True)
TOKEN='b'*64; PUBLIC='a'*32
SDK=r'''window.MercadoPago=class {
 constructor(key,opts){window.__mpKey=key;window.__mpOpts=opts;}
 yape(data){window.__yapeData=data;return {create:async()=>({id:'FAKE_TOKEN_YAPE_123456',live_mode:false})};}
 bricks(){return {create:async(type,id,settings)=>{
 window.__brickSettings=settings;window.__brickType=type;
 const b=document.createElement('button');b.id='mock-submit-card';b.textContent='SDK simulado: enviar tarjeta tokenizada';
 b.onclick=()=>settings.callbacks.onSubmit({selectedPaymentMethod:'credit_card',formData:{token:'FAKE_TOKEN_CARD_123456',payment_method_id:'visa',installments:1,payer:{email:'test_user_fontana@testuser.com'}}});
 document.getElementById(id).appendChild(b);settings.callbacks.onReady();
 return {unmount:()=>{window.__unmounted=true;}};
 }};}
};'''
LABELS={'pendiente':'Pendiente de prueba','en_proceso':'Esperando resultado de prueba','prueba':'Prueba aprobada · sin venta real','rechazado':'Prueba rechazada','cancelado':'Prueba cancelada','vencido':'Reserva vencida','revision':'Requiere revisión'}
results=[]; errors=[]
def ok(name): results.append(name); print('OK',name,flush=True)

with sync_playwright() as pw:
    opts={'headless':True}
    if args.chromium: opts['executable_path']=args.chromium
    if hasattr(os,'geteuid') and os.geteuid()==0: opts['args']=['--no-sandbox']
    browser=pw.chromium.launch(**opts)
    context=None
    def scenario(fixture,provider='demo',method='Yape',state='pendiente',width=1440,qr_fail=False):
        global context, page, current, calls, behavior
        if context: context.close()
        context=browser.new_context(viewport={'width':width,'height':1000})
        current={'estado':state,'etiqueta':LABELS[state],'total_centimos':1750,'id_venta':None,'pago_verificado':False,'referencia':None,'alerta':None,'metodo':method,'entorno':'demo' if provider=='demo' else 'sandbox','proveedor':provider,'academico':True,'vence_en':900,'vuelto_centimos':None,'id_orden':101}
        calls=[]; behavior={'fail_payment':False,'resolve':'prueba','sdk_error':False,'fail_qr':qr_fail}
        def setstate(value):
            current['estado']=value; current['etiqueta']=LABELS[value]
            if value=='prueba': current['pago_verificado']=True;current['referencia']='DEMO-101-TEST'
        def mock_api(url, data):
            path=urlsplit(url).path.lstrip('/')
            calls.append({'path':path,'data':data,'url':url})
            action=data.get('accion','consultar_local')
            response={'ok':True}
            if action=='demo_preparar':
                if behavior['fail_qr']:
                    behavior['fail_qr']=False
                    return {'http_status':503,'ok':False,'message':'No se pudo conectar. Intenta nuevamente.'}
                setstate('en_proceso');response['demo']={'url':'http://127.0.0.1/pago_demo.php#t='+TOKEN,'vence_en':900,'total_centimos':1750}
            elif action in ['demo_resolver','resolver']:
                result=data.get('resultado');setstate({'aprobado':'prueba','rechazado':'rechazado','cancelado':'cancelado','pendiente':'en_proceso'}[result])
                if method=='Efectivo' and result=='aprobado':current['vuelto_centimos']=int(float(data['recibido'])*100)-1750
            elif action=='mp_configurar':response['checkout']={'sdk':'https://sdk.mercadopago.com/js/v2','public_key':'TEST-FONTANA_FAKE_PUBLIC_KEY','test_only':True,'amount':'17.50'}
            elif action=='mp_pagar':
                if behavior['fail_payment']:
                    setstate('en_proceso');return {'http_status':503,'ok':False,'message':'Resultado desconocido. Consulta la API antes de repetir.'}
                setstate(behavior['resolve'])
            elif action=='mp_consultar':setstate(behavior['resolve'])
            elif action=='cancelar':setstate('cancelado')
            response['orden']=dict(current)
            return response
        context.expose_function('__fontanaMockApi',mock_api)
        page=context.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
        page.set_content(render_html(fixture),wait_until='load');page.wait_for_timeout(100)
        return page
    def render_html(fixture, mobile_token=None):
        html=(VIEWS/(fixture+'.html')).read_text()
        html=re.sub(r'<link rel="stylesheet" href="([^"]+)">',lambda m:'<style>'+(ROOT/m[1].split('?',1)[0]).read_text()+'</style>',html)
        html=re.sub(r'<script src="([^"]+)" defer></script>',lambda m:'<script>document.addEventListener("DOMContentLoaded",function(){'+(ROOT/m[1].split('?',1)[0]).read_text()+'\n});</script>',html)
        html=re.sub(r'src="(img/[^"]+)"',lambda m:'src="data:image/png;base64,'+base64.b64encode((ROOT/m[1].split('?',1)[0]).read_bytes()).decode()+'"',html)
        mock="""<script>window.fetch=async(url,options={})=>{
          const data=options.body?JSON.parse(options.body):{};
          const r=await window.__fontanaMockApi(url,data);
          return new Response(JSON.stringify(r),{status:r.http_status||200,headers:{'Content-Type':'application/json'}});
        };
        const _append=Element.prototype.appendChild;
        Element.prototype.appendChild=function(child){
          if(child.tagName==='SCRIPT'&&child.src==='https://sdk.mercadopago.com/js/v2'){
            setTimeout(()=>{if(window.__sdkFail){child.onerror();return;}SDK_BODY;child.onload();},0);return child;
          }return _append.call(this,child);
        };</script>""".replace('SDK_BODY',SDK)
        if mobile_token:
            # about:blank fixture has an opaque origin: storage/history are explicit
            # doubles, not assertions about HTTP browser storage/security behavior.
            mobile_mock='<script>location.hash="t='+mobile_token+'";window.__historyRemoved=false;const _mem={};Object.defineProperty(window,"sessionStorage",{value:{setItem:(k,v)=>_mem[k]=v,getItem:k=>_mem[k]||null}});history.replaceState=(a,b,c)=>{window.__historyRemoved=true;location.hash="";};</script>'
            mock+=mobile_mock
        return html.replace('</head>',mock+'</head>')
    page=scenario('demo_yape')
    page.locator('#demo-qr canvas').wait_for()
    assert page.locator('#demo-open').get_attribute('href')=='http://127.0.0.1/pago_demo.php#t='+TOKEN
    assert '17.50' in page.locator('body').inner_text()
    ok('QR académico recibe importe fijo de S/ 17.50 y enlace de esa orden')
    assert page.locator('#demo-new-qr').count()==0 and page.locator('#demo-retry-qr').is_hidden() and len([c for c in calls if c['data'].get('accion')=='demo_preparar'])==1
    ok('QR se prepara una sola vez automáticamente y no existe botón Mostrar QR')
    page.locator('#demo-qr canvas').screenshot(path=str(OUT/'qr_real.png'))
    decoded,_,_=cv2.QRCodeDetector().detectAndDecode(cv2.imread(str(OUT/'qr_real.png')))
    assert decoded=='http://127.0.0.1/pago_demo.php#t='+TOKEN,repr(decoded)
    ok('QR real decodificado: contiene exactamente el enlace académico con token')
    page.screenshot(path=str(OUT/'01_qr_academico.png'),full_page=True)
    assert page.locator('#payment-result').is_hidden();ok('Mostrar un QR no aprueba la prueba')
    mobile=context.new_page();mobile.set_viewport_size({'width':390,'height':844});mobile.on('pageerror',lambda e:errors.append(str(e)))
    mobile.set_content(render_html('pago_demo',TOKEN),wait_until='load')
    mobile.wait_for_function("document.querySelector('#demo-mobile-total').textContent==='S/ 17.50'")
    assert mobile.locator('#demo-mobile-total').inner_text()=='S/ 17.50'
    assert mobile.evaluate('window.__historyRemoved')
    ok('Vista móvil carga el total y solicita retirar el token (history/storage simulados)')
    assert all(TOKEN not in c['url'] for c in calls);ok('El token del QR no viaja en parámetros HTTP ni en URL del endpoint')
    assert mobile.locator('input[autocomplete=cc-number], input[name=cvv]').count()==0
    mobile.screenshot(path=str(OUT/'02_qr_movil.png'),full_page=True)
    assert mobile.evaluate('document.documentElement.scrollWidth <= innerWidth+1');ok('Simulador móvil a 390 px sin desbordamiento ni datos bancarios')
    mobile.locator('[data-mobile-result="aprobado"]').click()
    mobile.wait_for_function("document.querySelector('#demo-mobile-result').textContent.includes('Prueba aprobada')")
    page.locator('#refresh-status').click();page.wait_for_function("document.querySelector('#result-title').textContent.includes('aprobada')")
    assert page.locator('#test-receipt').is_visible() and page.locator('#academic-workspace').is_hidden()
    ok('Aprobación desde celular sincroniza cajero y habilita comprobante académico')
    page.screenshot(path=str(OUT/'03_prueba_aprobada.png'),full_page=True)
    page=scenario('demo_plin',method='Plin')
    assert 'Modo de prueba' in page.locator('.payment-test-note').inner_text() and 'no es un QR bancario' in page.locator('#academic-workspace').inner_text()
    page.locator('#academic-workspace summary').click()
    page.locator('[data-demo-result="pendiente"]').click();page.wait_for_timeout(150)
    assert page.locator('#payment-result').is_hidden();ok('Plin se identifica como simulador y pendiente no se registra como aprobado')
    page.locator('[data-demo-result="rechazado"]').click();page.wait_for_function("document.querySelector('#result-title').textContent.includes('rechazada')")
    assert page.locator('#test-receipt').is_hidden();ok('Rechazo cierra la prueba sin comprobante aprobado')
    page=scenario('demo_efectivo',method='Efectivo')
    page.locator('#demo-cash').fill('20');assert page.locator('#demo-change').inner_text()=='S/ 2.50'
    page.locator('[data-demo-result="aprobado"]').click();page.wait_for_function("document.querySelector('#result-title').textContent.includes('aprobada')")
    last=[x['data'] for x in calls if x['data'].get('accion')=='demo_resolver'][-1]
    assert last['recibido']=='20' and 'total' not in last and 'amount' not in last
    ok('Efectivo de prueba calcula S/ 2.50 de vuelto y no envía un total manipulable')
    page=scenario('demo_tarjeta',method='Tarjeta',width=360)
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth+1')
    assert page.locator('input[autocomplete=cc-number],input[name=cvv]').count()==0
    ok('Tarjeta local es una simulación explícita, sin datos de tarjeta y adaptable a 360 px')
    page=scenario('demo_vencido',state='vencido')
    assert page.locator('#demo-qr canvas').count()==0 and page.locator('#payment-result').is_visible()
    ok('Orden vencida no genera QR ni permite una aprobación desde su interfaz')
    page=scenario('demo_yape',qr_fail=True)
    page.locator('#demo-retry-qr').wait_for(state='visible')
    assert page.locator('#demo-qr canvas').count()==0 and page.locator('#payment-result').is_hidden()
    ok('Fallo al preparar el QR ofrece reintento sin confirmar pago')
    page.locator('#demo-retry-qr').click();page.locator('#demo-qr canvas').wait_for()
    assert page.locator('#demo-retry-qr').is_hidden() and len([c for c in calls if c['data'].get('accion')=='demo_preparar'])==2
    ok('Recuperar conexión muestra QR y vuelve a ocultar el botón de reintento')
    page=scenario('mp_yape',provider='mercadopago')
    page.locator('#mp-yape-submit').click();page.wait_for_function("document.querySelector('#result-title').textContent.includes('aprobada')")
    assert page.evaluate('window.__mpKey').startswith('TEST-')
    assert page.evaluate('window.__yapeData')=={'phoneNumber':'111111111','otp':'123456'}
    sent=[c['data'] for c in calls if c['data'].get('accion')=='mp_pagar'][0]
    assert sent['formData']['payment_method_id']=='yape' and 'phoneNumber' not in sent['formData'] and 'otp' not in sent['formData']
    assert 'transaction_amount' not in sent['formData']
    ok('Yape tokeniza celular/OTP de prueba; backend recibe token y no el importe del navegador')
    assert 'Prueba aprobada' in page.locator('#result-detail').inner_text() and 'No se registró una venta' in page.locator('#result-detail').inner_text();ok('Resultado de prueba se identifica sin tecnicismos ni venta ficticia')
    page=scenario('mp_yape',provider='mercadopago');behavior['resolve']='rechazado'
    page.locator('#mp-test-phone').select_option('111111113');page.locator('#mp-yape-submit').click()
    page.wait_for_function("document.querySelector('#result-title').textContent.includes('rechazada')")
    assert page.locator('#test-receipt').is_hidden();ok('Respuesta rechazada del adaptador no se convierte en aprobación')
    page=scenario('mp_tarjeta',provider='mercadopago',method='Tarjeta')
    page.locator('#mp-load-card').click();page.locator('#mock-submit-card').wait_for()
    assert page.evaluate('window.__brickSettings.initialization.amount')==17.5
    assert page.evaluate('window.__brickType')=='payment'
    page.screenshot(path=str(OUT/'04_tarjeta_api_formulario_sdk_simulado.png'),full_page=True)
    page.locator('#mock-submit-card').click();page.wait_for_function("document.querySelector('#result-title').textContent.includes('aprobada')")
    form=[c['data']['formData'] for c in calls if c['data'].get('accion')=='mp_pagar'][0]
    assert form['token']=='FAKE_TOKEN_CARD_123456' and 'card_number' not in form and 'cvv' not in form
    ok('Payment Brick recibe S/ 17.50 y envía token, no PAN/CVV, a Fontana')
    page=scenario('mp_yape',provider='mercadopago');behavior['fail_payment']=True
    page.locator('#mp-yape-submit').click();page.wait_for_function("document.querySelector('#payment-message').textContent.includes('desconocido')")
    assert page.locator('#payment-result').is_hidden() and page.locator('#mp-yape-form').is_hidden()
    assert len([c for c in calls if c['data'].get('accion')=='mp_pagar'])==1
    ok('Error de comunicación conserva resultado desconocido y bloquea reenvío accidental')
    # Base polling every 4.5 seconds must not starve external reconciliation at 10s.
    page.wait_for_function("document.querySelector('#result-title').textContent.includes('aprobada')",timeout=16000)
    assert any(c['data'].get('accion')=='mp_consultar' for c in calls)
    assert len([c for c in calls if c['data'].get('accion')=='mp_pagar'])==1
    ok('Consulta API automática funciona junto al polling local y recupera sin crear otro pago')
    page=scenario('mp_yape',provider='mercadopago');page.evaluate('window.__sdkFail=true')
    page.locator('#mp-yape-submit').click();page.wait_for_function("document.querySelector('#payment-message').textContent.includes('No se pudo cargar')")
    assert not any(c['data'].get('accion')=='mp_pagar' for c in calls)
    assert page.locator('#payment-result').is_hidden();ok('Fallo de carga del SDK muestra error sin enviar ni aprobar el pago')
    assert not errors,errors;ok('Sin excepciones JavaScript en todos los escenarios académicos probados')
    browser.close()
(OUT/'resultados.json').write_text(json.dumps({'passed':len(results),'tests':results,'note':'Respuestas API/SDK simuladas. No valida MySQL ni API externa real.'},ensure_ascii=False,indent=2))
print('RESULTADO',len(results),'pruebas de interfaz académica aprobadas')
