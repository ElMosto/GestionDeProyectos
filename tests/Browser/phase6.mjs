/** §19.B/C/G/H: indicadores, detalle conciliable, administración y descargas reales. */
import { withBrowser } from './support/browser.mjs';
import { mkdir, mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

await withBrowser('tests/Support/finance-browser-fixtures.php', async ({ fixtures, base, evaluate, wait, fill, click, navigate, check, cdp }) => {
    const login = async user => {
        await wait(`!!document.querySelector('[name=email]')`, 'Formulario de acceso');
        await fill('email', user.email);
        await fill('password', user.password);
        await evaluate(`document.querySelector('form').requestSubmit()`);
        await wait(`!!document.querySelector('#main-nav a[href="#/proyectos"]')`, 'Sesión autenticada');
    };
    const submit = () => evaluate(`document.querySelector('[role=dialog] form').requestSubmit()`);
    const close = async () => {
        await evaluate(`document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))`);
        await wait(`!document.querySelector('[role=dialog]')`, 'Diálogo cerrado');
    };
    await login(fixtures.admin);
    await navigate('/finanzas');
    await wait(`document.querySelector('#app').textContent.includes('Resultado de caja (no utilidad contable)')`, 'Dashboard con datos reales');
    for (const [label, amount] of [
        ['Importe comercial: ventas y proyectos cobrables', '2120.00 MXN'],
        ['Comercial de ventas emitidas (incluye reservas)', '1120.00 MXN'],
        ['Comercial de proyectos cobrables', '1000.00 MXN'],
        ['Valor interno estimado (no cobrable)', '1500.00 MXN'],
        ['Cobros netos del periodo', '620.00 MXN'],
        ['Pendiente a la fecha de corte', '1500.00 MXN'],
        ['Gastos pagados del periodo', '200.00 MXN'],
        ['Resultado de caja (no utilidad contable)', '420.00 MXN'],
    ]) {
        check(await evaluate(`[...document.querySelectorAll('.finance-metrics .panel')].some(n => n.querySelector('dt')?.textContent === ${JSON.stringify(label)} && n.querySelector('dd')?.textContent === ${JSON.stringify(amount)})`), `${label}: ${amount}`);
    }
    await wait(`!document.querySelector('#app .state-loading')`, 'Detalles financieros cargados');
    check(await evaluate(`document.querySelector('#finance-documents').textContent.includes('1500.00 MXN')`), 'Detalle separa valor interno de ingresos');
    check(await evaluate(`document.querySelector('#finance-paid-expenses').textContent.includes('-200.00 MXN')`), 'Gastos efectivos respaldan caja con signo de salida');
    check(await evaluate(`document.querySelector('#finance-transactions').textContent.includes('120.00 MXN')`), 'La reserva se cuenta mediante su único cobro');
    await navigate('/gastos');
    await wait(`document.querySelector('#app').textContent.includes('Gasto pagado de navegador')`, 'Lista de gastos');
    await click('Gasto pagado de navegador');
    await wait(`document.querySelector('[role=dialog]')?.textContent.includes('solo lectura')`, 'Gasto pagado no se reescribe');
    check(!(await evaluate(`!!document.querySelector('[role=dialog] [name=amount]')`)), 'Sin formulario para alterar gasto pagado');
    await close();
    await click('Nuevo gasto');
    await wait(`!!document.querySelector('[role=dialog] [name=amount]')`, 'Nuevo gasto pendiente');
    await fill('concept', 'Pendiente creado en navegador');
    await fill('amount', '300.00');
    await fill('expense_date', fixtures.expense.expense_date);
    await fill('responsible_id', String(fixtures.admin.id));
    await evaluate(`document.querySelector('[role=dialog] [name=confirmation]').checked = true`);
    await submit();
    await wait(`!document.querySelector('[role=dialog]') && document.querySelector('#app').textContent.includes('Pendiente creado en navegador')`, 'Gasto creado y lista recargada');
    await navigate('/finanzas');
    await wait(`document.querySelector('#app').textContent.includes('Resultado de caja (no utilidad contable)')`, 'Dashboard actualizado');
    check(await evaluate(`[...document.querySelectorAll('.finance-metrics .panel')].some(n => n.querySelector('dt')?.textContent === 'Gastos pendientes al corte' && n.querySelector('dd')?.textContent === '300.00 MXN')`), 'Gasto pendiente no se confunde con salida efectiva');
    check(await evaluate(`[...document.querySelectorAll('.finance-metrics .panel')].some(n => n.querySelector('dt')?.textContent === 'Resultado de caja (no utilidad contable)' && n.querySelector('dd')?.textContent === '420.00 MXN')`), 'Gasto pendiente no reduce caja');
    await navigate('/configuracion');
    await wait(`!!document.querySelector('[name=studio_name]')`, 'Configuración permitida');
    await fill('studio_name', 'Estudio verificado en navegador');
    await click('Guardar configuración');
    await wait(`document.querySelector('[role=status]')?.textContent.includes('Configuración guardada') || [...document.querySelectorAll('[role=status]')].some(n => n.textContent.includes('Configuración guardada'))`, 'Nombre del estudio guardado');
    await wait(`!!document.querySelector('[name=timezone]')`, 'Configuración recargada con digest nuevo');
    await fill('timezone', 'America/Mexico_City');
    await click('Guardar configuración');
    await wait(`document.querySelector('#app').textContent.includes('SETTINGS_TIMEZONE_IN_USE')`, 'No se reinterpretan horarios futuros');
    await navigate('/auditoria');
    await wait(`document.querySelector('#app').textContent.includes('EXPENSE_CREATE')`, 'Auditoría administrativa disponible');
    check(!(await evaluate(`document.querySelector('#app').textContent.includes('NOTAS_FINANCIERAS_NO_MIEMBRO')`)), 'Auditoría no expone textos privados');
    await cdp('Page.navigate', { url: `${base}/api/v1/projects/${fixtures.internal.id}/ticket?paper=A4` });
    await wait(`document.body.dataset.paper === 'A4'`, 'Ticket financiero del proyecto');
    const ticket = await evaluate('document.body.innerText');
    for (const text of ['Estudio verificado en navegador', 'valor interno estimado', '1500.00 MXN', 'Sin cuenta por cobrar', 'Comprobante interno; no es factura fiscal']) check(ticket.includes(text), `Ticket contiene ${text}`);
    check(!ticket.includes('Gasto pagado de navegador') && !ticket.includes('NOTAS_FINANCIERAS_NO_MIEMBRO'), 'Ticket no incluye gastos ni notas privadas');
    await cdp('Page.navigate', { url: `${base}/app.html#/proyectos/${fixtures.internal.id}` });
    await wait(`!!document.querySelector('#session-bar button')`, 'Volver a la aplicación');
    await click('Cerrar sesión');

    await login(fixtures.member);
    await navigate('/finanzas');
    await wait(`document.querySelector('#app').textContent.includes('Acceso restringido')`, 'Colaborador no accede a dashboard');
    await navigate(`/proyectos/${fixtures.internal.id}`);
    await wait(`document.querySelector('#app h1')?.textContent === 'Proyecto interno de exportación'`, 'Miembro accede al plan');
    check(!(await evaluate(`[...document.querySelectorAll('#app a,#app button')].some(n => n.textContent === 'Imprimir ticket')`)), 'Miembro no recibe ticket financiero');
    await mkdir(join(tmpdir(), 'opencode'), { recursive: true });
    const directory = await mkdtemp(join(tmpdir(), 'opencode', 'gestion-gantt-'));
    try {
        await cdp('Page.setDownloadBehavior', { behavior: 'allow', downloadPath: directory });
        await click('Imprimir proyecto');
        await wait(`!!document.querySelector('[role=dialog]')`, 'Exportación con rango opcional');
        await fill('from', '2020-01-01');
        await fill('to', '2050-12-31');
        await submit();
        await wait(`document.querySelector('[role=dialog]')?.textContent.includes('EXPORT_RANGE_TOO_LARGE')`, 'Un error de rango se muestra en el formulario, no como archivo descargado');
        await fill('from', '');
        await fill('to', '');
        await submit();
        await wait(`!document.querySelector('[role=dialog]')`, 'Descarga sin error JSON');
        const filename = `proyecto_${fixtures.internal.folio}_gantt.xlsx`;
        let bytes;
        for (let attempt = 0; attempt < 100; attempt++) {
            try { bytes = await readFile(join(directory, filename)); break; } catch { await cdp('Runtime.evaluate', { expression: 'new Promise(resolve => setTimeout(resolve, 50))', awaitPromise: true }); }
        }
        check(bytes?.subarray(0, 2).toString() === 'PK' && bytes.length > 1000, 'Descarga XLSX real con nombre exacto');
    } finally {
        await rm(directory, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
    }
});
