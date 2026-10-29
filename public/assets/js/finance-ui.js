import { api, el } from './api.js';
import { applyFormError, clearFieldErrors, dataTable, errorState, field, formAlert, formValues, loadingState, mount, pagination } from './ui.js';
import { dateField, selectField, userOptions } from './project-ui.js';
import { adminDirectory, monthRange, queryString } from './admin-ui.js';
import { workOptions } from './data.js';

/** Filtros compartidos; DATE financieras son UTC según api-finanzas.md. */
export async function reportFilters(ctx, { finance = false, expenses = false, audit = false, onApply }) {
    const range = monthRange(ctx.session.user.timezone);
    const users = await adminDirectory();
    const fields = [dateField('from', 'Desde (día UTC)', range.from), dateField('to', 'Hasta inclusive (día UTC)', range.to)];
    if (finance) fields.push(dateField('cut_off', 'Corte de pendientes (día UTC)', range.to));
    if (expenses) fields.push(selectField('responsible_id', 'Responsable', [{ value: '', label: 'Todas las personas' }, ...userOptions(users).slice(1)]), selectField('status', 'Estado', [{ value: '', label: 'Todos los estados' }, ...workOptions(['PENDIENTE', 'PAGADO', 'CANCELADO'])]), field({ name: 'project_id', label: 'ID del proyecto (opcional)', pattern: '[1-9][0-9]*', inputMode: 'numeric' }));
    if (audit) fields.push(selectField('actor_id', 'Actor', [{ value: '', label: 'Todas las personas' }, ...userOptions(users).slice(1)]), field({ name: 'action', label: 'Acción exacta (opcional)' }), field({ name: 'entity_type', label: 'Tipo de entidad exacto (opcional)' }));
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

/** Gráfica SVG accesible sin dependencias ni cálculo monetario. */
export function financeBarChart(title, rows, labelKey, valueKey, colors = {}) {
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const width = 640; const rowHeight = 34; const margin = 16;
    const height = Math.max(96, rows.length * rowHeight + margin * 2);
    const values = rows.map((row) => Math.abs(Number(row[valueKey])));
    const max = Math.max(1, ...values);
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', title);
    svg.classList.add('finance-chart');
    const titleNode = document.createElementNS(SVG_NS, 'title');
    titleNode.textContent = title;
    svg.append(titleNode);
    rows.forEach((row, index) => {
        const y = margin + index * rowHeight;
        const value = Number(row[valueKey]);
        const barWidth = Math.round((Math.abs(value) / max) * 300);
        const label = document.createElementNS(SVG_NS, 'text');
        label.setAttribute('x', '8'); label.setAttribute('y', String(y + 20));
        label.setAttribute('class', 'finance-chart-label'); label.textContent = String(row[labelKey]);
        const bar = document.createElementNS(SVG_NS, 'rect');
        bar.setAttribute('x', '250'); bar.setAttribute('y', String(y + 6));
        bar.setAttribute('width', String(barWidth)); bar.setAttribute('height', '18');
        bar.setAttribute('rx', '4'); bar.setAttribute('fill', colors[row[labelKey]] || 'var(--accent-strong, #1f5bd0)');
        const amount = document.createElementNS(SVG_NS, 'text');
        amount.setAttribute('x', String(260 + barWidth)); amount.setAttribute('y', String(y + 20));
        amount.setAttribute('class', 'finance-chart-value'); amount.textContent = `${row[valueKey]} MXN`;
        svg.append(label, bar, amount);
    });
    return el('figure', { class: 'finance-chart-card' }, [el('figcaption', { text: title }), svg]);
}
