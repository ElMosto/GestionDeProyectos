/**
 * Pantalla obligatoria cuando `must_change_password = true` (§6).
 *
 * El backend solo permite `GET /auth/me`, `POST /auth/logout`, `GET /auth/csrf`,
 * `POST /me/password` y `GET /me/avatar` mientras la bandera esté activa, así que aquí
 * no se ofrece ninguna otra acción.
 */

import { el } from '../api.js';
import { notice } from '../ui.js';
import { passwordForm } from './passwordForm.js';

/**
 * @param {HTMLElement} container
 * @param {{session: {user: any}, setSession: (data: any) => void, navigate: (path: string) => void, logout: () => void}} ctx
 */
export function renderPasswordChange(container, ctx) {
    const titleId = 'password-change-title';

    container.replaceChildren(el('section', { class: 'auth', 'aria-labelledby': titleId }, [
        el('div', { class: 'auth-card auth-card-wide' }, [
            el('h1', { class: 'auth-title', id: titleId, tabindex: '-1', text: 'Cambia tu contraseña' }),
            el('p', { class: 'auth-lead', text: `Hola, ${ctx.session.user.name}. Antes de continuar debes sustituir la contraseña temporal por una propia.` }),
            notice({
                tone: 'warning',
                title: 'Cambio obligatorio',
                message: 'Hasta que cambies la contraseña, el resto del sistema permanece bloqueado. Al guardarla se renovará esta sesión y se revocarán los dispositivos vinculados.',
            }),
            passwordForm(ctx, {
                forced: true,
                onSuccess: () => {
                    ctx.navigate('/inicio');
                },
            }),
            el('div', { class: 'form-actions form-actions-end' }, el('button', {
                type: 'button',
                class: 'btn btn-secondary',
                onClick: () => {
                    void ctx.logout();
                },
            }, 'Cerrar sesión')),
        ]),
    ]));

    const heading = container.querySelector('h1');

    if (heading instanceof HTMLElement) {
        heading.focus();
    }
}
