import { api, el } from '../api.js';
import { dataTable, errorState, formatDateTime, loadingState, mount, notice, pageHeader } from '../ui.js';
import { button, moneyText } from '../project-ui.js';
import { adminAllowed, documentLink, queryString } from '../admin-ui.js';
import { reportFilters, reportList } from '../finance-ui.js';
import { workLabel } from '../data.js';

export async function renderFinanzas(root, ctx) {
    if (!adminAllowed(root, ctx, 'finance.dashboard')) return;
    let disposed = false; let generation = 0; let lists = [];
    ctx.onCleanup?.(() => { disposed = true; generation++; lists.forEach((l) => l.dispose()); });
    mount(root, loadingState('Cargando filtros financieros…'));
    const results = el('div', { class: 'stack', 'aria-live': 'polite' });
    const controls = await reportFilters(ctx, { finance: true, onApply: (v) => { void load(v); } });
    if (disposed) return;
    mount(root, [pageHeader({ title: 'Finanzas', lead: 'Importes calculados por el servidor. Las reservas se incluyen en ventas, nunca se suman dos veces.' }), controls.form, results]);
    async function load(filters) {
        const ticket = ++generation; lists.forEach((l) => l.dispose()); lists = [];
        mount(results, loadingState('Consultando cobertura e indicadores…'));
        try {
            const [summary, cashflow] = await Promise.all([api(`/finance/summary?${queryString(filters)}`), api(`/finance/cashflow?${queryString(filters)}`)]);
            if (disposed || ticket !== generation) return;
            const metrics = [['commercial_total', 'Importe comercial: ventas y proyectos cobrables'], ['commercial_sales_total', 'Comercial de ventas emitidas (incluye reservas)'], ['commercial_projects_total', 'Comercial de proyectos cobrables'], ['internal_estimated_value', 'Valor interno estimado (no cobrable)'], ['net_cash', 'Cobros netos del periodo'], ['receivables_total', 'Pendiente a la fecha de corte'], ['paid_expenses', 'Gastos pagados del periodo'], ['pending_expenses', 'Gastos pendientes al corte'], ['cash_result', 'Resultado de caja (no utilidad contable)']];
            const details = el('div', { class: 'stack' });
            const transactions = el('section', { class: 'stack', id: 'finance-transactions' });
            const receivables = el('section', { class: 'stack', id: 'finance-receivables' });
            const documents = el('section', { class: 'stack', id: 'finance-documents' });
            const expenses = el('section', { class: 'stack', id: 'finance-paid-expenses' });
            const zone = ctx.session.user.timezone;
            const doc = { label: 'Documento', render: (r) => documentLink(r.document_type, r.document_id, r.folio) };
            lists = [reportList(transactions, '/finance/transactions', filters, 'Movimientos efectivos que respaldan caja', [doc, { label: 'Evento', render: (r) => ({ PAYMENT: 'Cobro', PAYMENT_VOID: 'Anulación de cobro', REFUND: 'Reembolso', REFUND_VOID: 'Anulación de reembolso' })[r.kind] ?? r.kind }, { label: `Fecha efectiva (${zone})`, render: (r) => formatDateTime(r.effective_at, zone) }, { label: 'Origen', render: (r) => workLabel(r.source_type) }, { label: 'Importe con signo (MXN)', render: (r) => moneyText(r.amount, 'MXN') }], controls.showError), reportList(receivables, '/finance/receivables', filters, 'Documentos con saldo positivo al corte', [doc, { label: 'Cliente', render: (r) => r.client_id ? `Cliente ${r.client_id}` : 'Sin cliente' }, { label: 'Emisión', render: (r) => formatDateTime(r.issued_at, zone) }, ...[['total', 'Total'], ['net_paid', 'Cobrado neto'], ['balance', 'Saldo']].map(([key, label]) => ({ label: `${label} (MXN)`, render: (r) => moneyText(r[key], 'MXN') })), { label: 'Estado', render: (r) => workLabel(r.payment_status) }], controls.showError)];
            lists.push(reportList(documents, '/finance/documents', filters, 'Documentos que respaldan el importe comercial y el valor interno', [doc,
                { label: 'Clasificación', render: (r) => ({ SALE: 'Venta emitida', PROJECT: 'Proyecto cobrable', INTERNAL_ESTIMATE: 'Valor interno estimado' })[r.commercial_class] ?? r.commercial_class },
                { label: 'Emisión', render: (r) => formatDateTime(r.issued_at, zone) }, { label: 'Valor al corte (MXN)', render: (r) => moneyText(r.total, 'MXN') }], controls.showError),
            reportList(expenses, '/finance/transactions', { ...filters, kind: 'expense' }, 'Gastos pagados que respaldan caja por fecha efectiva', [
                { label: 'Folio', render: (r) => r.folio }, { label: 'Concepto', render: (r) => r.concept },
                { label: 'Pago efectivo', render: (r) => formatDateTime(r.effective_at, zone) }, { label: 'Salida con signo (MXN)', render: (r) => moneyText(r.amount, 'MXN') }], controls.showError));
            mount(results, [notice({ title: `Corte: ${summary.meta.cut_off} inclusive · UTC`, message: `Periodo comercial y caja: ${filters.from} a ${filters.to}, días UTC. Pendientes: documentos emitidos hasta el corte, no solo los emitidos durante el periodo. Generado: ${formatDateTime(summary.meta.generated_at, zone)}.` }), el('dl', { class: 'finance-metrics' }, metrics.map(([key, label]) => el('div', { class: 'panel' }, [el('dt', { text: label }), el('dd', { text: moneyText(summary.data[key], 'MXN') })]))),
                el('nav', { class: 'form-actions', 'aria-label': 'Detalle de indicadores' }, [button('Ver emisiones comerciales', () => documents.scrollIntoView()), button('Ver movimientos de caja', () => transactions.scrollIntoView()), button('Ver pendientes al corte', () => receivables.scrollIntoView()), button('Ver gastos pagados efectivos', () => expenses.scrollIntoView()), el('a', { class: 'btn btn-secondary', href: `#/gastos?${queryString({ from: filters.from, to: filters.to, responsible_id: filters.responsible_id })}` }, 'Consultar registro de gastos')]),
                notice({ message: `${filters.client_id || filters.source_type ? 'Los filtros de cliente u origen excluyen los gastos generales de los indicadores. ' : ''}Emisión comercial, cobro efectivo y fecha del gasto son conceptos distintos. El detalle de gastos pagados usa la fecha efectiva de pago y sus importes negativos son salidas de caja.` }),
                dataTable({ caption: 'Cobros netos por origen (calculados por el servidor)', rows: Object.entries(summary.data.distribution).map(([origin, amount]) => ({ origin, amount })), columns: [{ label: 'Origen', render: (r) => r.origin === 'PROYECTO' ? 'Proyecto' : workLabel(r.origin) }, { label: 'Cobro neto (MXN)', render: (r) => moneyText(r.amount, 'MXN') }] }),
                dataTable({ caption: 'Evolución mensual de caja (meses UTC)', rows: cashflow.data, columns: [{ label: 'Mes UTC', render: (r) => r.month }, ...[['net_cash', 'Cobros netos'], ['paid_expenses', 'Gastos pagados'], ['cash_result', 'Resultado de caja']].map(([key, label]) => ({ label: `${label} (MXN)`, render: (r) => moneyText(r[key], 'MXN') }))] }), details]);
            details.append(documents, transactions, receivables, expenses); await Promise.all(lists.map((l) => l.load()));
        } catch (error) { if (disposed || ticket !== generation) return; controls.showError(error); mount(results, errorState({ error, title: error.code === 'HISTORY_INCOMPLETE' ? 'Historial incompleto: cifras no disponibles; no equivale a cero' : 'No se pudieron consultar las finanzas', onRetry: () => load(filters) })); }
    }
    await load(controls.values());
}
