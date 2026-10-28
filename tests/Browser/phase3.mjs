/** §19.C/D: catálogo, emisión, cobros, correcciones y tickets contra PHP/MariaDB reales. */
import { withBrowser } from './support/browser.mjs';
import { mkdir, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

await withBrowser('tests/Support/sales-browser-fixtures.php', async ({ fixtures, base, evaluate, wait, fill, click, navigate, check, cdp }) => {
    const login = async (user) => {
        await wait(`!!document.querySelector('[name=email]')`, 'Formulario de acceso');
        await fill('email', user.email);
        await fill('password', user.password);
        await evaluate(`document.querySelector('form').requestSubmit()`);
        await wait(`!!document.querySelector('#main-nav a[href="#/ventas"]')`, 'Sesión autenticada con ventas');
    };
    const confirm = async () => evaluate(`(() => {
        const box = document.querySelector('[role=dialog] [name=confirmation]');
        if (box) box.checked = true;
        document.querySelector('[role=dialog] form').requestSubmit();
    })()`);
    const closeDialog = async () => {
        await evaluate(`document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))`);
        await wait(`!document.querySelector('[role=dialog]')`, 'Diálogo cerrado');
    };
    await login(fixtures.seller);
    await navigate('/productos');
    await wait(`document.querySelector('#app').textContent.includes('Licuado navegador')`, 'Catálogo visible para colaborador');
    check(!(await evaluate(`document.querySelector('#app').textContent.includes('Nuevo producto o servicio')`)), 'Colaborador no administra precios');
    await navigate('/ventas');
    await wait(`document.querySelector('#app').textContent.includes('Nueva venta ordinaria')`, 'Lista de ventas');
    await click('Nueva venta ordinaria');
    await wait(`!!document.querySelector('[name="items.0.product_id"]')`, 'Partida de catálogo');
    await fill('items.0.product_id', String(fixtures.product.id));
    await fill('items.0.quantity', '2.000');
    await wait(`!!document.querySelector('.sale-line input[type=checkbox][value="${fixtures.extra.id}"]')`, 'Extras permitidos de la partida');
    await evaluate(`(() => {
        const extra = document.querySelector('.sale-line input[type=checkbox][value="${fixtures.extra.id}"]');
        extra.checked = true; extra.dispatchEvent(new Event('change', { bubbles: true }));
    })()`);
    await confirm();
    await wait(`!document.querySelector('[role=dialog]') && document.querySelector('#app').textContent.includes('120.00 MXN')`, 'Dos productos con extra totalizan 120.00');
    const salePath = await evaluate('location.hash');
    const saleId = salePath.split('/').at(-1);
    await click('Emitir venta');
    await wait(`!!document.querySelector('[role=dialog]')`, 'Confirmación de emisión');
    await click('Confirmar emisión');
    await wait(`!document.querySelector('[role=dialog]') && ![...document.querySelectorAll('#app button')].some(n => n.textContent === 'Editar borrador')`, 'Venta emitida e inmutable');
    await wait(`document.querySelector('#app').textContent.includes('Sin pagos registrados')`, 'Emitir no cobra');
    check(!(await evaluate(`document.querySelector('#app').textContent.includes('Cancelar venta')`)), 'Cancelar es exclusivamente ADMIN');
    await click('Registrar abono');
    await wait(`!!document.querySelector('[role=dialog] [name=amount]')`, 'Formulario de abono');
    await fill('amount', '30.00');
    await confirm();
    await wait(`!document.querySelector('[role=dialog]') && document.querySelector('#app').textContent.includes('90.00 MXN')`, 'Abono parcial con saldo 90.00');
    await click('Marcar pagada (cobrar saldo)');
    await wait(`!!document.querySelector('[role=dialog] [name=method]')`, 'Atajo cobra saldo transaccional');
    check(!(await evaluate(`!!document.querySelector('[role=dialog] [name=amount]')`)), 'El atajo no envía importe calculado en navegador');
    await confirm();
    await wait(`!document.querySelector('[role=dialog]') && [...document.querySelectorAll('#app dd')].some(n => n.textContent === 'Pagado')`, 'Documento pagado');
    await cdp('Page.navigate', { url: `${base}/api/v1/sales/${saleId}/ticket?paper=80mm` });
    await wait(`document.body.dataset.paper === '80mm'`, 'Ticket térmico autorizado');
    const ticketText = await evaluate('document.body.innerText');
    check(ticketText.includes('Comprobante interno; no es factura fiscal'), 'Aviso fiscal interno');
    check(ticketText.includes('120.00 MXN') && ticketText.includes('Chocolate navegador'), 'Ticket conserva partidas y total de API');
    await cdp('Page.navigate', { url: `${base}/app.html${salePath}` });
    await wait(`!!document.querySelector('#session-bar button')`, 'Volver a la aplicación');
    await click('Cerrar sesión');

    await login(fixtures.other);
    await navigate(salePath.slice(1));
    await wait(`document.querySelector('#app').textContent.includes('No se pudo')`, 'Venta ajena no accesible');
    check(!(await evaluate(`document.querySelector('#app').textContent.includes('Licuado navegador')`)), 'Error no filtra conceptos de la venta ajena');
    await click('Cerrar sesión');

    await login(fixtures.admin);
    await navigate('/productos');
    await wait(`document.querySelector('#app').textContent.includes('Nuevo producto o servicio')`, 'Catálogo administrable');
    await click('Nuevo producto o servicio');
    await wait(`!!document.querySelector('[role=dialog] [name=price]')`, 'Nuevo producto');
    await fill('name', 'Producto creado en navegador');
    await fill('price', '75.00');
    await confirm();
    await wait(`!document.querySelector('[role=dialog]') && document.querySelector('#app').textContent.includes('Producto creado en navegador')`, 'Producto guardado con opcionales vacíos');
    await evaluate(`(() => {
        const row = [...document.querySelectorAll('#app tr')].find(n => n.textContent.includes('Licuado navegador'));
        [...row.querySelectorAll('button')].find(n => n.textContent === 'Editar').click();
    })()`);
    await wait(`!!document.querySelector('[role=dialog] [name=price]')`, 'Editar precio de catálogo');
    await fill('price', '70.00');
    await confirm();
    await wait(`!document.querySelector('[role=dialog]')`, 'Precio actualizado');
    await navigate(salePath.slice(1));
    await wait(`document.querySelector('#app').textContent.includes('120.00 MXN')`, 'Venta emitida conserva total histórico');
    check(await evaluate(`document.querySelector('#app').textContent.includes('50.00 MXN')`), 'Precio histórico no se reemplaza por catálogo vigente');
    await wait(`document.querySelector('#app').textContent.includes('Reembolsar')`, 'Correcciones administrativas');
    await click('Reembolsar');
    await wait(`!!document.querySelector('[role=dialog] [name=reason]')`, 'Formulario de devolución real');
    await fill('amount', '10.00');
    await fill('reason', 'Devolución real de navegador');
    await confirm();
    await wait(`!document.querySelector('[role=dialog]') && document.querySelector('#app').textContent.includes('110.00 MXN')`, 'Reembolso reduce neto sin cancelar');
    await wait(`[...document.querySelectorAll('#app tr')].some(n => n.textContent.includes('30.00 MXN') && n.textContent.includes('Anular pago'))`, 'Pago no reembolsado visible');
    await evaluate(`(() => {
        const row = [...document.querySelectorAll('#app tr')].find(n => n.textContent.includes('30.00 MXN') && n.textContent.includes('Anular pago'));
        [...row.querySelectorAll('button')].find(n => n.textContent === 'Anular pago').click();
    })()`);
    await wait(`!!document.querySelector('[role=dialog] [name=reason]')`, 'Anulación con motivo');
    await fill('reason', 'Error de registro navegador');
    await confirm();
    await wait(`!document.querySelector('[role=dialog]') && document.querySelector('#app').textContent.includes('40.00 MXN')`, 'Anulación conserva historial y recalcula saldo');
    await click('Cancelar venta');
    await wait(`!!document.querySelector('[role=dialog] [name=reason]')`, 'Cancelación explícita');
    await fill('reason', 'Intento sin resolver el neto');
    await confirm();
    await wait(`document.querySelector('[role=dialog]')?.textContent.includes('PAYMENTS_REQUIRE_RESOLUTION')`, 'Cancelar no reembolsa automáticamente');
    await closeDialog();
    await navigate(`/proyectos/${fixtures.project.id}`);
    await wait(`document.querySelector('#app h1')?.textContent === 'Proyecto cobrable navegador'`, 'Proyecto finalizado con saldo');
    await click('Finanzas');
    await wait(`document.querySelector('#app').textContent.includes('Registrar abono')`, 'Finalizar trabajo no liquida cobros');
    await click('Registrar abono');
    await wait(`!!document.querySelector('[role=dialog] [name=amount]')`, 'Abono a proyecto finalizado');
    await fill('amount', '50.00');
    await confirm();
    await wait(`!document.querySelector('[role=dialog]') && document.querySelector('#app').textContent.includes('200.00 MXN')`, 'Proyecto cobrado sin modificar valor comercial');

    const artifactDirectory = join(tmpdir(), 'opencode');
    await mkdir(artifactDirectory, { recursive: true });
    for (const paper of ['80mm', 'A4']) {
        await cdp('Page.navigate', { url: `${base}/api/v1/sales/${fixtures.longTicket.id}/ticket?paper=${paper}` });
        await wait(`document.body.dataset.paper === ${JSON.stringify(paper)}`, `Ticket largo ${paper}`);
        await cdp('Emulation.setEmulatedMedia', { media: 'print' });
        await cdp('Emulation.setDeviceMetricsOverride', { width: paper === '80mm' ? 303 : 794, height: 1123, deviceScaleFactor: 1, mobile: false });
        check(await evaluate(`document.body.innerText.includes('ULTIMA_PARTIDA')`), 'Última partida no truncada');
        check(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), `Sin desbordamiento horizontal en ${paper}`);
        const pdf = await cdp('Page.printToPDF', { printBackground: false, preferCSSPageSize: true, paperWidth: paper === '80mm' ? 80 / 25.4 : 210 / 25.4, paperHeight: 297 / 25.4 });
        const bytes = Buffer.from(pdf.data, 'base64');
        check(bytes.subarray(0, 4).toString() === '%PDF', 'Salida PDF de prueba válida');
        if (paper === 'A4') check((bytes.toString('latin1').match(/\/Type\s*\/Page\b/g) ?? []).length > 1, 'Ticket largo se divide en varias páginas A4');
        await writeFile(join(artifactDirectory, `gestion-ticket-phase3-${paper}.pdf`), bytes);
    }
});
