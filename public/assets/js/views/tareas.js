import { el } from '../api.js';
import { field, formValues, mount, pageHeader, toast } from '../ui.js';
import { TASK_STATUSES, workOptions } from '../data.js';
import { button, cap, pagedList, selectField } from '../project-ui.js';
import { taskDetail, taskForm, taskTable } from '../task-ui.js';

export async function renderTareas(root, ctx) {
    const filters = { status: '', q: '' };
    const results = el('div', { class: 'stack' });
    const form = el('form', { class: 'filters' }, [
        selectField('status', 'Estado', [{ value: '', label: 'Todos' }, ...workOptions(TASK_STATUSES)]),
        field({ name: 'q', label: 'Buscar tarea', type: 'search' }),
        el('button', { type: 'submit', class: 'btn btn-secondary' }, 'Filtrar'),
    ]);
    const list = pagedList(results, '/tasks', filters, (rows) => taskTable(rows, (task) => taskDetail(ctx, task, { manager: cap(ctx, 'tasks.createStandalone') && !task.stage_id && !task.project_id, done: list.load })), 'tareas');
    const create = cap(ctx, 'tasks.createStandalone') ? button('Nueva tarea independiente', async () => {
        create.disabled = true;
        try { await taskForm(ctx, { done: list.load }); } catch (error) { toast(error.message, { tone: 'error' }); }
        finally { create.disabled = false; }
    }, true) : null;
    mount(root, el('section', { class: 'stack' }, [pageHeader({ title: 'Mis tareas', lead: 'Tareas asignadas a tu usuario, incluidas las independientes.', actions: create }), form, results]));
    form.addEventListener('submit', (event) => { event.preventDefault(); Object.assign(filters, formValues(form)); void list.reset(); });
    await list.load();
}
