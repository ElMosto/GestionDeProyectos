/**
 * Inicio (`#/inicio`): saludo y accesos a los módulos disponibles en las fases 1–5.
 *
 * No inventa datos de proyectos, ventas ni agenda: solo muestra lo que ya existe y
 * anuncia lo que llegará en fases posteriores (§17, §20).
 */

import { el } from '../api.js';
import { formatDateTime, pageHeader, panel, statusBadge } from '../ui.js';
import { roleLabel, workLabel, workOptions } from '../data.js';
import { dateText, selectField } from '../project-ui.js';
import { monthRange, positiveId } from '../admin-ui.js';
import { reportList } from '../finance-ui.js';

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
        ...[
            ['Finanzas', 'Indicadores y trazabilidad de caja, pendientes y valor comercial.', '/finanzas', 'finance.dashboard'],
            ['Gastos', 'Registro de gastos internos y estados de pago.', '/gastos', 'expenses.manage'],
            ['Auditoría', 'Metadatos de operaciones, sin contenido privado.', '/auditoria', 'audit.view'],
            ['Configuración', 'Nombre del estudio y zona horaria.', '/configuracion', 'settings.manage'],
        ].map(([title, description, path, capability]) => ({ title, description, path, visible: capabilities[capability] === true })),
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
            description: 'Tus tareas y reservas a cargo por rango de fechas.',
            path: '/agenda',
            visible: capabilities['agenda.viewOwn'] === true,
        },
        {
            title: 'Mis reuniones',
            description: 'Solicitudes que organizas e invitaciones individuales. Solo las aceptadas aparecen en tu agenda.',
            path: '/reuniones',
            visible: capabilities['meetings.create'] === true || capabilities['meetings.respond'] === true,
        },
        {
            title: 'Notificaciones',
            description: 'Avisos propios, leídos y no leídos, sin datos privados de terceros.',
            path: '/notificaciones',
            visible: capabilities['notifications.viewOwn'] === true,
        },
        {
            title: 'Clientes',
            description: 'Directorio de clientes normales y bandas. Altas y datos de contacto.',
            path: '/clientes',
            visible: capabilities['clients.view'] === true,
        },
        {
            title: 'Ventas',
            description: 'Ventas ordinarias, partidas con extras, tickets, abonos y saldos.',
            path: '/ventas',
            visible: capabilities['sales.viewOwn'] === true,
        },
        {
            title: 'Reservas',
            description: 'Reservas de bandas, reprogramación y venta vinculada para cobros y tickets.',
            path: '/reservas',
            visible: capabilities['reservations.viewOwn'] === true,
        },
        {
            title: 'Espacios',
            description: 'Ocupación mínima por rango. Administración configura espacios, horarios y mantenimiento.',
            path: '/espacios',
            visible: capabilities['spaces.viewOccupancy'] === true,
        },
        {
            title: 'Productos y extras',
            description: 'Catálogo de productos, servicios y extras permitidos por producto.',
            path: '/productos',
            visible: capabilities['catalog.view'] === true,
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
    ]);

    container.replaceChildren(content);
    const lists = [];
    ctx.onCleanup?.(() => lists.forEach((list) => list.dispose()));
    const range = monthRange(user.timezone ?? 'UTC');
    if (capabilities['tasks.updateAssigned'] === true) {
        const results = el('div');
        const status = selectField('home_task_status', 'Estado de mis tareas', workOptions(['PENDIENTE', 'EN_CURSO', 'BLOQUEADA']), 'PENDIENTE');
        const filters = { assignee_id: String(user.id), status: 'PENDIENTE' };
        const list = reportList(results, '/tasks', filters, 'Mis tareas por estado (lista paginada)', [
            { label: 'Tarea', render: (t) => t.title },
            { label: 'Fecha prevista', render: (t) => dateText(t.end_date) },
            { label: 'Situación de fecha', render: (t) => !t.end_date ? 'Sin programar' : t.end_date.slice(0, 10) < range.today ? 'Vencida' : 'Próxima' },
            { label: 'Estado', render: (t) => workLabel(t.status) },
        ]);
        status.control.addEventListener('change', () => { filters.status = status.control.value; void list.reset(); });
        content.append(panel({ title: 'Mis tareas próximas y vencidas', description: 'Se consulta al servidor por tu asignación y estado. Lista paginada; no representa un contador global de tareas vencidas.', children: el('div', { class: 'stack' }, [status, results, el('a', { class: 'btn btn-secondary', href: '#/mis-tareas' }, 'Ver todas mis tareas')]) }));
        lists.push(list); void list.load();
    }
    if (capabilities['meetings.respond'] === true) {
        const results = el('div');
        const list = reportList(results, '/meetings', {}, 'Solicitudes e invitaciones propias (paginadas)', [{ label: 'Invitaciones pendientes en esta página', render: (m) => {
            if (m.invitation?.response !== 'PENDIENTE') return 'Sin invitación pendiente en esta solicitud';
            return el('div', {}, [positiveId(m.id) ? el('a', { class: 'btn btn-secondary', href: `#/reuniones/${m.id}` }, m.title || 'Invitación pendiente') : 'Invitación pendiente', el('p', { text: formatDateTime(m.start_at, user.timezone) })]);
        } }]);
        content.append(panel({ title: 'Mis invitaciones', description: 'La API pagina todas tus solicitudes; aquí se destaca tu respuesta pendiente en cada página, no las de otras personas.', children: results }));
        lists.push(list); void list.load();
    }
    if (capabilities['agenda.viewOwn'] === true) {
        const results = el('div');
        const list = reportList(results, '/agenda/reservations', { from: range.today, to: range.to }, 'Reservas a mi cargo hasta fin de mes', [
            { label: 'Reserva', render: (r) => positiveId(r.reservation_id ?? r.id) ? el('a', { href: `#/reservas/${r.reservation_id ?? r.id}` }, r.title || 'Reserva propia') : 'Reserva propia' },
            { label: 'Inicio', render: (r) => formatDateTime(r.start_at, user.timezone) },
            { label: 'Fin', render: (r) => formatDateTime(r.end_at, user.timezone) },
            { label: 'Estado', render: (r) => workLabel(r.status) },
        ]);
        content.append(panel({ title: 'Reservas a mi cargo', description: `Del ${range.today} al ${range.to}. Solo la proyección personal de agenda.`, children: el('div', { class: 'stack' }, [results, el('a', { class: 'btn btn-secondary', href: '#/agenda' }, 'Consultar otras fechas')]) }));
        lists.push(list); void list.load();
    }
}
