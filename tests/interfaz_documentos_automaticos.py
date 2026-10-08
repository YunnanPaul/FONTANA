"""Comprueba en navegador la consulta automática de DNI/RUC con respuestas controladas.
No llama a RENIEC, SUNAT, MySQL ni servicios externos.
"""
from pathlib import Path
import argparse
import base64
import json
import re
import shutil
import subprocess
import tempfile
from playwright.sync_api import sync_playwright

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--salida', default=str(Path(tempfile.gettempdir()) / 'fontana-documentos-auto'))
args = parser.parse_args()
root = Path(__file__).resolve().parent.parent
out = Path(args.salida).resolve()
out.mkdir(parents=True, exist_ok=True)
views = out / 'vistas'
subprocess.run(['php', str(root / 'tests/generar_vistas.php'), str(views)], check=True)


def rendered(name: str) -> str:
    text = (views / name).read_text(encoding='utf-8')
    text = re.sub(
        r'<link rel="stylesheet" href="([^"]+)">',
        lambda match: '<style>' + (root / match.group(1).split('?', 1)[0]).read_text(encoding='utf-8') + '</style>',
        text,
    )
    text = re.sub(
        r'<script src="([^"]+)" defer></script>',
        lambda match: '<script>' + (root / match.group(1).split('?', 1)[0]).read_text(encoding='utf-8') + '</script>',
        text,
    )
    text = re.sub(
        r'src="(img/[^"]+)"',
        lambda match: 'src="data:image/png;base64,' + base64.b64encode((root / match.group(1).split('?', 1)[0]).read_bytes()).decode() + '"',
        text,
    )
    mock = r'''<script>
    window.__documentCalls = [];
    window.__documentAborts = 0;
    window.fetch = function (url, options) {
        options = options || {};
        const params = new URLSearchParams(options.body || '');
        const type = params.get('tipo') || '';
        const number = params.get('numero') || '';
        window.__documentCalls.push({type, number, url: String(url)});
        const names = {
            '12345678': {nombre: 'JUAN PEREZ PRUEBA', direccion: ''},
            '87654321': {nombre: 'RESPUESTA ANTIGUA', direccion: ''},
            '11223344': {nombre: 'CLIENTE NUEVO', direccion: ''},
            '99999999': {nombre: 'CLIENTE ANTES DE CONTINUAR', direccion: ''},
            '20131312955': {nombre: 'EMPRESA FONTANA PRUEBA S.A.C.', direccion: 'AV. PRUEBA 123 - LIMA'}
        };
        const delay = number === '87654321' ? 700 : number === '99999999' ? 160 : 45;
        return new Promise((resolve, reject) => {
            const finish = () => {
                if (number === '00000000') {
                    resolve(new Response(JSON.stringify({ok:false,message:'Documento no encontrado.',data:null}), {
                        status: 404, headers: {'Content-Type':'application/json'}
                    }));
                    return;
                }
                const data = names[number] || {nombre: type === 'RUC' ? 'EMPRESA DE PRUEBA' : 'CLIENTE DE PRUEBA', direccion: type === 'RUC' ? 'DIRECCION DE PRUEBA' : ''};
                resolve(new Response(JSON.stringify({ok:true,message:'OK',data:{
                    tipo_documento:type, numero_documento:number, nombre:data.nombre, direccion:data.direccion,
                    proveedor:'PRUEBA', estado:type === 'RUC' ? 'ACTIVO' : '', condicion:type === 'RUC' ? 'HABIDO' : ''
                }}), {status:200,headers:{'Content-Type':'application/json'}}));
            };
            const timer = setTimeout(finish, delay);
            if (options.signal) {
                const abort = () => {
                    clearTimeout(timer);
                    window.__documentAborts++;
                    reject(new DOMException('Aborted', 'AbortError'));
                };
                if (options.signal.aborted) abort();
                else options.signal.addEventListener('abort', abort, {once:true});
            }
        });
    };
    </script>'''
    return text.replace('<head>', '<head>' + mock, 1)


results = []
errors = []


def ok(condition, name):
    assert condition, name
    results.append(name)
    print('OK', name, flush=True)


with sync_playwright() as playwright:
    executable = shutil.which('chromium') or shutil.which('chromium-browser')
    browser = playwright.chromium.launch(headless=True, args=['--no-sandbox'], executable_path=executable)

    def load(name='ventas_base_original.html', width=1366, height=900):
        page = browser.new_page(viewport={'width': width, 'height': height})
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.set_content(rendered(name), wait_until='load')
        return page

    page = load()
    ok(page.locator('#buscar-documento').count() == 0, 'No existe botón Buscar DNI/RUC')
    ok(page.locator('#lookup-badge').inner_text() == 'Automático', 'La consulta automática queda visible')

    document = page.locator('#numero_documento')
    document.fill('1234567')
    page.wait_for_timeout(550)
    ok(page.evaluate('window.__documentCalls.length') == 0, 'No consulta antes de completar 8 dígitos')
    ok('Faltan 1' in page.locator('#lookup-message').inner_text(), 'Informa cuántos dígitos faltan')

    document.fill('12345678')
    page.wait_for_function("document.querySelector('#cliente_nombre').value === 'JUAN PEREZ PRUEBA'")
    ok(page.evaluate('window.__documentCalls.length') == 1, 'DNI completo dispara una sola consulta')
    ok(page.locator('#lookup-badge').inner_text() == 'Verificado', 'DNI encontrado queda verificado')

    page.evaluate("""
        const input = document.querySelector('#numero_documento');
        const clipboard = new DataTransfer();
        clipboard.setData('text/plain', '12a34-5678');
        input.dispatchEvent(new ClipboardEvent('paste', {clipboardData: clipboard, bubbles: true, cancelable: true}));
    """)
    ok(document.input_value() == '12345678', 'Al pegar el DNI conserva únicamente dígitos')
    page.wait_for_timeout(500)
    ok(page.evaluate('window.__documentCalls.length') == 1, 'No repite una consulta ya resuelta')

    document.fill('87654321')
    page.wait_for_function('window.__documentCalls.some(c => c.number === "87654321")')
    document.fill('11223344')
    page.wait_for_function("document.querySelector('#cliente_nombre').value === 'CLIENTE NUEVO'")
    page.wait_for_timeout(800)
    ok(page.locator('#cliente_nombre').input_value() == 'CLIENTE NUEVO', 'Una respuesta antigua no sobrescribe el documento nuevo')
    ok(page.evaluate('window.__documentAborts >= 1'), 'La consulta anterior se cancela al cambiar el documento')

    page.locator('#tipo_comprobante').select_option('factura')
    ok(document.get_attribute('maxlength') == '11', 'Factura cambia automáticamente a RUC de 11 dígitos')
    document.fill('20131312955')
    page.wait_for_function("document.querySelector('#cliente_nombre').value === 'EMPRESA FONTANA PRUEBA S.A.C.'")
    ok(page.locator('#cliente_direccion').input_value() == 'AV. PRUEBA 123 - LIMA', 'RUC completa razón social y dirección')
    ok('ACTIVO / HABIDO' in page.locator('#lookup-message').inner_text(), 'Muestra estado y condición del RUC')

    page.locator('#tipo_comprobante').select_option('boleta')
    document.fill('00000000')
    page.wait_for_function("document.querySelector('#lookup-badge').textContent === 'Revisar'")
    ok('Documento no encontrado' in page.locator('#lookup-message').inner_text(), 'Un documento no encontrado muestra un error controlado')
    page.locator('#cliente_nombre').fill('NOMBRE MANUAL')
    ok('editado manualmente' in page.locator('#lookup-message').inner_text().lower(), 'Permite continuar con datos ingresados manualmente')

    submit_page = load()
    submit_page.locator('#sale-lines select').select_option('1')
    submit_page.locator('#numero_documento').fill('99999999')
    submit_page.evaluate("""
        window.__submittedAfterLookup = 0;
        document.querySelector('#form-venta').addEventListener('submit', function (event) {
            event.preventDefault();
            window.__submittedAfterLookup++;
        });
    """)
    submit_page.locator('#sale-submit').click()
    submit_page.wait_for_function('window.__submittedAfterLookup === 1')
    ok(submit_page.locator('#cliente_nombre').input_value() == 'CLIENTE ANTES DE CONTINUAR', 'Al continuar, espera la consulta y luego envía el formulario')
    ok(submit_page.evaluate("window.__documentCalls.filter(c => c.number === '99999999').length") == 1, 'Continuar no duplica la consulta automática')

    draft = load('ventas_borrador.html')
    draft.wait_for_function("document.querySelector('#cliente_nombre').value === 'EMPRESA FONTANA PRUEBA S.A.C.'")
    ok(draft.evaluate("window.__documentCalls.filter(c => c.number === '20131312955').length") == 1, 'Un borrador con RUC se consulta automáticamente al restaurarse')

    mobile = load(width=390, height=844)
    ok(mobile.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'La consulta automática no desborda en móvil')
    mobile.screenshot(path=str(out / 'dni_ruc_automatico_movil.png'), full_page=True)
    page.screenshot(path=str(out / 'dni_ruc_automatico.png'), full_page=True)

    ok(not errors, 'Sin excepciones JavaScript en la consulta automática')
    browser.close()

(out / 'resultados.json').write_text(json.dumps({
    'alcance': 'HTML/JavaScript real con respuestas de consulta controladas; sin MySQL ni proveedores externos',
    'aprobadas': len(results),
    'casos': results,
}, ensure_ascii=False, indent=2), encoding='utf-8')
print('RESULTADO', len(results), 'comprobaciones de consulta automática aprobadas')
