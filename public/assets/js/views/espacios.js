import { api, el } from '../api.js';
import { applyFormError, clearFieldErrors, dataTable, emptyState, errorState, formAlert, formValues, loadingState, mount, notice, pageHeader, panel } from '../ui.js';
import { button, cap, completeCollection, idPath, loadSection, moneyText, pagedList, selectField } from '../project-ui.js';
import { asyncAction } from '../catalog-ui.js';
import { field } from '../ui.js';
import { instantField, reservationDialog, spaceOptions } from '../reservation-ui.js';
import { blockForm, hoursForm, spaceForm, WEEKDAYS } from '../space-ui.js';
import { workLabel } from '../data.js';

export async function renderEspacios(root, ctx) {
    if (!cap(ctx, 'spaces.viewOccupancy')) { mount(root, el('p', { role: 'alert', text: 'No tienes permiso para consultar espacios.' })); return; }
    const reload = () => renderEspacios(root, ctx);
    return loadSection(root, () => completeCollection('/spaces'), (spaces) => {
        const admin = cap(ctx, 'spaces.manage');
        const feedback = el('div');
        const result = el('div', { class: 'stack', 'aria-live': 'polite' }, el('p', { text: 'Selecciona un espacio y un rango con zona para consultar su calendario de ocupación.' }));
        const space = selectField('space_id', 'Espacio activo', spaceOptions(spaces.filter((s) => s.active)), '', true);
        const from = instantField('from', 'Inicio del rango con zona');
        const to = instantField('to', 'Fin del rango con zona');
        const alert = formAlert();
        const submit = el('button', { type: 'submit', class: 'btn btn-primary' }, 'Consultar ocupación');
        const form = el('form', { class: 'stack' }, [alert, space, from, to, el('p', { text: 'Rango máximo de 31 días. Fechas ISO con segundos y desplazamiento exacto. Las ventanas se rigen por la zona del estudio, no por la zona del navegador.' }), submit]);
        let busy = false;
        const consult = async () => {
            if (busy || !form.reportValidity()) return;
            clearFieldErrors(form); alert.hidden = true; busy = true; submit.disabled = true;
            const values = formValues(form);
            const controls = [...form.querySelectorAll('input,select,textarea')].filter((c) => !c.disabled);
            controls.forEach((c) => { c.disabled = true; });
            mount(result, loadingState('Consultando ocupación…'));
            try {
                const { data } = await api(`/spaces/${idPath(values.space_id)}/availability?${new URLSearchParams({ from: values.from, to: values.to })}`);
                mount(result, [el('h3', { text: `Calendario de ocupación · zona del estudio: ${data.timezone}` }),
                    notice({ tone: 'warning', message: 'La disponibilidad consultada no garantiza un bloqueo. Otra persona puede ocupar el horario antes de que confirmes tu reserva.' }),
                    data.windows.length ? dataTable({ caption: 'Ventanas de apertura del espacio (instantes ISO con zona)', rows: data.windows, columns: [
                        { label: 'Apertura (zona explícita)', render: (r) => r.start_at }, { label: 'Cierre (zona explícita)', render: (r) => r.end_at },
                    ] }) : emptyState({ title: 'No hay ventanas de disponibilidad en este rango', message: 'Sin ventanas configuradas no se pueden crear reservas. Administración debe configurar los horarios del espacio.',
                        action: admin ? button('Configurar horario completo', asyncAction(feedback, async () => { const { data: current } = await api(`/spaces/${idPath(values.space_id)}`); hoursForm(current, reload, data.timezone); })) : null }),
                    dataTable({ caption: 'Ocupación mínima: sin clientes, responsables, notas ni importes', rows: data.occupancies, emptyTitle: 'Sin ocupaciones en este rango', emptyMessage: 'Esto no garantiza disponibilidad: también se deben respetar las ventanas de apertura.', columns: [
                        { label: 'Inicio (zona explícita)', render: (r) => r.start_at }, { label: 'Fin (zona explícita)', render: (r) => r.end_at },
                        { label: 'Tipo', render: (r) => ({ RESERVATION: 'Reserva', MAINTENANCE: 'Mantenimiento', MEETING: 'Reunión' })[r.kind] ?? 'Ocupado' },
                        { label: 'Estado', render: (r) => workLabel(r.status) },
                    ] })]);
            } catch (error) {
                applyFormError(error, form, alert);
                mount(result, errorState({ error, onRetry: () => { void consult(); } }));
            } finally { controls.forEach((c) => { c.disabled = false; }); busy = false; submit.disabled = false; }
        };
        form.addEventListener('submit', (event) => { event.preventDefault(); void consult(); });
        const editHours = (row) => asyncAction(feedback, async () => {
            // Leer la zona configurada desde el contrato; no asumir America/Mexico_City.
            const fromISO = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
            const toISO = new Date(Date.now() + 86400000).toISOString().replace(/\.\d{3}Z$/, 'Z');
            const [{ data: current }, { data: availability }] = await Promise.all([api(`/spaces/${idPath(row.id)}`), api(`/spaces/${idPath(row.id)}/availability?${new URLSearchParams({ from: fromISO, to: toISO })}`)]);
            hoursForm(current, reload, availability.timezone);
        });
        const blocks = el('div', { class: 'stack' });
        if (admin) {
            const listing = pagedList(blocks, '/space-blocks', {}, (rows) => dataTable({ caption: 'Bloqueos de mantenimiento (administración)', rows, emptyTitle: 'Sin bloqueos de mantenimiento', columns: [
                { label: 'Espacio', render: (r) => spaces.find((s) => String(s.id) === String(r.space_id))?.name ?? `Espacio ${r.space_id}` },
                { label: 'Inicio', render: (r) => r.start_at }, { label: 'Fin', render: (r) => r.end_at }, { label: 'Estado', render: (r) => workLabel(r.status) },
                { label: 'Motivo', render: (r) => r.reason }, { label: 'Acciones', render: (r) => r.status !== 'CANCELLED' ? button('Cancelar bloqueo', () => reservationDialog({ title: 'Cancelar mantenimiento', path: `/space-blocks/${idPath(r.id)}/cancel`, version: r.version,
                    fields: [field({ name: 'reason', label: 'Motivo (opcional)', type: 'textarea' })], body: (v) => ({ version: r.version, ...(v.reason.trim() ? { reason: v.reason.trim() } : {}) }), done: () => listing.load(), reload: () => listing.load() })) : 'Cancelado' },
            ] }), 'bloqueos');
            void listing.load();
        }
        return [pageHeader({ title: 'Espacios', lead: 'Calendario mínimo de ocupación compartido. Los horarios de apertura se configuran en la zona del estudio.', actions: admin ? button('Crear espacio', () => spaceForm(null, reload), true) : null }), feedback,
            !spaces.length ? notice({ tone: 'warning', message: admin ? 'Crea manualmente el primer espacio con tarifa y duración de bloque; después configura su horario. No se instala un espacio ficticio.' : 'No hay espacios configurados. Solicita a administración crear un espacio y su horario.' }) : null,
            dataTable({ caption: 'Espacios del estudio', rows: spaces, emptyTitle: 'No hay espacios', columns: [
                { label: 'Espacio', render: (s) => s.name }, { label: 'Descripción', render: (s) => s.description || 'Sin descripción' },
                { label: 'Estado', render: (s) => s.active ? 'Activo' : 'Inactivo' }, { label: 'Bloque (minutos)', render: (s) => s.block_minutes },
                { label: 'Horario local del estudio', render: (s) => !Array.isArray(s.hours) ? 'Consulta las ventanas por rango' : s.hours.length ? el('ul', {}, s.hours.map((h) => el('li', { text: `${WEEKDAYS[h.weekday - 1]} ${h.local_start_time}–${h.local_end_time}` }))) : 'Sin ventanas: no disponible' },
                ...(admin ? [{ label: 'Tarifa por hora', render: (s) => moneyText(s.hourly_rate, 'MXN') }, { label: 'Configuración', render: (s) => el('div', { class: 'form-actions' }, [button('Editar espacio', asyncAction(feedback, async () => { const { data } = await api(`/spaces/${idPath(s.id)}`); spaceForm(data, reload); })), button('Configurar horarios', editHours(s))]) }] : []),
            ] }), panel({ title: 'Consultar calendario de ocupación', children: [form, result] }),
            admin ? panel({ title: 'Mantenimiento', children: [button('Crear bloqueo de mantenimiento', () => blockForm(spaces, reload)), blocks] }) : null];
    });
}
