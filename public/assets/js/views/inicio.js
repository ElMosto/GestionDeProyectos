/**
 * Inicio (`#/inicio`): saludo y accesos a los módulos disponibles en las fases 1–2.
 *
 * No inventa datos de proyectos, ventas ni agenda: solo muestra lo que ya existe y
 * anuncia lo que llegará en fases posteriores (§17, §20).
 */

import { el } from '../api.js';
import { pageHeader, panel, statusBadge } from '../ui.js';
import { roleLabel } from '../data.js';

/**
 * @param {HTMLElement} container
 * @param {{session: {user: any, capabilities: Record<string, boolean>}, navigate: (path: string) => void}} ctx
 */
export function renderInicio(container, ctx) {
    const { user, capabilities } = ctx.session;
    const firstName = typeof user.name === 'string' && user.name.trim() !== ''
        ? user.name.trim().split(/\s+/)[0]
        : '';

    const cards = [
        {
            title: 'Proyectos',
            description: 'Proyectos propios o compartidos, integrantes, etapas y avance operativo.',
            path: '/proyectos',
            visible: ['projects.viewAny', 'projects.viewOwn', 'projects.viewMember'].some((key) => capabilities[key] === true),
        },
        {
            title: 'Mis tareas',
            description: 'Tareas asignadas, avance y comentarios. Incluye las tareas independientes.',
            path: '/mis-tareas',
            visible: capabilities['tasks.updateAssigned'] === true,
        },
        {
            title: 'Agenda',
            description: 'Tus tareas programadas por rango de fechas. Reuniones y reservas llegarán después.',
            path: '/agenda',
            visible: capabilities['agenda.viewOwn'] === true,
        },
        {
            title: 'Clientes',
            description: 'Directorio de clientes normales y bandas. Altas y datos de contacto.',
            path: '/clientes',
            visible: capabilities['clients.view'] === true,
        },
        {
            title: 'Usuarios',
            description: 'Altas, roles, estado de las cuentas y restablecimiento de acceso.',
            path: '/usuarios',
            visible: capabilities['users.manage'] === true,
        },
        {
            title: 'Mi usuario',
            description: 'Tu perfil, zona horaria, preferencias, contraseña y avatar.',
            path: '/mi-usuario',
            visible: true,
        },
    ].filter((card) => card.visible);

    /** @param {{title: string, description: string, path: string}} card */
    const moduleCard = (card) => el('li', { class: 'card' }, [
        el('div', { class: 'card-body' }, [
            el('h3', { class: 'card-title', text: card.title }),
            el('p', { class: 'card-text', text: card.description }),
        ]),
        el('div', { class: 'card-actions' }, el('a', {
            class: 'btn btn-secondary',
            href: `#${card.path}`,
        }, `Abrir ${card.title.toLowerCase()}`)),
    ]);

    const content = el('div', { class: 'stack' }, [
        pageHeader({
            title: firstName ? `Hola, ${firstName}` : 'Hola',
            lead: 'Este es el punto de partida del sistema de gestión del estudio.',
        }),
        el('section', { class: 'panel', 'aria-labelledby': 'session-summary-title' }, [
            el('div', { class: 'panel-header' }, [
                el('h2', { class: 'panel-title', id: 'session-summary-title', text: 'Tu sesión' }),
            ]),
            el('div', { class: 'panel-body' }, el('dl', { class: 'summary-list' }, [
                el('div', { class: 'summary-item' }, [
                    el('dt', { text: 'Nombre' }),
                    el('dd', { text: user.name }),
                ]),
                el('div', { class: 'summary-item' }, [
                    el('dt', { text: 'Correo' }),
                    el('dd', { text: user.email }),
                ]),
                el('div', { class: 'summary-item' }, [
                    el('dt', { text: 'Rol' }),
                    el('dd', { text: roleLabel(user.role ? user.role.code : '') }),
                ]),
                el('div', { class: 'summary-item' }, [
                    el('dt', { text: 'Estado de la cuenta' }),
                    el('dd', {}, statusBadge(user.active)),
                ]),
            ])),
        ]),
        el('section', { class: 'panel', 'aria-labelledby': 'modules-title' }, [
            el('div', { class: 'panel-header' }, [
                el('h2', { class: 'panel-title', id: 'modules-title', text: 'Módulos disponibles' }),
                el('p', { class: 'panel-description', text: 'Solo aparecen los módulos que tu rol puede abrir.' }),
            ]),
            el('div', { class: 'panel-body' }, [
                cards.length > 0
                    ? el('ul', { class: 'card-grid' }, cards.map(moduleCard))
                    : el('p', { class: 'panel-text', text: 'Tu rol todavía no tiene módulos asignados.' }),
            ]),
        ]),
        panel({
            title: 'Próximas fases',
            description: 'Ventas, reservas, reuniones y finanzas globales llegan en las próximas fases.',
            children: el('p', { class: 'panel-text', text: 'Cuando estén disponibles aparecerán en el menú. Este inicio no muestra datos de ejemplo ni cifras calculadas en el navegador.' }),
        }),
    ]);

    container.replaceChildren(content);
}
