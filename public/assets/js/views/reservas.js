import { el } from '../api.js';
import { dataTable, field, mount, pageHeader } from '../ui.js';
import { button, cap, completeCollection, idPath, moneyText, pagedList, selectField } from '../project-ui.js';
import { asyncAction } from '../catalog-ui.js';
import { RESERVATION_STATUSES, workLabel, workOptions } from '../data.js';
import { instantField, reservationForm, spaceOptions } from '../reservation-ui.js';

export async function renderReservas(root, ctx) {
    if (!cap(ctx, 'reservations.viewOwn')) { mount(root, el('p', { role: 'alert', text: 'No tienes permiso para consultar reservas.' })); return; }
    const feedback = el('div');
    const rows = el('div', { class: 'stack' });
    const filters = {};
    const q = field({ name: 'q', label: 'Buscar reserva o banda', type: 'search' });
    const status = selectField('status', 'Estado', [{ value: '', label: 'Todos' }, ...workOptions(RESERVATION_STATUSES)]);
    const from = instantField('from', 'Desde (con zona, opcional)', '', false);
    const to = instantField('to', 'Hasta (con zona, opcional)', '', false);
    const spaces = await completeCollection('/spaces?active=1');
    const space = selectField('space_id', 'Espacio activo (opcional)', [{ value: '', label: 'Todos' }, ...spaceOptions(spaces).slice(1)]);
    const band = field({ name: 'band_client_id', label: 'ID de banda (filtro opcional)', pattern: '[1-9][0-9]*', inputMode: 'numeric' });
    const listing = pagedList(rows, '/reservations', filters, (data) => dataTable({ caption: 'Reservas autorizadas', rows: data, emptyTitle: 'Sin reservas para estos filtros', columns: [
        { label: 'Reserva', render: (r) => el('a', { class: 'reservation-document-link', href: `#/reservas/${idPath(r.id)}` }, `Reserva ${r.id}`) },
        { label: 'Banda', render: (r) => r.band_name }, { label: 'Espacio', render: (r) => r.space_name },
        { label: 'Inicio (zona explícita)', render: (r) => r.start_at }, { label: 'Fin (zona explícita)', render: (r) => r.end_at },
        { label: 'Estado', render: (r) => workLabel(r.status) }, { label: 'Total', render: (r) => moneyText(r.total, 'MXN') },
    ] }), 'reservas');
    const form = el('form', { class: 'filters' }, [q, status, space, band, from, to, el('button', { type: 'submit', class: 'btn btn-secondary' }, 'Filtrar')]);
    form.addEventListener('submit', (event) => {
        event.preventDefault(); if (!form.reportValidity()) return;
        Object.assign(filters, { q: q.control.value.trim(), status: status.control.value, space_id: space.control.value, band_client_id: band.control.value, from: from.control.value, to: to.control.value });
        void listing.reset();
    });
    mount(root, [pageHeader({ title: 'Reservas', lead: 'Reservas a tu cargo; administración puede consultar todas. La venta vinculada es la única vía de cobro.', actions: cap(ctx, 'reservations.create') ? button('Nueva reserva', asyncAction(feedback, () => reservationForm(ctx, null, ({ data }) => ctx.navigate(`/reservas/${data.id}`), () => listing.load())), true) : null }),
        el('p', {}, el('a', { class: 'btn btn-secondary', href: '#/espacios' }, 'Consultar disponibilidad y horarios')), form, feedback, rows]);
    void listing.load();
}
