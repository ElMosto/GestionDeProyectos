/** §8: own-task progress and comments; scope/assignment reserved for project managers. */
import { api, el } from './api.js';
import { applyFormError, clearFieldErrors, dataTable, emptyState, field, formAlert, formValues, mount, openDialog, toast, formatDateTime } from './ui.js';
import { PRIORITIES, TASK_STATUSES, workLabel, workOptions } from './data.js';
import { button, cap, dateField, dateText, directory, editDialog, idPath, pagedList, selectField, userOptions } from './project-ui.js';

export async function taskForm(ctx, { task = null, stage = null, users = null, done }) {
    const t = task ?? {};
    const assignees = users ?? await directory();
    return editDialog({ title: task ? 'Editar tarea' : stage ? 'Nueva tarea de etapa' : 'Nueva tarea independiente', fields: [
        field({ name: 'title', label: 'Título', required: true, maxLength: 200, value: t.title }),
        field({ name: 'description', label: 'Descripción', type: 'textarea', value: t.description }),
        selectField('assignee_id', 'Responsable', userOptions(assignees), t.assignee_id ?? t.assignee?.id, true),
        selectField('priority', 'Prioridad', workOptions(PRIORITIES), t.priority ?? 'MEDIA'),
        dateField('start_date', 'Inicio previsto', t.start_date), dateField('end_date', 'Fin previsto', t.end_date),
        ...(stage ? [selectField('is_required', 'Tarea obligatoria', [{ value: 'true', label: 'Sí' }, { value: 'false', label: 'No' }], String(t.is_required ?? true))] : []),
    ], transform: (v) => ({ ...v, description: v.description.trim() || null, start_date: v.start_date || null, end_date: v.end_date || null, ...(stage ? { is_required: v.is_required === 'true' } : {}), ...(task ? { version: t.version } : {}) }),
    save: (body) => api(task ? `/tasks/${idPath(t.id)}` : stage ? `/stages/${idPath(stage.id)}/tasks` : '/standalone-tasks', { method: task ? 'PATCH' : 'POST', body, version: task?.version }), done });
}

function progressForm(task, done) {
    return editDialog({ title: 'Actualizar avance', fields: [field({ name: 'progress', label: 'Avance (%)', value: task.progress ?? 0, pattern: '(100|[0-9]{1,2})', inputMode: 'numeric', required: true, help: 'Porcentaje operativo de 0 a 100. Completar una tarea es una transición separada.' })],
        transform: (v) => ({ progress: Number(v.progress), version: task.version }),
        save: (body) => api(`/tasks/${idPath(task.id)}`, { method: 'PATCH', body, version: task.version }), done });
}

export function taskTransition(task, done) {
    const status = selectField('status', 'Nuevo estado', workOptions(TASK_STATUSES), task.status, true);
    const reason = field({ name: 'reason', label: 'Motivo', type: 'textarea', help: 'Obligatorio al reabrir una tarea terminada o cancelada.' });
    const sync = () => { reason.control.required = ['COMPLETADA', 'CANCELADA'].includes(task.status) && status.control.value === 'EN_CURSO'; };
    status.control.addEventListener('change', sync); sync();
    return editDialog({ title: 'Confirmar cambio de estado de tarea', fields: [
        el('p', { text: `${task.title}. Estado actual: ${workLabel(task.status)}. Completar fija el avance al 100%; cancelar conserva el historial.` }), status, reason,
        field({ name: 'confirmation', label: 'Confirmo el cambio indicado', type: 'checkbox', required: true }),
    ], transform: (v) => ({ status: v.status, version: task.version, ...(v.reason.trim() ? { reason: v.reason.trim() } : {}) }),
    save: (body) => api(`/tasks/${idPath(task.id)}/transitions`, { method: 'POST', body, version: task.version }), done });
}

export function taskTable(rows, open) {
    return dataTable({ caption: 'Tareas', rows, emptyTitle: 'No hay tareas', columns: [
        { label: 'Tarea', render: (t) => button(t.title, () => open(t)) },
        { label: 'Responsable', render: (t) => t.assignee?.name ?? t.assignee_name ?? '—' },
        { label: 'Estado', render: (t) => workLabel(t.status) },
        { label: 'Prioridad', render: (t) => workLabel(t.priority) },
        { label: 'Avance operativo', render: (t) => `${t.progress ?? 0}%` },
        { label: 'Inicio', render: (t) => dateText(t.start_date) },
        { label: 'Fin', render: (t) => dateText(t.end_date) },
    ] });
}

export function taskDetail(ctx, task, { manager = false, stage = null, users = null, done } = {}) {
    const ownTask = cap(ctx, 'tasks.updateAssigned') && String(task.assignee_id ?? task.assignee?.id) === String(ctx.session.user.id);
    const canUpdate = manager || ownTask;
    const comments = el('section', { class: 'stack', 'aria-label': 'Comentarios' });
    const actions = el('div', { class: 'form-actions' });
    const dialog = openDialog({ title: task.title, content: el('div', { class: 'stack' }, [
        el('p', { text: task.description || 'Sin descripción.' }),
        el('p', { text: `Estado: ${workLabel(task.status)} · Prioridad: ${workLabel(task.priority)} · Avance: ${task.progress ?? 0}%` }),
        el('p', { text: `Fechas previstas: ${dateText(task.start_date)} — ${dateText(task.end_date)}` }),
        actions, el('h3', { text: 'Comentarios' }), comments,
    ]) });
    const next = (open) => { dialog.close(); open(); };
    if (canUpdate) actions.append(button('Actualizar avance', () => next(() => progressForm(task, done))), button('Cambiar estado', () => next(() => taskTransition(task, done))));
    if (manager) actions.append(button('Editar alcance y fechas', async () => {
        dialog.close();
        try { await taskForm(ctx, { task, stage, users, done }); } catch (error) { toast(error.message, { tone: 'error' }); }
    }));
    const commentList = pagedList(comments, `/tasks/${idPath(task.id)}/comments`, {}, (data) => {
        const list = data.length ? el('ol', { class: 'comment-list' }, data.map((c) => el('li', { class: 'work-card' }, [
            el('p', { text: c.body }), el('p', { class: 'help', text: `${c.author?.name ?? c.author_name ?? 'Usuario'} · ${formatDateTime(c.created_at, ctx.session.user.timezone)}` }),
        ]))) : emptyState({ title: 'Sin comentarios' });
        // §8: cualquier usuario que puede ver la tarea puede comentarla;
        // no hace falta estar autorizado a cambiar su avance.
        const alert = formAlert();
        const input = field({ name: 'body', label: 'Nuevo comentario', type: 'textarea', required: true, maxLength: 5000 });
        const submit = el('button', { type: 'submit', class: 'btn btn-primary' }, 'Comentar');
        const form = el('form', { class: 'stack' }, [alert, input, submit]);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (submit.disabled || !form.reportValidity()) return;
            clearFieldErrors(form); alert.hidden = true; submit.disabled = true;
            try { await api(`/tasks/${idPath(task.id)}/comments`, { method: 'POST', body: formValues(form) }); toast('Comentario añadido.', { tone: 'success' }); await commentList.reset(); }
            catch (error) { applyFormError(error, form, alert); }
            finally { submit.disabled = false; }
        });
        return el('div', { class: 'stack' }, [list, form]);
    }, 'comentarios');
    void commentList.load();
    return dialog;
}
