import { api, el } from './api.js';
import { field } from './ui.js';
import { PRIORITIES, workOptions } from './data.js';
import { cap, clientPicker, dateField, directory, editDialog, idPath, roleCode, selectField, userOptions } from './project-ui.js';

/** §8: money stays a decimal string from input to API; only lexical validation here. */
export async function projectForm(ctx, project, done) {
    const creating = !project;
    const p = project ?? {};
    const users = cap(ctx, 'projects.manageAny') ? (await directory()).filter((u) => roleCode(u) === 'LIDER_PROYECTO') : [ctx.session.user];
    const type = selectField('type', 'Tipo', workOptions(['INTERNO', 'EXTERNO']), p.type ?? 'INTERNO', true);
    const amount = field({ name: 'base_amount', label: 'Importe base', value: p.base_amount ?? '0.00', required: true, inputMode: 'decimal', pattern: '[0-9]{1,10}\\.[0-9]{2}', help: 'Importe decimal con dos cifras, por ejemplo 120.00. No se calcula en el navegador.' });
    const clients = clientPicker(p.client_id ?? p.client?.id ?? '', p.client?.name);
    const fields = [
        field({ name: 'name', label: 'Nombre', value: p.name, required: true, maxLength: 200 }),
        field({ name: 'description', label: 'Descripción', value: p.description, type: 'textarea', required: true }),
        type,
        clients,
        selectField('billable', 'Condición cobrable', [{ value: 'true', label: 'Cobrable' }, { value: 'false', label: 'No cobrable' }], String(p.billable ?? false)),
        selectField('leader_id', 'Líder', userOptions(users), p.leader_id ?? p.leader?.id ?? ctx.session.user.id, true),
        dateField('planned_start', 'Inicio previsto', p.planned_start), dateField('planned_end', 'Fin previsto', p.planned_end),
        amount,
        el('p', { text: `Moneda: ${p.currency ?? 'MXN'}. La moneda la establece el servidor.` }),
        selectField('priority', 'Prioridad', workOptions(PRIORITIES), p.priority ?? 'MEDIA'),
        field({ name: 'notes', label: 'Notas', value: p.notes, type: 'textarea' }),
    ];
    const sync = () => {
        clients.querySelector('select[name="client_id"]').required = type.control.value === 'EXTERNO';
        if (type.control.value === 'EXTERNO') fields.find((row) => row.control?.name === 'billable').control.value = 'true';
        amount.control.setCustomValidity(type.control.value === 'EXTERNO' && /^0+\.00$/.test(amount.control.value) ? 'Un proyecto externo requiere un importe mayor que cero.' : '');
    };
    type.control.addEventListener('change', sync); amount.control.addEventListener('input', sync); sync();
    return editDialog({
        title: creating ? 'Nuevo proyecto' : 'Editar proyecto', fields,
        transform: ({ client_search, ...v }) => ({ ...v, notes: v.notes.trim() || null, client_id: v.client_id || null, billable: v.billable === 'true', planned_start: v.planned_start || null, planned_end: v.planned_end || null, ...(creating ? {} : { version: p.version }) }),
        save: (body) => api(creating ? '/projects' : `/projects/${idPath(p.id)}`, { method: creating ? 'POST' : 'PATCH', body, idempotent: true, version: creating ? undefined : p.version }), done,
    });
}

export function stageForm(project, stage, done) {
    const s = stage ?? {};
    return editDialog({ title: stage ? 'Editar etapa' : 'Nueva etapa', fields: [
        field({ name: 'name', label: 'Nombre', value: s.name, required: true, maxLength: 200 }),
        field({ name: 'description', label: 'Descripción', value: s.description, type: 'textarea' }),
        dateField('start_date', 'Inicio previsto', s.start_date), dateField('end_date', 'Fin previsto', s.end_date),
        ...(stage ? [selectField('status', 'Estado de etapa', workOptions(['PENDIENTE', 'EN_CURSO', 'COMPLETADA', 'CANCELADA']), s.status)] : []),
    ], transform: (v) => ({ ...v, description: v.description.trim() || null, start_date: v.start_date || null, end_date: v.end_date || null, ...(stage ? { version: stage.version } : {}) }),
    save: (body) => api(stage ? `/stages/${idPath(stage.id)}` : `/projects/${idPath(project.id)}/stages`, { method: stage ? 'PATCH' : 'POST', body, version: stage?.version }), done });
}

export function chargeForm(project, charge, done) {
    const c = charge ?? {};
    return editDialog({ title: charge ? 'Editar cargo' : 'Añadir cargo adicional', fields: [
        el('p', { text: 'Un cargo aumenta el precio comercial; no registra un pago ni un gasto.' }),
        field({ name: 'concept', label: 'Concepto', required: true, value: c.concept }),
        field({ name: 'quantity', label: 'Cantidad', required: true, value: c.quantity ?? '1.000', pattern: '[0-9]{1,7}\\.[0-9]{3}', inputMode: 'decimal', help: 'Tres decimales, por ejemplo 1.000.' }),
        field({ name: 'unit_price', label: 'Precio unitario', required: true, value: c.unit_price ?? '', pattern: '[0-9]{1,10}\\.[0-9]{2}', inputMode: 'decimal', help: 'Dos decimales, por ejemplo 150.00.' }),
        dateField('charge_date', 'Fecha del cargo', c.charge_date ?? `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}-${String(new Date().getDate()).padStart(2, '0')}`),
    ], transform: (v) => ({ ...v, ...(charge ? { version: c.version } : {}) }),
    save: (body) => api(charge ? `/project-charges/${idPath(c.id)}` : `/projects/${idPath(project.id)}/charges`, { method: charge ? 'PATCH' : 'POST', body, idempotent: true, version: charge?.version }), done });
}
