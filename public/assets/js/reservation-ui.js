/** Reservas §12, D-20–23. Solo forma y presentación; la API decide disponibilidad y dinero. */
import { api, el } from './api.js';
import { applyFormError, clearFieldErrors, field, formAlert, formValues, notice, openDialog, toast } from './ui.js';
import { button, cap, completeCollection, idPath, selectField } from './project-ui.js';

export const reservationOwner = (ctx, row) => String(row.responsible_id) === String(ctx.session.user.id);
export const canChangeReservation = (ctx, row) => cap(ctx, 'reservations.cancelAny') || (cap(ctx, 'reservations.rescheduleOwn') && reservationOwner(ctx, row));
export const instantField = (name, label, value = '', required = true) => field({ name, label, value, required,
    placeholder: '2026-12-15T09:00:00-06:00', pattern: '[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(Z|([+]|-)[0-9]{2}:[0-9]{2})',
    help: 'ISO 8601 completo con segundos y zona explícita: Z para UTC o desplazamiento ±HH:MM. No se interpreta como hora local del navegador.' });
export const spaceOptions = (spaces) => [{ value: '', label: 'Selecciona un espacio activo' }, ...spaces.map((s) => ({ value: String(s.id), label: s.name }))];

/** El mismo envío se reintenta por api.js; no se generan claves en las vistas. */
export function reservationDialog({ title, fields, path, method = 'POST', version, body, done, submitLabel = 'Confirmar', reload = done }) {
    const alert = formAlert();
    const form = el('form', { class: 'stack reservation-form' }, [alert, ...fields]);
    const submit = el('button', { type: 'submit', class: 'btn btn-primary' }, submitLabel);
    form.append(submit);
    const dialog = openDialog({ title, content: form });
    let busy = false;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        clearFieldErrors(form); alert.hidden = true; busy = true;
        const controls = [...form.querySelectorAll('input,select,textarea,button')].filter((c) => !c.disabled);
        try {
            const payload = body(formValues(form));
            controls.forEach((c) => { c.disabled = true; });
            const response = await api(path, { method, version, body: payload, idempotent: true });
            dialog.close(); toast('Operación realizada.', { tone: 'success' }); await done?.(response);
        } catch (error) {
            controls.forEach((c) => { c.disabled = false; });
            applyFormError(error, form, alert);
            if (error.status === 409) alert.append(el('p', { text: 'No se aplicó un cambio parcial. Recarga el estado actual antes de volver a confirmar.' }), button('Cerrar y recargar', () => { dialog.close(); void reload?.(); }));
        } finally { controls.forEach((c) => { c.disabled = false; }); busy = false; }
    });
    return dialog;
}

export async function reservationForm(ctx, reservation, done, reload) {
    const [spaces, bands] = await Promise.all([completeCollection('/spaces?active=1'), reservation ? Promise.resolve([]) : completeCollection('/clients?active=1&type=BANDA')]);
    const space = selectField('space_id', 'Espacio', spaceOptions(spaces), reservation?.space_id ?? '', true);
    const warning = notice({ tone: 'warning', message: 'Este formulario no guarda un borrador ni bloquea el horario. Solo la confirmación del servidor garantiza la reserva y genera una única venta, sin registrar pago.' });
    const fields = [warning];
    if (!reservation) fields.push(selectField('band_client_id', 'Cliente banda activo', [{ value: '', label: 'Selecciona una banda' }, ...bands.filter((b) => b.type === 'BANDA' && b.active).map((b) => ({ value: String(b.id), label: b.name }))], '', true),
        cap(ctx, 'clients.create') ? el('p', {}, el('a', { href: '#/clientes' }, 'Crear cliente de tipo BANDA y regresar a Reservas (no crea una cuenta de usuario)')) : null);
    fields.push(space, instantField('start_at', 'Inicio con zona', reservation?.start_at), instantField('end_at', 'Fin con zona', reservation?.end_at),
        el('p', { text: 'Consulta primero Espacios para ver ventanas de apertura en la zona del estudio. El servidor valida duración, bloques, horarios y conflictos; no hay tarifas ni horarios supuestos.' }));
    if (!reservation) fields.push(field({ name: 'notes', label: 'Notas (opcional)', type: 'textarea' }));
    if (cap(ctx, 'reservations.retroactive')) fields.push(field({ name: 'reason', label: 'Motivo (obligatorio para una operación retrospectiva)', type: 'textarea' }));
    if (reservation) fields.push(el('p', { text: 'Mismo espacio: conserva tarifa histórica. Otro espacio: adopta tarifa vigente. Si el nuevo total queda por debajo del neto cobrado, un administrador debe resolver antes los cobros en la venta.' }));
    return reservationDialog({ title: reservation ? 'Reprogramar reserva' : 'Nueva reserva confirmada', fields,
        path: reservation ? `/reservations/${idPath(reservation.id)}/reschedule` : '/reservations', version: reservation?.version,
        body: (v) => ({ space_id: v.space_id, start_at: v.start_at, end_at: v.end_at, ...(v.reason?.trim() ? { reason: v.reason.trim() } : {}),
            ...(reservation ? { version: reservation.version } : { band_client_id: v.band_client_id, notes: v.notes.trim() || null }) }), done, reload });
}
