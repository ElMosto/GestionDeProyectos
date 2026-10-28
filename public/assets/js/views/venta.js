import { api, el } from '../api.js';
import { confirmAction, dataTable, errorState, field, loadingState, mount, notice, pageHeader, toast, formatDateTime } from '../ui.js';
import { button, cap, editDialog, idPath, moneyText } from '../project-ui.js';
import { workLabel } from '../data.js';
import { asyncAction } from '../catalog-ui.js';
import { editSale, saleForm, saleOwner } from '../sale-ui.js';
import { renderPayments } from '../payment-ui.js';

export async function renderVenta(root, ctx, id) {
    const path = `/sales/${idPath(id)}`;
    let generation = 0;
    const reload = async () => {
        const ticket = ++generation;
        mount(root, loadingState('Cargando venta…'));
        try { const { data } = await api(path); if (ticket === generation) paint(data); }
        catch (error) { if (ticket === generation) mount(root, errorState({ error, onRetry: reload })); }
    };
    function paint(sale) {
        const feedback = el('div');
        const actions = el('div', { class: 'form-actions' });
        const payments = el('section', { class: 'stack', 'aria-label': 'Cobros de la venta' });
        const summary = sale.financial_summary;
        if (editSale(ctx, sale)) {
            actions.append(button('Editar borrador', asyncAction(feedback, () => saleForm(ctx, sale, reload))));
            if (cap(ctx, 'sales.issueOwn') && (saleOwner(ctx, sale) || cap(ctx, 'sales.editDraftAny'))) {
                const issue = button('Emitir venta', async () => {
                    if (issue.disabled) return;
                    issue.disabled = true;
                    try {
                        const confirmed = await confirmAction({ title: 'Emitir venta', message: 'Se recalcularán los precios vigentes y quedarán congelados. La venta no podrá editarse y todavía no estará cobrada.', destructive: false, summary: [sale.folio ?? 'Borrador', `Total provisional del servidor: ${moneyText(sale.total, sale.currency)}`], confirmLabel: 'Confirmar emisión' });
                        if (!confirmed) return;
                        await api(`${path}/issue`, { method: 'POST', body: { version: sale.version }, version: sale.version, idempotent: true });
                        toast('Venta emitida. No se ha registrado un cobro.', { tone: 'success' }); await reload();
                    } catch (error) { mount(feedback, errorState({ error, title: error.status === 409 ? 'La venta cambió o no puede emitirse. Recarga y revisa.' : 'No se pudo emitir', onRetry: reload })); }
                    finally { issue.disabled = false; }
                }, true);
                actions.append(issue);
            }
        }
        // D-18 / §10: cancelar solo administrador, incluso sin pagos. Reservas tienen su coordinador.
        if (cap(ctx, 'sales.cancelWithPayments') && sale.type === 'ORDINARIA' && sale.status !== 'CANCELADA') actions.append(button('Cancelar venta', () => editDialog({
            title: 'Cancelar venta (no reembolsar)', submitLabel: 'Confirmar cancelación', fields: [
                notice({ tone: 'warning', title: sale.folio ?? 'Venta', message: `Total: ${moneyText(sale.total, sale.currency)}. Neto cobrado: ${moneyText(summary?.net_paid, sale.currency)}. Si hay cobros pendientes de resolver, anula por error o reembolsa explícitamente antes de cancelar. No se ejecutan devoluciones automáticas.` }),
                field({ name: 'reason', label: 'Motivo de cancelación', type: 'textarea', required: true, maxLength: 255 }),
                field({ name: 'confirmation', label: 'Confirmo cancelar y conservar el historial', type: 'checkbox', required: true }),
            ], transform: (v) => ({ reason: v.reason.trim(), version: sale.version }), save: (body) => api(`${path}/cancel`, { method: 'POST', body, version: sale.version, idempotent: true }), done: reload,
        })));
        if (cap(ctx, 'sales.ticketOwn')) ['80mm', 'A4'].forEach((paper) => actions.append(el('a', { class: 'btn btn-secondary', href: `/api/v1/sales/${idPath(sale.id)}/ticket?paper=${paper}`, target: '_blank', rel: 'noopener noreferrer' }, `Ticket ${paper === '80mm' ? '80 mm' : paper}`)));
        mount(root, [el('p', {}, el('a', { href: '#/ventas' }, '← Volver a ventas')), pageHeader({ title: sale.folio ?? `Venta ${sale.id}`, lead: `${workLabel(sale.type)} · ${workLabel(sale.status)}` }),
            sale.status === 'BORRADOR' ? notice({ title: 'Total provisional', message: 'El servidor recalcula el borrador con el catálogo vigente. Revisa los importes antes de emitir. Emitir congela los precios; no registra dinero.' }) : null,
            sale.type === 'RESERVA' ? notice({ title: 'Venta de reserva', message: 'Los productos, horarios y cancelación se gestionan desde la reserva. Aquí solo se consulta y cobra el documento.' }) : null,
            el('dl', { class: 'project-facts' }, [
                ['Cliente', sale.client?.name ?? sale.client_name ?? (sale.client_id ? `Cliente ${sale.client_id}` : 'Sin cliente')],
                ['Responsable', sale.responsible?.name ?? sale.responsible_name ?? `Usuario ${sale.responsible_id}`],
                ['Emisión', formatDateTime(sale.issued_at, ctx.session.user.timezone)], ['Observaciones', sale.notes ?? '—'],
                ['Total', moneyText(sale.total, sale.currency)], ['Cobros registrados', moneyText(summary?.paid_amount, sale.currency)],
                ['Reembolsado', moneyText(summary?.refunded_amount, sale.currency)], ['Neto cobrado', moneyText(summary?.net_paid, sale.currency)],
                ['Saldo', moneyText(summary?.balance, sale.currency)], ['Estado de pago', workLabel(summary?.payment_status)],
            ].flatMap(([label, value]) => [el('dt', { text: label }), el('dd', { text: value })])),
            dataTable({ caption: 'Partidas de la venta', rows: sale.items, emptyTitle: 'Borrador sin partidas', columns: [
                { label: 'Producto / concepto', render: (item) => item.name_snapshot }, { label: 'Cantidad', render: (item) => item.quantity },
                { label: 'Precio base', render: (item) => moneyText(item.unit_price, sale.currency) },
                { label: 'Extras por unidad', render: (item) => item.extras?.length ? el('ul', {}, item.extras.map((extra) => el('li', { text: `${extra.name_snapshot} · ${extra.quantity_per_unit} × ${moneyText(extra.unit_price, sale.currency)}` }))) : 'Sin extras' },
                { label: 'Importe', render: (item) => moneyText(item.line_total, sale.currency) },
            ] }), actions, feedback, payments,
        ]);
        void renderPayments(payments, ctx, sale, 'sale', reload);
    }
    await reload();
}
