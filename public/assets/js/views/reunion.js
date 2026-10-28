import { api, el } from '../api.js';
import { field, formatDateTime, notice, pageHeader, panel } from '../ui.js';
import { button, cap, loadSection } from '../project-ui.js';
import { asyncAction } from '../catalog-ui.js';
import { editMeetingForm, meetingDialog, meetingOwner, positiveId } from '../meeting-ui.js';
import { instantField } from '../reservation-ui.js';
import { workLabel } from '../data.js';

export async function renderReunion(root, ctx, id) {
    if (!positiveId(id)) return;
    const reload = () => renderReunion(root, ctx, id);
    return loadSection(root, () => api(`/meetings/${id}`), ({ data: row }) => {
        const owner = meetingOwner(ctx, row);
        const invitation = row.invitation;
        const actions = [];
        const feedback = el('div');
        const zone = ctx.session.user.timezone;
        const entries = [['Estado', workLabel(row.status)], ['Organizador', row.organizer?.name], ['Inicio', formatDateTime(row.start_at, zone)], ['Fin', formatDateTime(row.end_at, zone)], ['Modalidad', workLabel(row.modality)]];
        // No inferir acceso completo por rol: el DTO decide qué campos existen.
        if (Object.hasOwn(row, 'description')) entries.push(['Descripción', row.description || 'Sin descripción']);
        if (Object.hasOwn(row, 'location')) entries.push(['Lugar', row.location || 'Sin lugar indicado']);
        if (Object.hasOwn(row, 'space_id')) entries.push(['Espacio del estudio (ID)', row.space_id || 'Sin espacio del estudio']);
        if (invitation) entries.push(['Mi respuesta', workLabel(invitation.response)]);
        const dialog = (title, suffix, fields, body, method = 'POST', version = row.version) => {
            const control = meetingDialog({ title, path: `/meetings/${id}${suffix}`, fields, body, method, version, done: reload });
            ctx.onCleanup?.(() => control.close());
            return control;
        };
        if (owner && row.status === 'PROGRAMADA' && cap(ctx, 'meetings.create')) {
            actions.push(button('Editar datos', asyncAction(feedback, () => editMeetingForm(ctx, row, reload))));
            actions.push(button('Reprogramar', () => dialog('Confirmar nuevo horario', '', [
                notice({ tone: 'warning', message: 'Cambiar inicio o fin restablece TODAS las invitaciones a pendiente, incluidas las rechazadas. Cada persona deberá aceptar de nuevo y la reunión desaparecerá temporalmente de su agenda.' }),
                instantField('start_at', 'Inicio con zona', row.start_at), instantField('end_at', 'Fin con zona', row.end_at),
            ], (v) => ({ version: row.version, start_at: v.start_at, end_at: v.end_at }), 'PATCH')));
            actions.push(button('Cancelar reunión', () => dialog('Confirmar cancelación de reunión', '/cancel', [notice({ tone: 'warning', message: 'Se cancelará la reunión para todas las personas y se liberará el espacio. El historial se conserva. No se generan ventas ni reembolsos.' }), field({ name: 'reason', label: 'Motivo de cancelación', type: 'textarea', required: true })], (v) => ({ version: row.version, reason: v.reason.trim() }))));
        }
        if (!owner && invitation && row.status === 'PROGRAMADA' && cap(ctx, 'meetings.respond')) {
            for (const [response, label] of [['ACCEPTED', 'Aceptar'], ['DECLINED', 'Rechazar']]) actions.push(button(label, () => dialog(`${label} invitación`, '/response', [notice({ message: `Confirma tu respuesta para ${formatDateTime(row.start_at, zone)}. Solo se modifica tu invitación; no la de otras personas.` })], () => ({ response, version: invitation.version }), 'POST', invitation.version)));
        }
        return [pageHeader({ title: row.title || 'Solicitud de reunión', lead: 'Los permisos se comprueban de nuevo en el servidor.' }),
            el('a', { class: 'btn btn-secondary', href: '#/reuniones' }, 'Volver a mis reuniones'),
            panel({ title: 'Solicitud', children: el('dl', { class: 'summary-list' }, entries.map(([label, value]) => el('div', { class: 'summary-item' }, [el('dt', { text: label }), el('dd', { text: value ?? '—' })]))) }),
            !owner && invitation?.response !== 'ACEPTADA' ? notice({ message: 'Esta solicitud aún no se incorpora a tu agenda. El detalle privado solo está disponible después de aceptar.' }) : null,
            owner && Array.isArray(row.invitations) ? panel({ title: 'Respuestas individuales', children: row.invitations.length ? el('ul', { class: 'stack' }, row.invitations.map((i) => el('li', { text: `${i.invitee.name}: ${workLabel(i.response)}` }))) : el('p', { text: 'Sin personas invitadas.' }) }) : null,
            actions.length ? panel({ title: 'Acciones', children: el('div', { class: 'form-actions' }, actions) }) : null, feedback];
    });
}
