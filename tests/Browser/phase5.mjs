/** §19.F: invitaciones individuales, privacidad, reaceptación y avisos reales. */
import { withBrowser } from './support/browser.mjs';

await withBrowser('tests/Support/meetings-browser-fixtures.php', async ({ fixtures, evaluate, wait, fill, click, navigate, check }) => {
    const path = `/reuniones/${fixtures.meeting.id}`;
    const login = async user => {
        await wait(`!!document.querySelector('[name=email]')`, 'Formulario de acceso');
        await fill('email', user.email);
        await fill('password', user.password);
        await evaluate(`document.querySelector('form').requestSubmit()`);
        await wait(`!!document.querySelector('#main-nav a[href="#/reuniones"]')`, 'Acceso a reuniones');
    };
    const submit = () => evaluate(`document.querySelector('[role=dialog] form').requestSubmit()`);
    const meeting = async () => {
        await navigate(path);
        await wait(`document.querySelector('#app h1')?.textContent === 'Reunión de navegador'`, 'Detalle de reunión');
    };
    const agenda = async () => {
        await navigate('/agenda');
        await wait(`!!document.querySelector('[name=from]')`, 'Agenda propia');
        await fill('from', '2030-11-01');
        await fill('to', '2030-11-30');
        await evaluate(`document.querySelector('#app form').requestSubmit()`);
        await wait(`!document.querySelector('#app .state-loading')`, 'Agenda cargada');
    };
    const respond = async label => {
        await click(label);
        await wait(`!!document.querySelector('[role=dialog]')`, `Confirmación ${label}`);
        await submit();
        await wait(`!document.querySelector('[role=dialog]') && !document.querySelector('#app .state-loading')`, `Respuesta ${label} registrada`);
    };
    await login(fixtures.first);
    await navigate('/notificaciones');
    await wait(`document.querySelector('#app').textContent.includes('Abrir solicitud de reunión')`, 'Invitación notificada');
    const messages = await evaluate(`document.querySelector('#app').textContent`);
    for (const secret of ['DESCRIPCION_PRIVADA_REUNION', 'UBICACION_PRIVADA_REUNION', 'TITULO_PRIVADO_TAREA_AVISO']) {
        check(!messages.includes(secret), 'Avisos sin contenido privado del objeto');
    }
    await click('Marcar como leída');
    await wait(`!![...document.querySelectorAll('#app .notification-state')].find(n => n.textContent === 'Leída')`, 'Aviso marcado leído');
    await meeting();
    check(!(await evaluate(`document.querySelector('#app').textContent.includes('UBICACION_PRIVADA_REUNION')`)), 'Pendiente no ve ubicación');
    check(!(await evaluate(`document.querySelector('#app').textContent.includes('Cancelar reunión')`)), 'Invitado no cancela');
    await agenda();
    check(!(await evaluate(`document.querySelector('#app').textContent.includes('Reunión de navegador')`)), 'Pendiente no aparece en agenda');
    await meeting();
    await respond('Aceptar');
    await wait(`document.querySelector('#app').textContent.includes('UBICACION_PRIVADA_REUNION')`, 'Aceptado recibe detalle autorizado');
    await agenda();
    await wait(`document.querySelector('#app').textContent.includes('Reunión de navegador')`, 'Aceptación incorpora solo agenda propia');
    await click('Cerrar sesión');

    await login(fixtures.second);
    await meeting();
    await respond('Rechazar');
    await agenda();
    check(!(await evaluate(`document.querySelector('#app').textContent.includes('Reunión de navegador')`)), 'Rechazo individual no incorpora agenda');
    await click('Cerrar sesión');

    await login(fixtures.organizer);
    await meeting();
    const states = await evaluate(`document.querySelector('#app').textContent`);
    check(states.includes(`${fixtures.first.name}: Aceptada`) && states.includes(`${fixtures.second.name}: Rechazada`), 'Respuestas individuales conservadas');
    await click('Reprogramar');
    await wait(`!!document.querySelector('[role=dialog] [name=start_at]')`, 'Reprogramación con advertencia');
    check(await evaluate(`document.querySelector('[role=dialog]').textContent.includes('TODAS')`), 'Aviso de reaceptación para todos');
    await fill('start_at', '2030-11-06T10:00:00Z');
    await fill('end_at', '2030-11-06T11:00:00Z');
    await submit();
    await wait(`!document.querySelector('[role=dialog]') && !document.querySelector('#app .state-loading')`, 'Horario actualizado');
    const reset = await evaluate(`document.querySelector('#app').textContent`);
    check(reset.includes(`${fixtures.first.name}: Pendiente`) && reset.includes(`${fixtures.second.name}: Pendiente`), 'Cambio temporal restablece aceptados y rechazados');
    await click('Cerrar sesión');

    await login(fixtures.first);
    await meeting();
    check(!(await evaluate(`document.querySelector('#app').textContent.includes('DESCRIPCION_PRIVADA_REUNION')`)), 'Reprogramación retira detalle privado hasta reaceptar');
    await agenda();
    check(!(await evaluate(`document.querySelector('#app').textContent.includes('Reunión de navegador')`)), 'Agenda retira aceptación anterior');
    await meeting();
    await respond('Aceptar');
    await click('Cerrar sesión');

    await login(fixtures.admin);
    await navigate(path);
    await wait(`document.querySelector('#app').textContent.includes('No se pudo')`, 'ADMIN ajeno no accede a agenda privada');
    check(!(await evaluate(`document.querySelector('#app').textContent.includes('UBICACION_PRIVADA_REUNION')`)), 'Error ajeno no revela ubicación');
    await click('Cerrar sesión');

    await login(fixtures.organizer);
    await navigate('/reuniones');
    await wait(`document.querySelector('#app').textContent.includes('Nueva reunión')`, 'Creación de reuniones');
    await click('Nueva reunión');
    await wait(`!!document.querySelector('[role=dialog] [name=title]')`, 'Formulario nuevo');
    await fill('title', 'Reunión con reserva en conflicto');
    await fill('space_id', String(fixtures.space.id));
    await fill('start_at', '2030-11-05T14:00:00Z');
    await fill('end_at', '2030-11-05T15:00:00Z');
    await submit();
    await wait(`['RESERVATION_CONFLICT', 'CONFLICTS_REQUIRE_ACK'].some(code => document.querySelector('[role=dialog]')?.textContent.includes(code))`, 'Cruce de actividad detectado');
    if (await evaluate(`!!document.querySelector('[role=dialog] [name=acknowledge_conflicts]')`)) {
        await evaluate(`document.querySelector('[role=dialog] [name=acknowledge_conflicts]').checked = true`);
        await submit();
    }
    await wait(`document.querySelector('[role=dialog]')?.textContent.includes('RESERVATION_CONFLICT')`, 'Reunión no invade reserva');
    await evaluate(`document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))`);
    await wait(`!document.querySelector('[role=dialog]')`, 'Diálogo de conflicto cerrado');
    await click('Nueva reunión');
    await wait(`!!document.querySelector('[role=dialog] [name=title]')`, 'Reunión personal sin espacio');
    await fill('title', 'Reunión personal breve navegador');
    await fill('start_at', '2030-11-07T10:01:00Z');
    await fill('end_at', '2030-11-07T10:12:00Z');
    await submit();
    await wait(`!document.querySelector('[role=dialog]') && document.querySelector('#app').textContent.includes('Reunión personal breve navegador')`, 'Reunión personal no fuerza bloques de 30 minutos');
    await meeting();
    await click('Cancelar reunión');
    await wait(`!!document.querySelector('[role=dialog] [name=reason]')`, 'Cancelar como organizador');
    await fill('reason', 'Cancelación de navegador');
    await submit();
    await wait(`!document.querySelector('[role=dialog]') && [...document.querySelectorAll('#app dd')].some(n => n.textContent === 'Cancelada')`, 'Reunión cancelada sin operaciones financieras');
    await click('Cerrar sesión');
    await login(fixtures.first);
    await agenda();
    check(!(await evaluate(`document.querySelector('#app').textContent.includes('Reunión de navegador')`)), 'Cancelación retira reunión aceptada de agenda');
});
