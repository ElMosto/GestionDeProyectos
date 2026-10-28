/** §13; D-24/D-25. Presentación: la API decide acceso, conflictos y versiones. */
import { api, el } from './api.js';
import { applyFormError, clearFieldErrors, field, formAlert, formValues, notice, openDialog, toast } from './ui.js';
import { completeCollection, selectField } from './project-ui.js';
import { instantField } from './reservation-ui.js';
import { workLabel } from './data.js';

export const positiveId = (id) => /^[1-9]\d*$/.test(String(id));
export const meetingOwner = (ctx, row) => String(row.organizer?.id) === String(ctx.session.user.id);

export function meetingDialog({ title, fields, path, method = 'POST', version, body, done }) {
    const alert = formAlert();
    const form = el('form', { class: 'stack meeting-form' }, [alert, ...fields]);
    const submit = el('button', { type: 'submit', class: 'btn btn-primary' }, 'Confirmar');
    form.append(submit);
    const dialog = openDialog({ title, content: form });
    let busy = false;
    let acknowledgment = null;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        clearFieldErrors(form); alert.hidden = true; busy = true;
        const controls = [...form.querySelectorAll('input,select,textarea,button')].filter((c) => !c.disabled);
        try {
            const payload = body(formValues(form));
            if (acknowledgment?.control.checked) payload.acknowledge_conflicts = true;
            controls.forEach((c) => { c.disabled = true; });
            await api(path, { method, version, body: payload, idempotent: true });
            dialog.close(); toast('Reunión actualizada.', { tone: 'success' }); await done?.();
        } catch (error) {
            applyFormError(error, form, alert);
            if (error.code === 'CONFLICTS_REQUIRE_ACK' && !acknowledgment) {
                acknowledgment = field({ name: 'acknowledge_conflicts', type: 'checkbox', label: 'Entiendo que existe un cruce con mis actividades y confirmo continuar.' });
                form.insertBefore(acknowledgment, submit);
            }
            if (error.code === 'CONFLICTS_REQUIRE_ACK') alert.append(el('p', { text: 'Hay un cruce con tus actividades. Puedes confirmar expresamente la advertencia para continuar. Esto nunca permite ocupar un espacio del estudio ya ocupado.' }));
            if (error.status === 409) alert.append(el('p', { text: error.code === 'CONFLICTS_REQUIRE_ACK' ? 'También puedes cerrar y consultar de nuevo el estado actual.' : 'El horario, las respuestas o la disponibilidad pueden haber cambiado. Recarga antes de confirmar de nuevo.' }),
                el('button', { type: 'button', class: 'btn btn-secondary', onClick: () => { dialog.close(); void done?.(); } }, 'Cerrar y recargar'));
        } finally { controls.forEach((c) => { c.disabled = false; }); busy = false; }
    });
    return dialog;
}

export async function meetingForm(ctx, done) {
    const [users, spaces] = await Promise.all([completeCollection('/users/directory'), completeCollection('/spaces?active=1')]);
    const invited = selectField('invited_user_ids', 'Personas invitadas (selección múltiple, opcional)', users.filter((u) => u.active !== false && String(u.id) !== String(ctx.session.user.id)).map((u) => ({ value: String(u.id), label: u.name })), '');
    invited.control.multiple = true; invited.control.size = 6; invited.control.selectedIndex = -1;
    const dialog = meetingDialog({ title: 'Nueva solicitud de reunión', path: '/meetings', done,
        fields: [el('p', { text: `Organizador: ${ctx.session.user.name}. Las personas invitadas quedan fijadas al crear la solicitud.` }),
            field({ name: 'title', label: 'Título', required: true, maxLength: 200 }), field({ name: 'description', label: 'Descripción (opcional)', type: 'textarea' }),
            instantField('start_at', 'Inicio con zona'), instantField('end_at', 'Fin con zona'),
            selectField('modality', 'Modalidad', [{ value: 'PRESENCIAL', label: 'Presencial' }, { value: 'VIRTUAL', label: 'Virtual' }, { value: 'HIBRIDA', label: 'Híbrida' }], 'PRESENCIAL', true),
            field({ name: 'location', label: 'Lugar (opcional)' }),
            selectField('space_id', 'Espacio del estudio (opcional)', [{ value: '', label: 'Sin espacio del estudio' }, ...spaces.map((s) => ({ value: String(s.id), label: s.name }))]), invited,
            notice({ message: 'Las reuniones personales no exigen bloques de 30 minutos. Con espacio del estudio, el servidor valida apertura y exclusión de ocupación. Crear la solicitud no acepta por los invitados.' })],
        body: (v) => ({ title: v.title.trim(), description: v.description.trim() || null, start_at: v.start_at, end_at: v.end_at, modality: v.modality, location: v.location.trim() || null, space_id: v.space_id || null, invited_user_ids: [...invited.control.selectedOptions].map((o) => o.value) }) });
    ctx.onCleanup?.(() => dialog.close());
    return dialog;
}

export async function editMeetingForm(ctx, row, done) {
    if (!positiveId(row.id)) throw new Error('Identificador de reunión no válido. Recarga la solicitud.');
    const spaces = await completeCollection('/spaces?active=1');
    const options = [{ value: '', label: 'Sin espacio del estudio' }, ...spaces.map((s) => ({ value: String(s.id), label: s.name }))];
    if (row.space_id && !spaces.some((s) => String(s.id) === String(row.space_id))) options.push({ value: String(row.space_id), label: 'Espacio actual' });
    const dialog = meetingDialog({ title: 'Editar reunión', path: `/meetings/${row.id}`, method: 'PATCH', version: row.version, done,
        fields: [field({ name: 'title', label: 'Título', required: true, value: row.title, maxLength: 200 }),
            field({ name: 'description', label: 'Descripción', type: 'textarea', value: row.description }),
            field({ name: 'location', label: 'Lugar', value: row.location }),
            selectField('modality', 'Modalidad', ['PRESENCIAL', 'VIRTUAL', 'HIBRIDA'].map((value) => ({ value, label: workLabel(value) })), row.modality),
            selectField('space_id', 'Espacio del estudio', options, row.space_id ?? ''),
            el('p', { text: 'No se pueden reemplazar invitados. Estos cambios conservan respuestas si no cambia el horario. El servidor vuelve a validar cualquier cambio de espacio.' })],
        body: (v) => ({ version: row.version, title: v.title.trim(), description: v.description.trim() || null, location: v.location.trim() || null, modality: v.modality, space_id: v.space_id || null }) });
    ctx.onCleanup?.(() => dialog.close());
    return dialog;
}
