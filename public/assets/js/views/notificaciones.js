import { api, el } from '../api.js';
import { emptyState, errorState, formatDateTime, loadingState, mount, notice, pageHeader, pagination, toast } from '../ui.js';
import { button, cap, selectField } from '../project-ui.js';
import { notificationLink, visiblePolling } from '../notification-ui.js';
import { positiveId } from '../meeting-ui.js';

export function renderNotificaciones(root, ctx) {
    if (!cap(ctx, 'notifications.viewOwn')) {
        root.replaceChildren(notice({ message: 'No tienes permiso para consultar notificaciones.' })); return;
    }
    let alive = true; let sequence = 0; let offset = 0;
    const list = el('div'); const feedback = el('div');
    const count = el('p', { 'aria-live': 'polite' });
    const unread = selectField('unread', 'Mostrar notificaciones', [{ value: '', label: 'Todas' }, { value: '1', label: 'Solo no leídas' }]);
    const load = async () => {
        const ticket = ++sequence;
        mount(list, loadingState('Consultando tus notificaciones…'));
        try {
            const query = new URLSearchParams({ limit: '25', offset: String(offset) });
            if (unread.control.value === '1') query.set('unread', '1');
            const { data, meta } = await api(`/notifications?${query}`);
            if (!alive || ticket !== sequence) return;
            count.textContent = `No leídas: ${meta.unread_count}`;
            const items = data.filter((row) => positiveId(row.id)).map((row) => {
                const href = notificationLink(row);
                const mark = button('Marcar como leída', async () => {
                    if (mark.disabled) return;
                    mark.disabled = true;
                    try {
                        await api(`/notifications/${row.id}/read`, { method: 'POST', body: {} });
                        if (!alive) return;
                        toast('Notificación marcada como leída.', { tone: 'success' }); await load();
                    } catch (error) {
                        if (alive) mount(feedback, errorState({ error, onRetry: () => mark.click() }));
                    } finally { mark.disabled = false; }
                });
                return el('li', { class: 'panel notification-item' }, [
                    el('p', { class: 'notification-state', text: row.read_at ? 'Leída' : 'No leída' }),
                    el('p', { text: row.payload?.message || 'Tienes una notificación.' }),
                    el('p', { text: formatDateTime(row.created_at, ctx.session.user.timezone) }),
                    row.read_at ? el('p', { text: `Leída el ${formatDateTime(row.read_at, ctx.session.user.timezone)}` }) : mark,
                    href ? el('a', { class: 'btn btn-secondary', href }, row.entity_type === 'meeting' ? 'Abrir solicitud de reunión' : 'Abrir elemento relacionado') : null,
                ]);
            });
            mount(list, [items.length ? el('ul', { class: 'notification-list stack' }, items) : emptyState({ title: 'Sin notificaciones', message: 'No hay avisos para este filtro.' }),
                pagination({ total: meta.total, limit: 25, offset, label: 'notificaciones', onChange: (next) => { offset = next; void load(); } })]);
        } catch (error) { if (alive && ticket === sequence) mount(list, errorState({ error, onRetry: () => { void load(); } })); }
    };
    unread.control.addEventListener('change', () => { offset = 0; void load(); });
    root.replaceChildren(pageHeader({ title: 'Mis notificaciones', lead: 'Avisos privados de tu cuenta. Se consulta al abrir y cada minuto mientras esta bandeja y la pestaña estén visibles.' }), unread, count, button('Actualizar', () => { void load(); }), feedback, list);
    const stop = visiblePolling(() => list.contains(document.activeElement) ? Promise.resolve() : load(), { authenticated: () => Boolean(ctx.session) });
    ctx.onCleanup(() => { alive = false; ++sequence; stop(); });
}
