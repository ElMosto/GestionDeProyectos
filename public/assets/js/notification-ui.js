/** §13: internal allowlist, never use payload.action as an arbitrary URL. */
import { positiveId } from './meeting-ui.js';

export function notificationLink(row) {
    if (!positiveId(row.entity_id)) return null;
    const pairs = {
        meeting: ['MEETING_INVITED', 'MEETING_CHANGED', 'MEETING_CANCELLED', 'MEETING_RESPONDED'],
        task: ['TASK_ASSIGNED', 'TASK_REASSIGNED', 'TASK_CHANGED', 'TASK_CANCELLED'],
        reservation: ['RESERVATION_CREATED', 'RESERVATION_CHANGED', 'RESERVATION_CANCELLED'],
    };
    if (!Object.hasOwn(pairs, row.entity_type) || !pairs[row.entity_type].includes(row.type)) return null;
    const routes = { meeting: `#/reuniones/${row.entity_id}`, task: '#/mis-tareas', reservation: `#/reservas/${row.entity_id}` };
    return routes[row.entity_type] ?? null;
}

/** Poll only the open inbox, never a global background service. Cleanup is owned by router. */
export function visiblePolling(load, { document: doc = document, authenticated = () => true, delay = 60000, setTimer = setTimeout, clearTimer = clearTimeout } = {}) {
    let alive = true; let timer = null; let busy = false;
    const clear = () => { if (timer !== null) clearTimer(timer); timer = null; };
    const run = async () => {
        clear();
        if (!alive || busy || doc.visibilityState !== 'visible' || !authenticated()) return;
        busy = true;
        try { await load(); }
        finally {
            busy = false;
            if (alive && doc.visibilityState === 'visible' && authenticated()) timer = setTimer(() => { void run(); }, delay);
        }
    };
    const changed = () => { clear(); if (doc.visibilityState === 'visible') void run(); };
    doc.addEventListener('visibilitychange', changed);
    void run();
    return () => { alive = false; clear(); doc.removeEventListener('visibilitychange', changed); };
}
