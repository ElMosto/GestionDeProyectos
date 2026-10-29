import { api, el } from './api.js';
import { applyFormError, clearFieldErrors, dataTable, errorState, field, formAlert, formValues, loadingState, mount, pagination } from './ui.js';
import { clientPicker, dateField, selectField, userOptions } from './project-ui.js';
import { adminDirectory, monthRange, queryString } from './admin-ui.js';
import { workOptions } from './data.js';

/** Filtros compartidos; DATE financieras son UTC según api-finanzas.md. */
export async function reportFilters(ctx, { finance = false, expenses = false, audit = false, onApply }) {
    const range = monthRange(ctx.session.user.timezone);
    const users = await adminDirectory();
    const fields = [dateField('from', 'Desde (día UTC)', range.from), dateField('to', 'Hasta inclusive (día UTC)', range.to)];
    if (finance) fields.push(dateField('cut_off', 'Corte de pendientes (día UTC)', range.to), clientPicker(), selectField('source_type', 'Origen', [{ value: '', label: 'Todos los orígenes' }, ...workOptions(['INTERNO', 'EXTERNO', 'ORDINARIA', 'RESERVA'])]));
    fields.push(selectField(audit ? 'actor_id' : 'responsible_id', audit ? 'Actor' : 'Responsable', [{ value: '', label: 'Todas las personas' }, ...userOptions(users).slice(1)]));
    if (finance || expenses) fields.push(selectField('status', 'Estado', [{ value: '', label: 'Todos los estados' }, ...workOptions(expenses ? ['PENDIENTE', 'PAGADO', 'CANCELADO'] : ['NO_PAGADO', 'PARCIAL', 'PAGADO', 'SIN_CARGO'])]));
    if (expenses) fields.push(field({ name: 'project_id', label: 'ID del proyecto (opcional)', pattern: '[1-9][0-9]*', inputMode: 'numeric' }));
    if (audit) fields.push(field({ name: 'action', label: 'Acción exacta (opcional)' }), field({ name: 'entity_type', label: 'Tipo de entidad exacto (opcional)' }));
    const alert = formAlert();
    const form = el('form', { class: 'filters finance-filters' }, [alert, ...fields, el('button', { type: 'submit', class: 'btn btn-primary' }, 'Aplicar filtros')]);
    const values = () => { const v = formValues(form); delete v.client_search; return v; };
    form.addEventListener('submit', (event) => { event.preventDefault(); if (!form.reportValidity()) return; clearFieldErrors(form); alert.hidden = true; onApply(values()); });
    return { form, values, showError: (error) => applyFormError(error, form, alert) };
}

export function reportList(root, path, filters, caption, columns, showError = () => {}) {
    let offset = 0; let sequence = 0;
    const load = async () => {
        const ticket = ++sequence; mount(root, loadingState());
        try {
            const result = await api(`${path}?${queryString({ ...filters, limit: '25', offset: String(offset) })}`);
            if (ticket !== sequence) return;
            mount(root, [dataTable({ caption, columns, rows: result.data, emptyTitle: 'Sin resultados para estos filtros' }), pagination({ total: result.meta.total, limit: 25, offset, label: caption, onChange: (next) => { offset = next; void load(); } })]);
        } catch (error) { if (ticket !== sequence) return; showError(error); mount(root, errorState({ error, title: error.code === 'HISTORY_INCOMPLETE' ? 'Historial incompleto: no se pueden reconstruir las cifras' : 'No se pudo cargar el detalle', onRetry: load })); }
    };
    return { load, reset: () => { offset = 0; return load(); }, dispose: () => { sequence++; } };
}
