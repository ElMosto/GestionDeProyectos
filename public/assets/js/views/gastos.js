import { api, el } from '../api.js';
import { errorState, field, formatDateTime, loadingState, mount, notice, openDialog, pageHeader } from '../ui.js';
import { button, dateField, editDialog, moneyText, selectField, userOptions } from '../project-ui.js';
import { adminAllowed, adminDirectory, positiveId } from '../admin-ui.js';
import { reportFilters, reportList } from '../finance-ui.js';
import { workLabel, workOptions } from '../data.js';

export async function renderGastos(root, ctx) {
    if (!adminAllowed(root, ctx, 'expenses.manage')) return;
    let disposed = false; let current;
    ctx.onCleanup?.(() => { disposed = true; current?.dispose(); });
    mount(root, loadingState('Cargando gastos…'));
    const results = el('div'); const feedback = el('div');
    const controls = await reportFilters(ctx, { expenses: true, onApply: (v) => load(v) });
    if (disposed) return;
    const initial = new URLSearchParams(window.location.hash.split('?')[1] ?? '');
    for (const key of ['from', 'to', 'responsible_id']) if (initial.has(key)) controls.form.elements[key].value = initial.get(key);
    function load(filters) {
        current?.dispose();
        current = reportList(results, '/expenses', filters, 'Gastos internos', [
            { label: 'Concepto', render: (g) => button(g.concept, () => { void detail(g.id); }) },
            { label: 'Importe (MXN)', render: (g) => moneyText(g.amount, 'MXN') },
            { label: 'Fecha del gasto', render: (g) => g.expense_date },
            { label: 'Estado', render: (g) => workLabel(g.status) },
            { label: 'Responsable', render: (g) => `Usuario ${g.responsible_id}` },
        ], controls.showError);
        return current.load();
    }
    const reload = () => { if (!disposed) return load(controls.values()); };
    async function detail(id) {
        if (!positiveId(id)) return;
        mount(feedback, loadingState('Cargando detalle del gasto…'));
        try { const { data } = await api(`/expenses/${id}`); if (disposed) return; mount(feedback, []); await form(data); }
        catch (error) { if (!disposed) mount(feedback, errorState({ error, onRetry: () => detail(id) })); }
    }
    async function form(g = null) {
        const users = await adminDirectory();
        if (disposed) return;
        if (g && g.status !== 'PENDIENTE') {
            openDialog({ title: 'Gasto: solo lectura', content: el('div', { class: 'stack' }, [notice({ message: 'Los gastos pagados son inmutables y los cancelados son terminales. No se elimina el historial.' }), el('dl', { class: 'project-facts' }, [['Concepto', g.concept], ['Importe', moneyText(g.amount, 'MXN')], ['Estado', workLabel(g.status)], ['Fecha', g.expense_date], ['Pago', g.paid_at ? formatDateTime(g.paid_at, ctx.session.user.timezone) : 'Sin pago'], ['Notas', g.notes ?? '—']].flatMap(([label, value]) => [el('dt', { text: label }), el('dd', { text: value })]))]) });
            return;
        }
        const status = selectField('status', 'Estado', workOptions(g ? ['PENDIENTE', 'PAGADO', 'CANCELADO'] : ['PENDIENTE', 'PAGADO']), g?.status ?? 'PENDIENTE', true);
        const paid = field({ name: 'paid_at', label: 'Fecha efectiva de pago con zona horaria', value: g?.paid_at ?? '', placeholder: '2026-10-28T15:30:00-06:00', help: 'ISO 8601 con segundos y zona. Obligatoria al marcar pagado; no se asigna una fecha automáticamente.' });
        const sync = () => { paid.control.required = status.control.value === 'PAGADO'; paid.control.disabled = status.control.value !== 'PAGADO'; };
        status.control.addEventListener('change', sync); sync();
        const fields = [notice({ message: 'Un gasto no aumenta el valor del proyecto ni las cuentas por cobrar. Cancelar solo se permite mientras está pendiente; conserva el historial.' }), field({ name: 'concept', label: 'Concepto', value: g?.concept ?? '', required: true }), field({ name: 'amount', label: 'Importe MXN', value: g?.amount ?? '', inputMode: 'decimal', pattern: '[0-9]+\\.[0-9]{2}', required: true, help: 'Importe decimal con dos decimales; por ejemplo 200.00.' }), dateField('expense_date', 'Fecha del gasto', g?.expense_date ?? ''), selectField('responsible_id', 'Responsable', userOptions(users), g?.responsible_id ?? '', true), field({ name: 'project_id', label: 'ID de proyecto (opcional)', value: g?.project_id ?? '', pattern: '[1-9][0-9]*', inputMode: 'numeric' }), status, paid, field({ name: 'notes', label: 'Notas', type: 'textarea', value: g?.notes ?? '' }), field({ name: 'confirmation', label: 'Confirmo el registro o cambio de estado indicado', type: 'checkbox', required: true })];
        editDialog({ title: g ? 'Editar gasto pendiente' : 'Nuevo gasto', fields, transform: (v) => ({ concept: v.concept, amount: v.amount, expense_date: v.expense_date, responsible_id: v.responsible_id, project_id: v.project_id || null, status: v.status, notes: v.notes || null, ...(v.status === 'PAGADO' ? { paid_at: v.paid_at } : {}), ...(g ? { expected_version: g.version } : {}) }), save: (body) => api(g ? `/expenses/${g.id}` : '/expenses', { method: g ? 'PATCH' : 'POST', body, idempotent: true, ...(g ? { version: g.version } : {}) }), done: reload });
    }
    mount(root, [pageHeader({ title: 'Gastos', lead: 'Administración de gastos internos; sin borrado de documentos financieros.' }), button('Nuevo gasto', () => { void form().catch((error) => mount(feedback, errorState({ error, onRetry: () => form() }))); }, true), feedback, controls.form, results]);
    await reload();
}
