/** Administración: permisos de presentación, listas completas y fechas sin dinero numérico. */
import { el } from './api.js';
import { notice } from './ui.js';
import { cap, completeCollection, roleCode } from './project-ui.js';

export function adminAllowed(root, ctx, capability) {
    const allowed = roleCode(ctx.session.user) === 'ADMINISTRADOR' && cap(ctx, capability);
    if (!allowed) root.replaceChildren(notice({ title: 'Acceso restringido', message: 'Esta pantalla está disponible solo para administración.' }));
    return allowed;
}
export const positiveId = (id) => /^[1-9]\d*$/.test(String(id));
export function documentLink(type, id, label) {
    const route = { PROJECT: 'proyectos', SALE: 'ventas', project: 'proyectos', sale: 'ventas', projects: 'proyectos', sales: 'ventas', reservation: 'reservas', reservations: 'reservas' }[type];
    return route && positiveId(id) ? el('a', { href: `#/${route}/${id}` }, label || `Documento ${id}`) : (label || '—');
}
export function monthRange(timezone = 'UTC') {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date()).map((p) => [p.type, p.value]));
    const last = new Date(Date.UTC(Number(parts.year), Number(parts.month), 0)).getUTCDate();
    return { from: `${parts.year}-${parts.month}-01`, to: `${parts.year}-${parts.month}-${last}`, today: `${parts.year}-${parts.month}-${parts.day}` };
}
export async function adminDirectory() {
    return completeCollection('/users/directory');
}
export const queryString = (values) => new URLSearchParams(Object.entries(values).filter(([, value]) => value !== '' && value != null)).toString();
