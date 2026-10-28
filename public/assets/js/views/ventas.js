import { el } from '../api.js';
import { dataTable, field, mount, pageHeader } from '../ui.js';
import { button, cap, clientPicker, moneyText, pagedList, selectField } from '../project-ui.js';
import { workLabel, workOptions } from '../data.js';
import { saleForm } from '../sale-ui.js';
import { asyncAction } from '../catalog-ui.js';

export function renderVentas(root, ctx) {
    if (!cap(ctx, 'sales.viewOwn')) { mount(root, el('p', { role: 'alert', text: 'No tienes permiso para consultar ventas.' })); return; }
    const feedback = el('div');
    const rows = el('div', { class: 'stack' });
    const filters = {};
    const q = field({ name: 'q', label: 'Buscar por folio', type: 'search' });
    const status = selectField('status', 'Estado', [{ value: '', label: 'Todos' }, ...workOptions(['BORRADOR', 'EMITIDA', 'CANCELADA'])]);
    const type = selectField('type', 'Tipo', [{ value: '', label: 'Todos' }, ...workOptions(['ORDINARIA', 'RESERVA'])]);
    const from = field({ name: 'from', label: 'Emitida desde (UTC)', type: 'date' });
    const to = field({ name: 'to', label: 'Emitida hasta (UTC)', type: 'date' });
    const client = clientPicker();
    client.querySelector('legend').textContent = 'Cliente activo (filtro opcional)';
    const listing = pagedList(rows, '/sales', filters, (data) => dataTable({ caption: 'Ventas visibles', rows: data, emptyTitle: 'Sin ventas para estos filtros', columns: [
        { label: 'Folio', render: (sale) => el('a', { class: 'commerce-document-link', href: `#/ventas/${encodeURIComponent(sale.id)}` }, sale.folio ?? `Venta ${sale.id}`) },
        { label: 'Tipo', render: (sale) => workLabel(sale.type) }, { label: 'Estado', render: (sale) => workLabel(sale.status) },
        { label: 'Total', render: (sale) => moneyText(sale.total ?? sale.financial_summary?.total, sale.currency) },
        { label: 'Cobros y saldo', render: (sale) => el('a', { href: `#/ventas/${encodeURIComponent(sale.id)}` }, 'Consultar detalle') },
    ] }), 'ventas');
    const form = el('form', { class: 'filters' }, [q, status, type, from, to, client, el('button', { type: 'submit', class: 'btn btn-secondary' }, 'Filtrar')]);
    form.addEventListener('submit', (event) => {
        event.preventDefault(); if (!form.reportValidity()) return;
        Object.assign(filters, { q: q.control.value.trim(), status: status.control.value, type: type.control.value, from: from.control.value, to: to.control.value, client_id: client.querySelector('select[name="client_id"]').value });
        void listing.reset();
    });
    mount(root, [pageHeader({ title: 'Ventas', lead: 'Documentos, saldos y cobros autorizados. Emitir una venta no registra dinero.', actions: cap(ctx, 'sales.create') ? button('Nueva venta ordinaria', asyncAction(feedback, () => saleForm(ctx, null, ({ data }) => ctx.navigate(`/ventas/${data.id}`))), true) : null }), form, feedback, rows]);
    void listing.load();
}
