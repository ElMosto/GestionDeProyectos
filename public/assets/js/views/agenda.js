import { el } from '../api.js';
import { dataTable, emptyState, formValues, mount, pageHeader } from '../ui.js';
import { dateField, dateText, pagedList } from '../project-ui.js';
import { workLabel } from '../data.js';

export async function renderAgenda(root) {
    const now = new Date();
    const date = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const end = new Date(now.getFullYear(), now.getMonth() + 1, now.getDate());
    const results = el('div', { class: 'stack' });
    const form = el('form', { class: 'filters' }, [dateField('from', 'Desde', date(now)), dateField('to', 'Hasta', date(end)), el('button', { type: 'submit', class: 'btn btn-secondary' }, 'Consultar')]);
    form.querySelectorAll('input[type="date"]').forEach((input) => { input.required = true; });
    const filters = formValues(form);
    const list = pagedList(results, '/agenda', filters, (data) => data.length ? dataTable({ caption: 'Agenda propia', rows: data, columns: [
        { label: 'Tarea', render: (t) => t.title },
        { label: 'Inicio previsto', render: (t) => dateText(t.start_date) },
        { label: 'Fin previsto', render: (t) => dateText(t.end_date) },
        { label: 'Estado', render: (t) => workLabel(t.status) },
    ] }) : emptyState({ title: 'Sin tareas en este rango', message: 'Selecciona otras fechas para consultar tu agenda.' }), 'tareas en agenda');
    mount(root, el('section', { class: 'stack' }, [pageHeader({ title: 'Agenda', lead: 'Vista de tareas propias por rango de fechas. Reuniones y reservas se incorporarán en su fase; no se muestran como tareas ni se infiere duración.' }), form, results]));
    form.addEventListener('submit', (event) => { event.preventDefault(); Object.assign(filters, formValues(form)); void list.reset(); });
    await list.load();
}
