/** §19.E: reservas, ocupación mínima, agenda y configuración con PHP/MariaDB reales. */
import { withBrowser } from './support/browser.mjs';

await withBrowser('tests/Support/reservations-browser-fixtures.php', async ({ fixtures, evaluate, wait, fill, click, navigate, check, cdp }) => {
    const login = async (user) => {
        await wait(`!!document.querySelector('[name=email]')`, 'Formulario de acceso');
        await fill('email', user.email);
        await fill('password', user.password);
        await evaluate(`document.querySelector('form').requestSubmit()`);
        await wait(`!!document.querySelector('#main-nav a[href="#/reservas"]')`, 'Sesión con acceso a reservas');
    };
    const submit = () => evaluate(`document.querySelector('[role=dialog] form').requestSubmit()`);
    const closeDialog = async () => {
        await evaluate(`document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))`);
        await wait(`!document.querySelector('[role=dialog]')`, 'Diálogo cerrado');
    };
    await login(fixtures.owner);
    await navigate(`/reservas/${fixtures.reservation.id}`);
    await wait(`document.querySelector('#app').textContent.includes('180.00 MXN')`, 'Tarifa por 90 minutos calculada por servidor');
    check(!(await evaluate(`document.querySelector('#app').textContent.includes('Cancelar reserva y venta')`)), 'Colaborador no cancela venta coordinada');
    check(await evaluate(`document.querySelector('#app').textContent.includes('Venta vinculada: cobros, saldo y tickets')`), 'Cobro usa la venta vinculada');
    await click('Editar notas');
    await wait(`!!document.querySelector('[role=dialog] [name=notes]')`, 'Editar solo notas');
    await fill('notes', '<script>window.reservationXss=true</script>');
    await submit();
    await wait(`!document.querySelector('[role=dialog]') && document.querySelector('#app').textContent.includes('<script>window.reservationXss=true</script>')`, 'Notas persistidas literalmente');
    check(await evaluate('window.reservationXss === undefined'), 'Notas no ejecutan script');
    await click('Reprogramar');
    await wait(`!!document.querySelector('[role=dialog] [name=start_at]')`, 'Formulario de reprogramación');
    await fill('start_at', '2030-11-05T14:00:00Z');
    await fill('end_at', '2030-11-05T15:30:00Z');
    await submit();
    await wait(`document.querySelector('[role=dialog]')?.textContent.includes('RESERVATION_CONFLICT')`, 'Mantenimiento impide reprogramación');
    await closeDialog();
    await click('Reprogramar');
    await wait(`!!document.querySelector('[role=dialog] [name=start_at]')`, 'Reprogramación a horario libre');
    await fill('start_at', '2030-11-05T11:30:00Z');
    await fill('end_at', '2030-11-05T13:00:00Z');
    await submit();
    await wait(`!document.querySelector('[role=dialog]') && document.querySelector('#app').textContent.includes('2030-11-05T11:30:00Z')`, 'Reprogramación confirmada');
    await navigate('/agenda');
    await wait(`!!document.querySelector('[name=from]')`, 'Agenda por rango');
    await fill('from', '2030-11-01');
    await fill('to', '2030-11-30');
    await evaluate(`document.querySelector('#app form').requestSubmit()`);
    await wait(`document.querySelector('#app').textContent.includes('Sala de navegador')`, 'Reserva en agenda propia');
    check(!(await evaluate(`document.querySelector('#app').textContent.includes('Banda navegador privada')`)), 'Agenda mínima no expone banda');
    await click('Día');
    await wait(`document.querySelector('[name=from]').value === document.querySelector('[name=to]').value`, 'Vista diaria delimita rango');
    await click('Semana');
    await wait(`document.querySelector('[name=from]').value !== document.querySelector('[name=to]').value`, 'Vista semanal delimita rango');
    await click('Cerrar sesión');

    await login(fixtures.other);
    await navigate(`/reservas/${fixtures.reservation.id}`);
    await wait(`document.querySelector('#app').textContent.includes('No se pudo')`, 'Reserva ajena rechazada');
    check(!(await evaluate(`document.querySelector('#app').textContent.includes('180.00')`)), 'Error ajeno sin importes');
    await navigate('/espacios');
    await wait(`!!document.querySelector('[name=space_id]')`, 'Calendario compartido');
    await fill('space_id', String(fixtures.space.id));
    await fill('from', '2030-11-05T00:00:00Z');
    await fill('to', '2030-11-06T00:00:00Z');
    await click('Consultar ocupación');
    await wait(`document.querySelector('#app').textContent.includes('Ocupación mínima: sin clientes')`, 'Ocupación mínima consultada');
    const calendar = await evaluate(`document.querySelector('#app').textContent`);
    for (const secret of ['Banda navegador privada', 'NOTA_PRIVADA', 'MOTIVO_PRIVADO_MANTENIMIENTO', '180.00 MXN', '120.00 MXN']) {
        check(!calendar.includes(secret), `Calendario no contiene ${secret}`);
    }
    await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    check(await evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Calendario móvil sin desbordamiento global');
    await click('Cerrar sesión');

    await login(fixtures.admin);
    await navigate(`/reservas/${fixtures.reservation.id}`);
    await wait(`document.querySelector('#app').textContent.includes('Cancelar reserva y venta')`, 'ADMIN puede cancelar');
    await click('Cancelar reserva y venta');
    await wait(`!!document.querySelector('[role=dialog] [name=reason]')`, 'Cancelación con motivo');
    await fill('reason', 'Cancelación administrativa de navegador');
    await submit();
    await wait(`!document.querySelector('[role=dialog]') && [...document.querySelectorAll('#app dd')].some(n => n.textContent === 'Cancelada')`, 'Reserva cancelada');
    await navigate('/espacios');
    await wait(`document.querySelector('#app').textContent.includes('Crear espacio')`, 'Configuración ADMIN');
    await click('Crear espacio');
    await wait(`!!document.querySelector('[role=dialog] [name=hourly_rate]')`, 'Nuevo espacio sin tarifa inventada');
    await fill('name', 'Sala adicional navegador');
    await fill('hourly_rate', '150.00');
    await fill('block_minutes', '30');
    await submit();
    await wait(`!document.querySelector('[role=dialog]') && document.querySelector('#app').textContent.includes('Sala adicional navegador')`, 'Espacio creado con datos explícitos');
    await evaluate(`(() => {
        const row = [...document.querySelectorAll('#app tr')].find(n => n.textContent.includes('Sala adicional navegador'));
        [...row.querySelectorAll('button')].find(n => n.textContent === 'Configurar horarios').click();
    })()`);
    await wait(`!!document.querySelector('[role=dialog]')`, 'Horarios con zona del estudio');
    await click('Añadir ventana de apertura');
    await fill('hours.0.weekday', '1');
    await fill('hours.0.local_start_time', '00:00:00');
    await fill('hours.0.local_end_time', '24:00:00');
    await submit();
    await wait(`!document.querySelector('[role=dialog]')`, 'Ventana hasta 24:00:00 guardada');
});
