import { api, el } from '../api.js';
import { field, notice, pageHeader, panel } from '../ui.js';
import { button, cap, idPath, loadSection, moneyText } from '../project-ui.js';
import { asyncAction } from '../catalog-ui.js';
import { workLabel } from '../data.js';
import { canChangeReservation, reservationDialog, reservationForm } from '../reservation-ui.js';

export async function renderReserva(root, ctx, id) {
    const reload = () => renderReserva(root, ctx, id);
    return loadSection(root, () => api(`/reservations/${idPath(id)}`), ({ data: row }) => {
        const feedback = el('div');
        const canChange = canChangeReservation(ctx, row);
        const action = (label, suffix, fields, extra, submitLabel) => button(label, () => reservationDialog({ title: label, path: `/reservations/${idPath(id)}${suffix}`, version: row.version, fields,
            body: (v) => ({ version: row.version, ...extra(v) }), done: reload, reload, submitLabel }));
        const actions = [];
        if (canChange) {
            actions.push(button('Editar notas', () => reservationDialog({ title: 'Editar notas', path: `/reservations/${idPath(id)}`, method: 'PATCH', version: row.version,
                fields: [field({ name: 'notes', label: 'Notas (opcional)', type: 'textarea', value: row.notes })], body: (v) => ({ version: row.version, notes: v.notes.trim() || null }), done: reload, reload })));
            // PATCH jamás puede cambiar espacio, estado ni horario.
            if (row.status === 'CONFIRMED') actions.push(button('Reprogramar', asyncAction(feedback, () => reservationForm(ctx, row, reload, reload))));
            const next = row.status === 'CONFIRMED' ? 'IN_PROGRESS' : row.status === 'IN_PROGRESS' ? 'FINISHED' : null;
            if (next) actions.push(action(next === 'IN_PROGRESS' ? 'Iniciar uso' : 'Finalizar uso', '/transitions', [notice({ message: `Confirma cambiar el estado a ${workLabel(next)}. El estado del pago es independiente del uso del espacio.` }), field({ name: 'reason', label: 'Motivo (opcional)', type: 'textarea' })], (v) => ({ status: next, ...(v.reason.trim() ? { reason: v.reason.trim() } : {}) }), 'Confirmar cambio de estado'));
        }
        if (cap(ctx, 'reservations.cancelAny') && row.status !== 'CANCELLED' && row.status !== 'FINISHED') actions.push(action('Cancelar reserva y venta', '/cancel', [notice({ tone: 'warning', message: 'Solo administración puede cancelar, incluso sin pagos. Requiere neto cobrado cero: resuelve antes anulaciones o reembolsos en la venta. No se realizan reembolsos ni penalizaciones automáticos.' }),
            field({ name: 'reason', label: 'Motivo de cancelación', type: 'textarea', required: true })], (v) => ({ reason: v.reason.trim() }), 'Confirmar cancelación'));
        const entries = [['Banda', row.band_name], ['Espacio', row.space_name], ['Estado', workLabel(row.status)], ['Responsable (ID)', row.responsible_id],
            ['Inicio (ISO con zona)', row.start_at], ['Fin (ISO con zona)', row.end_at], ['Duración (minutos)', row.duration_minutes ?? 'No informada por el servidor'],
            ['Tarifa histórica por hora', moneyText(row.hourly_rate_snapshot, 'MXN')], ['Total del servidor', moneyText(row.total, 'MXN')], ['Notas', row.notes || 'Sin notas']];
        if (row.financial_summary) entries.push(['Neto cobrado', moneyText(row.financial_summary.net_paid, 'MXN')], ['Saldo', moneyText(row.financial_summary.balance, 'MXN')]);
        return [pageHeader({ title: `Reserva ${row.id}`, lead: 'Reserva y venta coordinadas por el servidor. No se calcula dinero en el navegador.' }),
            el('div', { class: 'form-actions' }, [el('a', { class: 'btn btn-secondary', href: '#/reservas' }, 'Volver a reservas'), row.sale_id ? el('a', { class: 'btn btn-primary', href: `#/ventas/${idPath(row.sale_id)}` }, 'Venta vinculada: cobros, saldo y tickets') : null]),
            panel({ title: 'Resumen', children: el('dl', { class: 'summary-list' }, entries.map(([label, value]) => el('div', { class: 'summary-item' }, [el('dt', { text: label }), el('dd', { text: value ?? '—' })]))) }),
            notice({ message: 'Cancelar una reserva es una operación exclusiva de administración. Cobrar, anular y reembolsar se hace en la venta vinculada, sin un segundo flujo de pagos.' }),
            actions.length ? panel({ title: 'Acciones autorizadas', children: el('div', { class: 'form-actions' }, actions) }) : null, feedback];
    });
}
