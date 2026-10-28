import { el } from '../api.js';
import { emptyState, formatDateTime, notice, pageHeader } from '../ui.js';
import { button, cap, pagedList } from '../project-ui.js';
import { asyncAction } from '../catalog-ui.js';
import { meetingForm, positiveId } from '../meeting-ui.js';
import { workLabel } from '../data.js';

export function renderReuniones(root, ctx) {
    if (!cap(ctx, 'meetings.create') && !cap(ctx, 'meetings.respond')) {
        root.replaceChildren(notice({ message: 'No tienes permiso para consultar reuniones.' })); return;
    }
    const feedback = el('div'); const list = el('div');
    const controller = pagedList(list, '/meetings', {}, (rows) => rows.length ? el('ul', { class: 'meeting-list stack' }, rows.filter((row) => positiveId(row.id)).map((row) => el('li', { class: 'panel' }, [
        el('a', { class: 'btn btn-secondary', href: `#/reuniones/${row.id}` }, row.title || 'Solicitud de reunión'),
        el('p', { text: `${workLabel(row.status)} · ${formatDateTime(row.start_at, ctx.session.user.timezone)} — ${formatDateTime(row.end_at, ctx.session.user.timezone)}` }),
        row.invitation ? el('p', { text: `Mi respuesta: ${workLabel(row.invitation.response)}` }) : null,
        el('p', { text: 'Solicitud propia o invitación. Solo las reuniones aceptadas se incorporan a la agenda de invitados.' }),
    ]))) : emptyState({ title: 'Sin reuniones', message: 'No tienes solicitudes ni invitaciones disponibles.' }), 'reuniones');
    root.replaceChildren(pageHeader({ title: 'Mis reuniones', lead: 'Solo reuniones que organizas o a las que estás invitado; no es una agenda global.' }),
        ...(cap(ctx, 'meetings.create') ? [button('Nueva reunión', asyncAction(feedback, () => meetingForm(ctx, controller.load)), true)] : []), feedback, list);
    return controller.load();
}
