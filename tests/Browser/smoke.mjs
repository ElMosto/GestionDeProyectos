/** §19.A/H. Chromium + CDP nativo, sin dependencias npm ni build de producción.
 * Requiere PHP 8.4 (PHP_BINARY) y Chromium (CHROMIUM_BINARY) en el entorno.
 * Reinicia SOLO gestion_test. No ejecutar en paralelo con PHPUnit.
 */
import { spawn, execFileSync } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';
import { createServer } from 'node:net';
import assert from 'node:assert/strict';

const root = fileURLToPath(new URL('../../', import.meta.url));
const php = process.env.PHP_BINARY;
const chromium = process.env.CHROMIUM_BINARY;
assert(php && chromium, 'Configura PHP_BINARY y CHROMIUM_BINARY.');
const users = JSON.parse(execFileSync(php, ['tests/Support/browser-fixtures.php'], { cwd: root, encoding: 'utf8' }));
const profile = await mkdtemp(join(tmpdir(), 'gestion-browser-'));
let browser, server, ws;
const errors = [];
let checks = 0;
const listener = createServer();
await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
const address = `127.0.0.1:${listener.address().port}`;
await new Promise(resolve => listener.close(resolve));
const base = `http://${address}`;
const pending = new Map();
let sequence = 0;

try {
    server = spawn(php, ['-S', address, '-t', 'public', 'tests/Support/http-router.php'], { cwd: root, stdio: 'ignore' });
    let ready = false;
    for (let i = 0; i < 80; i++) {
        try { ready = (await fetch(`${base}/api/v1/health`)).ok; } catch { /* arranque */ }
        if (ready) break;
        await delay(50);
    }
    assert(ready, 'Servidor de pruebas no disponible.');
    browser = spawn(chromium, [
        '--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`,
        '--no-first-run', '--no-default-browser-check', 'about:blank',
    ], { stdio: ['ignore', 'ignore', 'pipe'] });
    const endpoint = await new Promise((resolve, reject) => {
        let output = '';
        const timeout = setTimeout(() => reject(new Error('Chromium no arrancó.')), 15000);
        browser.on('error', reject);
        browser.stderr.on('data', chunk => {
            output += chunk;
            const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/);
            if (match) { clearTimeout(timeout); resolve(match[1]); }
        });
    });
    const origin = new URL(endpoint).origin.replace('ws:', 'http:');
    const target = await (await fetch(`${origin}/json/new?about:blank`, { method: 'PUT' })).json();
    ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { ws.addEventListener('open', resolve); ws.addEventListener('error', reject); });
    ws.addEventListener('message', event => {
        const message = JSON.parse(event.data);
        if (message.id) {
            const call = pending.get(message.id);
            pending.delete(message.id);
            if (message.error) call?.reject(new Error(JSON.stringify(message.error)));
            else call?.resolve(message.result);
        } else if (message.method === 'Runtime.exceptionThrown') {
            errors.push(message.params.exceptionDetails.exception?.description ?? message.params.exceptionDetails.text);
        }
    });
    const cdp = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++sequence;
        const timeout = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 15000);
        pending.set(id, { resolve: value => { clearTimeout(timeout); resolve(value); }, reject: error => { clearTimeout(timeout); reject(error); } });
        ws.send(JSON.stringify({ id, method, params }));
    });
    const evaluate = async expression => {
        const result = await cdp('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description ?? result.exceptionDetails.text);
        return result.result.value;
    };
    const wait = async (expression, label) => {
        for (let i = 0; i < 150; i++) {
            if (await evaluate(expression)) { checks++; return; }
            await delay(50);
        }
        throw new Error(`No se cumplió: ${label}\n${await evaluate('document.body.innerText')}`);
    };
    const fill = async (name, value) => evaluate(`(() => {
        const scope = document.querySelector('[role=dialog]') ?? document;
        const input = scope.querySelector('[name=${JSON.stringify(name)}]');
        if (!input) throw new Error('Campo ausente: ' + ${JSON.stringify(name)});
        input.value = ${JSON.stringify(value)};
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    })()`);
    const clickText = async text => evaluate(`(() => {
        const button = [...document.querySelectorAll('button,a')].find(n => n.textContent.trim() === ${JSON.stringify(text)});
        if (!button) throw new Error('Control ausente: ' + ${JSON.stringify(text)});
        button.click();
    })()`);
    const navigate = async hash => evaluate(`location.hash = ${JSON.stringify(hash)}`);
    await cdp('Runtime.enable');
    await cdp('Page.enable');
    await cdp('Page.navigate', { url: `${base}/app.html` });
    await wait(`!!document.querySelector('[name=email]')`, 'login cargado');
    await fill('email', users.admin.email);
    await fill('password', users.admin.password);
    await evaluate(`document.querySelector('form').requestSubmit()`);
    await wait(`!!document.querySelector('[name=new_password]')`, 'cambio obligatorio');
    await fill('current_password', users.admin.password);
    await fill('new_password', 'NuevaClaveBrowser2026!');
    await fill('new_password_confirmation', 'NuevaClaveBrowser2026!');
    await evaluate(`document.querySelector('form').requestSubmit()`);
    await wait(`document.querySelector('#main-nav a[href="#/usuarios"]') !== null`, 'admin navega tras cambiar contraseña');
    await navigate('/usuarios');
    await wait(`document.querySelector('#app')?.textContent.includes('collaborator@test.local')`, 'lista usuarios');
    await clickText('Nuevo usuario');
    await wait(`!!document.querySelector('#create-user-form')`, 'formulario usuario');
    await fill('name', 'Líder navegador');
    await fill('email', 'leader-browser@test.local');
    await fill('password', 'ClaveTemporalBrowser2026!');
    await fill('role', 'LIDER_PROYECTO');
    await evaluate(`document.querySelector('#create-user-form').requestSubmit()`);
    await wait(`!document.querySelector('[role=dialog]') && document.querySelector('#app')?.textContent.includes('leader-browser@test.local')`, 'crear líder');
    await navigate('/clientes');
    await wait(`document.querySelector('#app')?.textContent.includes('Cliente privado admin')`, 'lista clientes');
    await clickText('Nuevo cliente');
    await wait(`!!document.querySelector('[role=dialog]')`, 'diálogo accesible');
    await fill('type', 'BANDA');
    await fill('name', '<script>window.xssExecuted=true</script>');
    await fill('representative_name', 'Representante navegador');
    await fill('phone', '5551234567');
    await evaluate(`document.querySelector('#create-client-form').requestSubmit()`);
    await wait(`!document.querySelector('[role=dialog]') && document.querySelector('#app')?.textContent.includes('<script>')`, 'cliente guardado, XSS literal');
    assert.equal(await evaluate('window.xssExecuted'), undefined);
    checks++;
    await navigate('/mi-usuario');
    await wait(`!!document.querySelector('[name=job_title]')`, 'perfil');
    await fill('job_title', 'Prueba de navegador');
    await clickText('Guardar cambios');
    await wait(`document.body.textContent.includes('Perfil actualizado.')`, 'guardar perfil');
    // Vista móvil: no debe desbordar horizontalmente toda la página.
    await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    assert(await evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Desbordamiento móvil');
    checks++;
    await clickText('Cerrar sesión');
    await wait(`!!document.querySelector('[name=email]')`, 'logout');
    await fill('email', users.collaborator.email);
    await fill('password', users.collaborator.password);
    await evaluate(`document.querySelector('form').requestSubmit()`);
    await wait(`!!document.querySelector('#main-nav a[href="#/clientes"]')`, 'login colaborador');
    assert.equal(await evaluate(`!!document.querySelector('#main-nav a[href="#/usuarios"]')`), false);
    checks++;
    await navigate('/clientes');
    await wait(`document.querySelector('#app')?.textContent.includes('Cliente privado admin')`, 'directorio colaborador');
    assert.equal(await evaluate(`document.querySelector('#app').textContent.includes('SECRETO_ADMIN')`), false);
    checks++;
    assert.deepEqual(errors, [], 'Excepciones JS del navegador');
    console.log(`OK: ${checks} comprobaciones de navegador; sin excepciones JavaScript.`);
} finally {
    ws?.close();
    browser?.kill();
    server?.kill();
    // Chromium puede tardar en liberar archivos del perfil en Windows.
    await rm(profile, { recursive: true, force: true, maxRetries: 10, retryDelay: 100 });
}
