/** Espacios y horarios completos (§12, D-22). */
import { el } from './api.js';
import { field, notice } from './ui.js';
import { button, idPath, selectField } from './project-ui.js';
import { decimal, moneyField } from './catalog-ui.js';
import { instantField, reservationDialog, spaceOptions } from './reservation-ui.js';

export const WEEKDAYS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
export function spaceForm(space, done) {
    return reservationDialog({ title: space ? 'Editar espacio' : 'Crear primer espacio o añadir otro', path: `/spaces${space ? `/${idPath(space.id)}` : ''}`, method: space ? 'PATCH' : 'POST', version: space?.version,
        fields: [field({ name: 'name', label: 'Nombre', value: space?.name, required: true }), field({ name: 'description', label: 'Descripción (opcional)', type: 'textarea', value: space?.description }),
            moneyField('hourly_rate', 'Tarifa por hora (MXN)', space?.hourly_rate ?? ''),
            field({ name: 'block_minutes', label: 'Duración del bloque en minutos', value: space?.block_minutes ?? '', required: true, pattern: '[0-9]+', inputMode: 'numeric' }),
            selectField('active', 'Estado', [{ value: '1', label: 'Activo' }, { value: '0', label: 'Inactivo' }], space?.active === false ? '0' : '1'),
            notice({ message: 'Después de crear el espacio, configura sus horarios. Sin ventanas de apertura no hay disponibilidad. No se instalan horarios ni tarifas de ejemplo.' })],
        body: (v) => ({ name: v.name.trim(), description: v.description.trim() || null, hourly_rate: decimal(v.hourly_rate, 2), block_minutes: Number(v.block_minutes), active: v.active === '1', ...(space ? { version: space.version } : {}) }), done, reload: done });
}

export function hoursForm(space, done, timezone) {
    const list = el('div', { class: 'stack space-hours' });
    const entries = [];
    function add(initial = {}) {
        const day = selectField('weekday', 'Día de la semana', [{ value: '', label: 'Selecciona un día' }, ...WEEKDAYS.map((label, i) => ({ value: String(i + 1), label }))], initial.weekday ? String(initial.weekday) : '', true);
        const start = field({ name: 'local_start_time', label: 'Apertura local (HH:MM:SS)', required: true, value: initial.local_start_time ?? '', pattern: '[0-9]{2}:[0-9]{2}:[0-9]{2}' });
        const end = field({ name: 'local_end_time', label: 'Cierre local (HH:MM:SS, admite 24:00:00)', required: true, value: initial.local_end_time ?? '', pattern: '[0-9]{2}:[0-9]{2}:[0-9]{2}' });
        const row = { day, start, end };
        const reindex = () => entries.forEach((r, i) => { r.day.control.name = `hours.${i}.weekday`; r.start.control.name = `hours.${i}.local_start_time`; r.end.control.name = `hours.${i}.local_end_time`; });
        const box = el('fieldset', { class: 'work-card stack' }, [el('legend', { text: 'Ventana de apertura' }), day, start, end, button('Eliminar esta ventana', () => { entries.splice(entries.indexOf(row), 1); box.remove(); reindex(); })]);
        entries.push(row); list.append(box); reindex();
    }
    (space.hours ?? []).forEach(add);
    return reservationDialog({ title: `Horario completo: ${space.name}`, path: `/spaces/${idPath(space.id)}/hours`, method: 'PUT', version: space.version,
        fields: [notice({ message: `Horas locales en la zona del estudio${timezone ? `: ${timezone}` : ' (identificada al consultar disponibilidad)'}. Se reemplaza la lista completa. Sin ventanas no está disponible. Divide un horario nocturno en dos días con extremo 24:00:00.` }), list, button('Añadir ventana de apertura', () => add())],
        body: () => ({ version: space.version, hours: entries.map((r) => ({ weekday: Number(r.day.control.value), local_start_time: r.start.control.value, local_end_time: r.end.control.value })) }), done, reload: done });
}

export function blockForm(spaces, done) {
    return reservationDialog({ title: 'Bloquear por mantenimiento', path: '/space-blocks', fields: [selectField('space_id', 'Espacio activo', spaceOptions(spaces.filter((s) => s.active)), '', true),
        instantField('start_at', 'Inicio con zona'), instantField('end_at', 'Fin con zona'), field({ name: 'reason', label: 'Motivo del mantenimiento', type: 'textarea', required: true }), notice({ message: 'El servidor comprueba los mismos conflictos que una reserva. Solo el éxito de la operación confirma el bloqueo.' })],
        body: (v) => ({ space_id: v.space_id, start_at: v.start_at, end_at: v.end_at, reason: v.reason.trim() }), done, reload: done });
}
