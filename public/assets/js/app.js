/**
 * Arranque de la aplicación y router por hash.
 *
 * Rutas: `#/login`, `#/inicio`, `#/clientes`, `#/usuarios`, `#/mi-usuario`.
 *
 * Al arrancar se consulta `GET /auth/me`:
 * - 401 → pantalla de acceso.
 * - `must_change_password` → pantalla obligatoria de cambio de contraseña.
 * - sesión válida → vista pedida (o inicio).
 *
 * La sesión vive en memoria: no se guardan credenciales en `localStorage` ni en
 * `sessionStorage`. Los permisos de la UI salen de `capabilities`, que solo ocultan
 * botones; el backend reautoriza cada petición.
 */

import {
    ApiError,
    api,
    clearClientState,
    el,
    setUnauthorizedHandler,
} from './api.js';
import {
    clear,
    errorState,
    loadingState,
    mount,
    toast,
    userAvatar,
} from './ui.js';
import { roleLabel } from './data.js';
import { renderLogin } from './views/login.js';
import { renderPasswordChange } from './views/passwordChange.js';
import { renderInicio } from './views/inicio.js';
import { renderClientes } from './views/clientes.js';
import { renderUsuarios } from './views/usuarios.js';
import { renderMiUsuario } from './views/miUsuario.js';
import { renderProyectos } from './views/proyectos.js';
import { renderProyecto } from './views/proyecto.js';
import { renderTareas } from './views/tareas.js';
import { renderAgenda } from './views/agenda.js';
import { renderProductos } from './views/productos.js';
import { renderVentas } from './views/ventas.js';
import { renderVenta } from './views/venta.js';
import { renderEspacios } from './views/espacios.js';
import { renderReservas } from './views/reservas.js';
import { renderReserva } from './views/reserva.js';
import { renderReuniones } from './views/reuniones.js';
import { renderReunion } from './views/reunion.js';
import { renderNotificaciones } from './views/notificaciones.js';
import { renderFinanzas } from './views/finanzas.js';
import { renderGastos } from './views/gastos.js';
import { renderAuditoria } from './views/auditoria.js';
import { renderConfiguracion } from './views/configuracion.js';

const ROUTES = {
    '/finanzas': { title: 'Finanzas', render: renderFinanzas },
    '/gastos': { title: 'Gastos', render: renderGastos },
    '/auditoria': { title: 'Auditoría', render: renderAuditoria },
    '/configuracion': { title: 'Configuración', render: renderConfiguracion },
    '/inicio': { title: 'Inicio', render: renderInicio },
    '/clientes': { title: 'Clientes', render: renderClientes },
    '/usuarios': { title: 'Usuarios', render: renderUsuarios },
    '/mi-usuario': { title: 'Mi usuario', render: renderMiUsuario },
    '/proyectos': { title: 'Proyectos', render: renderProyectos },
    '/mis-tareas': { title: 'Mis tareas', render: renderTareas },
    '/agenda': { title: 'Agenda', render: renderAgenda },
    '/productos': { title: 'Productos y extras', render: renderProductos },
    '/ventas': { title: 'Ventas', render: renderVentas },
    '/espacios': { title: 'Espacios', render: renderEspacios },
    '/reservas': { title: 'Reservas', render: renderReservas },
    '/reuniones': { title: 'Mis reuniones', render: renderReuniones },
    '/notificaciones': { title: 'Mis notificaciones', render: renderNotificaciones },
};

const appRoot = document.getElementById('app');
const navRoot = document.getElementById('main-nav');
const sessionRoot = document.getElementById('session-bar');

/** @type {{user: any, capabilities: Record<string, boolean>, mustChangePassword: boolean}|null} */
let session = null;
let viewCleanups = [];
let renderSequence = 0;

function cleanupView() {
    const callbacks = viewCleanups;
    viewCleanups = [];
    callbacks.forEach((cleanup) => cleanup());
}

/**
 * @returns {string}
 */
function currentPath() {
    const raw = window.location.hash.replace(/^#/, '');
    const withoutQuery = raw.split('?')[0];

    if (withoutQuery === '') {
        return '';
    }

    return withoutQuery.startsWith('/') ? withoutQuery : `/${withoutQuery}`;
}

/**
 * @param {string} title
 */
function setTitle(title) {
    document.title = `${title} · Gestión del estudio`;
}

/**
 * @param {any} data
 */
function setSession(data) {
    if (!data || typeof data !== 'object' || !data.user) {
        return;
    }

    session = {
        user: data.user,
        capabilities: data.capabilities && typeof data.capabilities === 'object' ? data.capabilities : {},
        mustChangePassword: data.must_change_password === true || data.user.must_change_password === true,
    };

    renderChrome();
}

async function refreshSession() {
    const { data } = await api('/auth/me');
    setSession(data);
}

/**
 * @param {string} path
 * @param {{replace?: boolean}} [options]
 */
function navigate(path, { replace = false } = {}) {
    const target = `#${path}`;

    if (window.location.hash === target) {
        void render();

        return;
    }

    if (replace) {
        window.history.replaceState(null, '', target);
        void render();
    } else {
        window.location.hash = target;
    }
}

async function logout() {
    try {
        await api('/auth/logout', { method: 'POST', notifyUnauthorized: false });
    } catch (error) {
        if (!(error instanceof ApiError) || error.status !== 401) {
            toast('No se pudo cerrar la sesión. Revisa la conexión y vuelve a intentarlo.', { tone: 'error' });
            return;
        }
    }

    session = null;
    clearClientState();
    renderChrome();
    navigate('/login', { replace: true });
    toast('Sesión cerrada.', { tone: 'success' });
}

/**
 * Cabecera y navegación derivadas de `capabilities`.
 */
function renderChrome() {
    if (!navRoot || !sessionRoot) {
        return;
    }

    clear(navRoot);
    clear(sessionRoot);

    if (!session || session.mustChangePassword) {
        // Do not leave private object dialogs visible after session expiry.
        document.querySelectorAll('.dialog-close').forEach((control) => control.click());
        navRoot.hidden = true;
        sessionRoot.hidden = true;

        return;
    }

    const activePath = currentPath();
    const items = [
        { path: '/inicio', label: 'Inicio' },
        { path: '/proyectos', label: 'Proyectos', allowed: ['projects.viewAny', 'projects.viewOwn', 'projects.viewMember'].some((key) => session.capabilities[key] === true) },
        { path: '/mis-tareas', label: 'Mis tareas', capability: 'tasks.updateAssigned' },
        { path: '/agenda', label: 'Agenda', capability: 'agenda.viewOwn' },
        { path: '/reuniones', label: 'Mis reuniones', allowed: ['meetings.create', 'meetings.respond'].some((key) => session.capabilities[key] === true) },
        { path: '/notificaciones', label: 'Notificaciones', capability: 'notifications.viewOwn' },
        { path: '/clientes', label: 'Clientes', capability: 'clients.view' },
        { path: '/ventas', label: 'Ventas', capability: 'sales.viewOwn' },
        { path: '/reservas', label: 'Reservas', capability: 'reservations.viewOwn' },
        { path: '/espacios', label: 'Espacios', capability: 'spaces.viewOccupancy' },
        { path: '/productos', label: 'Productos / Extras', capability: 'catalog.view' },
        { path: '/usuarios', label: 'Usuarios', capability: 'users.manage' },
        { path: '/finanzas', label: 'Finanzas', capability: 'finance.dashboard' },
        { path: '/gastos', label: 'Gastos', capability: 'expenses.manage' },
        { path: '/auditoria', label: 'Auditoría', capability: 'audit.view' },
        { path: '/configuracion', label: 'Configuración', capability: 'settings.manage' },
        { path: '/mi-usuario', label: 'Mi usuario' },
    ].filter((item) => item.allowed !== false && (!item.capability || session.capabilities[item.capability] === true));

    navRoot.append(el('ul', { class: 'nav-list' }, items.map((item) => el(
        'li',
        { class: 'nav-item' },
        el('a', {
            class: 'nav-link',
            href: `#${item.path}`,
            'aria-current': activePath === item.path || (item.path === '/proyectos' && /^\/proyectos\/[1-9]\d*$/.test(activePath)) || (item.path === '/ventas' && /^\/ventas\/[1-9]\d*$/.test(activePath)) || (item.path === '/reservas' && /^\/reservas\/[1-9]\d*$/.test(activePath)) || (item.path === '/reuniones' && /^\/reuniones\/[1-9]\d*$/.test(activePath)) ? 'page' : null,
        }, item.label),
    ))));

    const user = session.user;
    const roleCode = user.role && typeof user.role.code === 'string' ? user.role.code : '';

    sessionRoot.append(
        el('div', { class: 'session-identity' }, [
            userAvatar(user, { small: true, alt: '' }),
            el('span', { class: 'session-text' }, [
                el('span', { class: 'session-name', text: user.name }),
                el('span', { class: 'session-role', text: roleLabel(roleCode) }),
            ]),
        ]),
        el('button', {
            type: 'button',
            class: 'btn btn-secondary btn-sm',
            onClick: () => { void logout(); },
        }, 'Cerrar sesión'),
    );

    navRoot.hidden = false;
    sessionRoot.hidden = false;
}

/**
 * Enfoca el `<h1>` de la vista para que los lectores de pantalla anuncien el cambio.
 */
function focusHeading() {
    const heading = appRoot ? appRoot.querySelector('h1') : null;

    if (heading instanceof HTMLElement) {
        heading.focus({ preventScroll: true });
    }
}

/**
 * @param {unknown} error
 * @returns {any}
 */
function asApiError(error) {
    if (error instanceof ApiError) {
        return error;
    }

    return new ApiError(0, {
        error: {
            code: 'NETWORK_ERROR',
            message: 'No se pudo conectar con el servidor. Revisa tu conexión e inténtalo de nuevo.',
        },
    });
}

async function render() {
    if (!appRoot) {
        return;
    }

    cleanupView();
    const renderTicket = ++renderSequence;
    renderChrome();

    if (!session) {
        if (window.location.hash !== '#/login') {
            window.history.replaceState(null, '', '#/login');
        }

        setTitle('Iniciar sesión');
        renderLogin(appRoot, { onAuthenticated });

        return;
    }

    if (session.mustChangePassword) {
        setTitle('Cambia tu contraseña');
        renderPasswordChange(appRoot, ctx);

        return;
    }

    const path = currentPath();

    if (path === '' || path === '/' || path === '/login') {
        navigate('/inicio', { replace: true });

        return;
    }

    // IDs remain strings: BIGINT identifiers must never pass through Number.
    const projectMatch = /^\/proyectos\/([1-9]\d*)$/.exec(path);
    const saleMatch = /^\/ventas\/([1-9]\d*)$/.exec(path);
    const reservationMatch = /^\/reservas\/([1-9]\d*)$/.exec(path);
    const meetingMatch = /^\/reuniones\/([1-9]\d*)$/.exec(path);
    const route = projectMatch ? { title: 'Proyecto', render: (root, context) => renderProyecto(root, context, projectMatch[1]) }
        : saleMatch ? { title: 'Venta', render: (root, context) => renderVenta(root, context, saleMatch[1]) }
            : reservationMatch ? { title: 'Reserva', render: (root, context) => renderReserva(root, context, reservationMatch[1]) }
                : meetingMatch ? { title: 'Reunión', render: (root, context) => renderReunion(root, context, meetingMatch[1]) } : ROUTES[path];

    if (!route) {
        setTitle('Página no encontrada');
        mount(appRoot, el('section', { class: 'stack' }, [
            el('h1', { class: 'page-title', tabindex: '-1', text: 'Página no encontrada' }),
            el('p', { class: 'page-lead', text: 'La dirección que abriste no existe en esta aplicación.' }),
            el('p', {}, el('a', { class: 'btn btn-primary', href: '#/inicio' }, 'Volver al inicio')),
        ]));
        focusHeading();

        return;
    }

    try {
        // Cada navegación posee su contenedor. Una carga anterior que termine
        // tarde solo actualiza su nodo ya desconectado, nunca la vista nueva.
        const viewRoot = el('div');
        mount(appRoot, viewRoot);
        const viewContext = Object.create(ctx);
        viewContext.onCleanup = (cleanup) => {
            if (renderTicket === renderSequence) viewCleanups.push(cleanup);
            else cleanup();
        };
        await route.render(viewRoot, viewContext);
        if (renderTicket !== renderSequence) return;
        setTitle(route.title);
        focusHeading();
    } catch (error) {
        if (renderTicket !== renderSequence) return;
        cleanupView();
        setTitle('Error');
        mount(appRoot, errorState({
            error: asApiError(error),
            title: 'No se pudo mostrar la pantalla',
            onRetry: () => { void render(); },
        }));
    }
}

/**
 * @param {any} data
 */
function onAuthenticated(data) {
    setSession(data);

    if (!session) {
        return;
    }

    if (session.mustChangePassword) {
        void render();

        return;
    }

    navigate('/inicio', { replace: true });
}

const ctx = {
    get session() {
        return session;
    },
    setSession,
    refreshSession,
    navigate,
    logout,
    render,
};

async function boot() {
    if (!appRoot) {
        return;
    }

    mount(appRoot, loadingState('Cargando la aplicación…'));

    try {
        const { data } = await api('/auth/me', { notifyUnauthorized: false });
        setSession(data);
    } catch (error) {
        if (!(error instanceof ApiError) || error.status !== 401) {
            mount(appRoot, errorState({
                error: asApiError(error),
                title: 'No se pudo iniciar la aplicación',
                onRetry: () => { void boot(); },
            }));

            return;
        }

        session = null;
    }

    await render();
}

setUnauthorizedHandler(() => {
    if (!session) {
        return;
    }

    session = null;
    clearClientState();
    renderChrome();
    navigate('/login', { replace: true });
    toast('Tu sesión terminó. Vuelve a iniciar sesión.', { tone: 'error' });
});

window.addEventListener('hashchange', () => {
    void render();
});

document.querySelector('.skip-link')?.addEventListener('click', (event) => {
    event.preventDefault();
    appRoot?.focus();
});

void boot();
