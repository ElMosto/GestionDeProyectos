/** Ejecutor CDP compartido para aceptación. Solo desarrollo, sin paquetes npm. */
import { spawn, execFileSync } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:net';
import { fileURLToPath } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';
import assert from 'node:assert/strict';

const root = fileURLToPath(new URL('../../../', import.meta.url));

export async function withBrowser(fixtureScript, scenario) {
    const php = process.env.PHP_BINARY;
    const chromium = process.env.CHROMIUM_BINARY;
    assert(php && chromium, 'Configura PHP_BINARY y CHROMIUM_BINARY.');
    const fixtures = JSON.parse(execFileSync(php, [fixtureScript], { cwd: root, encoding: 'utf8' }));
    const profile = await mkdtemp(join(tmpdir(), 'gestion-browser-'));
    const listener = createServer();
    await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
    const address = `127.0.0.1:${listener.address().port}`;
    await new Promise(resolve => listener.close(resolve));
    const base = `http://${address}`;
    let server, browser, ws;
    let count = 0;
    let sequence = 0;
    const pending = new Map();
    const exceptions = [];
    try {
        server = spawn(php, ['-S', address, '-t', 'public', 'tests/Support/http-router.php'], { cwd: root, stdio: 'ignore' });
        let ready = false;
        for (let i = 0; i < 100; i++) {
            try { ready = (await fetch(`${base}/api/v1/health`)).ok; } catch { /* iniciando */ }
            if (ready) break;
            await delay(50);
        }
        assert(ready, 'Servidor HTTP de pruebas no disponible.');
        browser = spawn(chromium, [
            '--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`,
            '--no-first-run', '--no-default-browser-check', 'about:blank',
        ], { stdio: ['ignore', 'ignore', 'pipe'] });
        const endpoint = await new Promise((resolve, reject) => {
            let stderr = '';
            const timeout = setTimeout(() => reject(new Error('Chromium no inició CDP.')), 15000);
            browser.on('error', error => { clearTimeout(timeout); reject(error); });
            browser.stderr.on('data', chunk => {
                stderr += chunk;
                const match = stderr.match(/DevTools listening on (ws:\/\/[^\s]+)/);
                if (match) { clearTimeout(timeout); resolve(match[1]); }
            });
        });
        const origin = new URL(endpoint).origin.replace('ws:', 'http:');
        const target = await (await fetch(`${origin}/json/new?about:blank`, { method: 'PUT' })).json();
        ws = new WebSocket(target.webSocketDebuggerUrl);
        await new Promise((resolve, reject) => {
            ws.addEventListener('open', resolve);
            ws.addEventListener('error', reject);
        });
        ws.addEventListener('message', event => {
            const message = JSON.parse(event.data);
            if (message.id) {
                const waiter = pending.get(message.id);
                pending.delete(message.id);
                if (message.error) waiter?.reject(new Error(JSON.stringify(message.error)));
                else waiter?.resolve(message.result);
            } else if (message.method === 'Runtime.exceptionThrown') {
                exceptions.push(message.params.exceptionDetails.exception?.description ?? message.params.exceptionDetails.text);
            }
        });
        const cdp = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++sequence;
            const timeout = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 15000);
            pending.set(id, {
                resolve: value => { clearTimeout(timeout); resolve(value); },
                reject: error => { clearTimeout(timeout); reject(error); },
            });
            ws.send(JSON.stringify({ id, method, params }));
        });
        const evaluate = async expression => {
            const result = await cdp('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
            if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description ?? result.exceptionDetails.text);
            return result.result.value;
        };
        const wait = async (expression, description) => {
            for (let i = 0; i < 200; i++) {
                if (await evaluate(expression)) { count++; return; }
                await delay(50);
            }
            throw new Error(`${description}\n${await evaluate('document.body.innerText')}`);
        };
        const fill = async (name, value) => evaluate(`(() => {
            const scope = document.querySelector('[role=dialog]') ?? document;
            const input = scope.querySelector('[name=${JSON.stringify(name)}]');
            if (!input) throw new Error('Campo ausente: ' + ${JSON.stringify(name)});
            input.value = ${JSON.stringify(value)};
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        })()`);
        const click = async text => evaluate(`(() => {
            const scope = document.querySelector('[role=dialog]') ?? document;
            const button = [...scope.querySelectorAll('button,a')].find(n => n.textContent.trim() === ${JSON.stringify(text)});
            if (!button) throw new Error('Control ausente: ' + ${JSON.stringify(text)});
            button.click();
        })()`);
        const navigate = async hash => evaluate(`location.hash = ${JSON.stringify(hash)}`);
        const check = (value, message) => { assert(value, message); count++; };
        await cdp('Runtime.enable');
        await cdp('Page.enable');
        await cdp('Page.navigate', { url: `${base}/app.html` });
        await scenario({ fixtures, base, cdp, evaluate, wait, fill, click, navigate, check, assert });
        assert.deepEqual(exceptions, [], 'Excepciones JavaScript no controladas');
        console.log(`OK: ${count} comprobaciones de navegador; sin excepciones JavaScript.`);
    } finally {
        ws?.close();
        browser?.kill();
        server?.kill();
        await rm(profile, { recursive: true, force: true, maxRetries: 10, retryDelay: 100 });
    }
}
