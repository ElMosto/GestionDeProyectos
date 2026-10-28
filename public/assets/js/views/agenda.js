import { el } from '../api.js';
import { dataTable, emptyState, formValues, formatDateTime, mount, pageHeader } from '../ui.js';
import { dateField, dateText, pagedList } from '../project-ui.js';
import { workLabel } from '../data.js';

export async function renderAgenda(root, ctx) {
    const timezone = ctx?.session?.user?.timezone ?? 'UTC';
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date()).map((p) => [p.type, p.value]));
    const now = new Date(Number(parts.year), Number(parts.month) - 1, Number(parts.day), 12);
    const date = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    // La proyección de reservas permite hasta 31 días inclusivos.
    const end = new Date(now.getFullYear(), now.getMonth() + 1, 0, 12);
    const results = el('div', { class: 'stack' });
    const reservationResults = el('div', { class: 'stack' });
    const form = el('form', { class: 'filters' }, [dateField('from', 'Desde', date(now)), dateField('to', 'Hasta', date(end)), el('button', { type: 'submit', class: 'btn btn-secondary' }, 'Consultar')]);
    form.querySelectorAll('input[type="date"]').forEach((input) => { input.required = true; });
    const filters = formValues(form);
    const list = pagedList(results, '/agenda', filters, (data) => data.length ? dataTable({ caption: 'Agenda propia', rows: data, columns: [
        { label: 'Tarea', render: (t) => t.title },
        { label: 'Inicio previsto', render: (t) => dateText(t.start_date) },
        { label: 'Fin previsto', render: (t) => dateText(t.end_date) },
        { label: 'Estado', render: (t) => workLabel(t.status) },
    ] }) : emptyState({ title: 'Sin tareas en este rango', message: 'Selecciona otras fechas para consultar tu agenda.' }), 'tareas en agenda');
    const reservations = pagedList(reservationResults, '/agenda/reservations', filters, (data) => data.length ? dataTable({ caption: 'Reservas propias', rows: data, columns: [
        { label: 'Reserva', render: (r) => el('a', { href: `#/reservas/${encodeURIComponent(r.reservation_id ?? r.id)}` }, r.title ?? 'Reserva') },
        { label: 'Inicio', render: (r) => formatDateTime(r.start_at, timezone) },
        { label: 'Fin', render: (r) => formatDateTime(r.end_at, timezone) },
        { label: 'Estado', render: (r) => workLabel(r.status) },
    ] }) : emptyState({ title: 'Sin reservas en este rango', message: 'Aquí aparecen únicamente las reservas a tu cargo.' }), 'reservas en agenda');
    const periods = el('nav', { class: 'project-tabs', 'aria-label': 'Rango de agenda' });
    for (const [label, period] of [['Día', 'day'], ['Semana', 'week'], ['Mes', 'month']]) {
        const button = el('button', { type: 'button', class: 'btn btn-secondary', text: label });
        button.addEventListener('click', () => {
            const anchor = new Date(`${form.elements.from.value || date(now)}T12:00:00`);
            const first = new Date(anchor);
            const last = new Date(anchor);
            if (period === 'week') {
                first.setDate(first.getDate() - (first.getDay() + 6) % 7);
                last.setTime(first.getTime());
                last.setDate(last.getDate() + 6);
            } else if (period === 'month') {
                first.setDate(1);
                last.setMonth(last.getMonth() + 1, 0);
            }
            form.elements.from.value = date(first);
            form.elements.to.value = date(last);
            form.requestSubmit();
        });
        periods.append(button);
    }
    mount(root, el('section', { class: 'stack' }, [pageHeader({ title: 'Agenda', lead: `Tareas de día completo y reservas a tu cargo. Horas en ${timezone}. Las reuniones se incorporarán en la fase 5.` }), periods, form, results, reservationResults]));
    form.addEventListener('submit', (event) => { event.preventDefault(); Object.assign(filters, formValues(form)); void Promise.all([list.reset(), reservations.reset()]); });
    await Promise.all([list.load(), reservations.load()]);
}
