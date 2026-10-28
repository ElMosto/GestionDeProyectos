/** Cobros únicos para venta/proyecto (§11); ningún saldo se calcula aquí. */
import { api, el } from './api.js';
import { dataTable, field, formatDateTime, mount, notice } from './ui.js';
import { button, cap, editDialog, idPath, moneyText, pagedList, selectField } from './project-ui.js';
import { decimal, moneyField, optional } from './catalog-ui.js';
import { workLabel } from './data.js';

const methods = ['EFECTIVO', 'TRANSFERENCIA', 'TARJETA', 'OTRO'].map((value) => ({ value, label: ({ EFECTIVO: 'Efectivo', TRANSFERENCIA: 'Transferencia', TARJETA: 'Tarjeta', OTRO: 'Otro' })[value] }));
const effectiveField = () => field({ name: 'effective_at', label: 'Fecha y hora efectiva (ISO con zona)', value: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'), required: true, help: 'Incluye segundos y Z o desplazamiento de zona, por ejemplo 2026-10-28T14:00:00-06:00. Sin milisegundos. La fecha inicial es el instante actual en UTC.' });
const confirmation = () => field({ name: 'confirmation', label: 'Confirmo la operación y los datos anteriores', type: 'checkbox', required: true });
const reasonField = () => field({ name: 'reason', label: 'Motivo obligatorio', type: 'textarea', required: true, maxLength: 255 });

function paymentForm(document, kind, markPaid, done) {
    const path = `/${kind === 'sale' ? 'sales' : 'projects'}/${idPath(document.id)}`;
    return editDialog({ title: markPaid ? 'Marcar venta pagada: cobrar saldo pendiente' : 'Registrar abono', submitLabel: 'Confirmar registro de cobro', fields: [
        notice({ title: `${document.folio ?? document.name ?? 'Documento'} · ${kind === 'sale' ? 'Venta' : 'Proyecto'}`, message: `Saldo informado: ${moneyText(document.financial_summary?.balance, document.currency)}. ${markPaid ? 'El servidor cobrará el saldo actual dentro de la transacción; no se envía un importe.' : 'El servidor verifica el saldo y rechaza sobrepagos.'}` }),
        ...(!markPaid ? [moneyField('amount', 'Importe del abono', '')] : []), effectiveField(),
        selectField('method', 'Medio de pago', methods, 'EFECTIVO', true),
        field({ name: 'reference', label: 'Referencia (opcional)', help: 'No registres números de tarjeta.' }),
        field({ name: 'notes', label: 'Notas (opcional)', type: 'textarea' }), confirmation(),
    ], transform: (v) => ({ ...(!markPaid ? { amount: decimal(v.amount, 2) } : {}), method: v.method, effective_at: v.effective_at.trim(), reference: optional(v.reference), notes: optional(v.notes) }),
    save: (body) => api(`${path}/${markPaid ? 'mark-paid' : 'payments'}`, { method: 'POST', body, version: document.version, idempotent: true }), done });
}

function correction(payment, refund, currency, done) {
    return editDialog({ title: refund ? 'Registrar devolución real' : 'Anular pago por error de registro', submitLabel: refund ? 'Confirmar reembolso' : 'Confirmar anulación', fields: [
        notice({ tone: 'warning', title: `${payment.folio ?? 'Pago'} · ${moneyText(payment.amount, currency)}`, message: refund ? 'Devolución real vinculada a este pago. El servidor limita el importe aún no reembolsado. No cancela el documento.' : 'El pago dejará de contar, pero se conserva en el historial. No es una devolución de dinero y no cancela el documento.' }),
        ...(refund ? [moneyField('amount', 'Importe a reembolsar'), effectiveField()] : []), reasonField(), confirmation(),
    ], transform: (v) => ({ reason: v.reason.trim(), ...(refund ? { amount: decimal(v.amount, 2), effective_at: v.effective_at.trim() } : {}) }),
    save: (body) => api(`/payments/${idPath(payment.id)}/${refund ? 'refunds' : 'void'}`, { method: 'POST', body, idempotent: true }), done });
}

export function renderPayments(root, ctx, document, kind, reloadDocument) {
    const path = `/${kind === 'sale' ? 'sales' : 'projects'}/${idPath(document.id)}`;
    const summary = document.financial_summary;
    const canRegister = cap(ctx, kind === 'sale' ? 'payments.registerOwn' : 'payments.registerProject');
    const ready = kind === 'sale' ? document.status === 'EMITIDA' : document.billable === true && document.status !== 'CANCELADO';
    const payable = ready && summary?.payment_status !== 'SIN_CARGO' && summary?.payment_status !== 'PAGADO';
    const rows = el('div', { class: 'stack' });
    const actions = el('div', { class: 'form-actions' });
    if (canRegister && payable) {
        actions.append(button('Registrar abono', () => paymentForm(document, kind, false, reloadDocument), true));
        if (kind === 'sale') actions.append(button('Marcar pagada (cobrar saldo)', () => paymentForm(document, kind, true, reloadDocument)));
    }
    const refunds = (payment, host) => {
        const list = pagedList(host, `/payments/${idPath(payment.id)}/refunds`, {}, (data) => dataTable({ caption: `Reembolsos de ${payment.folio}`, rows: data, emptyTitle: 'Sin reembolsos en este pago', columns: [
            { label: 'Folio', render: (r) => r.folio },
            { label: 'Fecha efectiva', render: (r) => formatDateTime(r.effective_at, ctx.session.user.timezone) },
            { label: 'Importe', render: (r) => moneyText(r.amount, document.currency) },
            { label: 'Estado', render: (r) => workLabel(r.status) }, { label: 'Motivo', render: (r) => r.reason },
        ] }), 'reembolsos');
        void list.load();
    };
    const listing = pagedList(rows, `${path}/payments`, {}, (data) => dataTable({ caption: 'Historial de pagos', rows: data, emptyTitle: 'Sin pagos registrados', columns: [
        { label: 'Folio / origen', render: (p) => `${p.folio ?? '—'} · ${kind === 'sale' ? 'Venta' : 'Proyecto'} ${document.folio ?? document.id}` },
        { label: 'Fecha efectiva', render: (p) => formatDateTime(p.effective_at, ctx.session.user.timezone) },
        { label: 'Importe', render: (p) => moneyText(p.amount, document.currency) },
        { label: 'Medio / referencia', render: (p) => `${methods.find((m) => m.value === p.method)?.label ?? p.method ?? '—'} · ${p.reference ?? 'Sin referencia'}` },
        { label: 'Estado / motivo de anulación', render: (p) => `${workLabel(p.status)}${p.void_reason ? ` · ${p.void_reason}` : ''}` },
        { label: 'Historial y correcciones', render: (payment) => {
            const host = el('div', { class: 'stack' });
            const history = el('div', { class: 'payment-refunds' });
            host.append(el('div', { class: 'form-actions' }, [button('Ver reembolsos', () => refunds(payment, history)),
                cap(ctx, 'payments.void') && payment.status === 'REGISTRADO' ? button('Anular pago', () => correction(payment, false, document.currency, reloadDocument)) : null,
                cap(ctx, 'payments.refund') && payment.status === 'REGISTRADO' ? button('Reembolsar', () => correction(payment, true, document.currency, reloadDocument)) : null,
            ]), history);
            return host;
        } },
    ] }), 'pagos');
    mount(root, [el('h3', { text: 'Cobros e historial' }),
        summary?.payment_status === 'SIN_CARGO' ? notice({ title: 'Sin cargo', message: 'Total cero: no se registra un pago ficticio.' }) : null,
        actions, rows]);
    return listing.load();
}
