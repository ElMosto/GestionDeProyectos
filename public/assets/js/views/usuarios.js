/**
 * Administración de cuentas (`#/usuarios`). Solo `users.manage` (§5).
 *
 * La interfaz oculta lo que el rol no puede hacer, pero el backend reautoriza cada
 * petición: aquí no se decide ningún permiso. Las ediciones envían `version` e
 * `If-Match`; un `409 VERSION_CONFLICT` se explica y ofrece recargar.
 */

import { api, el } from '../api.js';
import {
    applyFormError,
    clearFieldErrors,
    confirmAction,
    dataTable,
    errorState,
    field,
    formAlert,
    formValues,
    loadingState,
    mount,
    openDialog,
    pageHeader,
    pagination,
    setFieldErrors,
    statusBadge,
    toast,
    formatDateTime,
} from '../ui.js';
import {
    DEFAULT_TIMEZONE,
    PAGE_SIZE,
    ROLES,
    roleLabel,
    timezoneOptions,
} from '../data.js';

/**
 * @param {HTMLElement} container
 * @param {any} ctx
 */
export function renderUsuarios(container, ctx) {
    if (ctx.session.capabilities['users.manage'] !== true) {
        container.replaceChildren(errorState({
            title: 'No tienes acceso a la administración de usuarios',
            error: {
                message: 'Esta sección requiere el permiso de administración de cuentas. Si crees que es un error, consulta con un administrador.',
                code: 'FORBIDDEN',
            },
        }));

        return;
    }

    const state = {
        q: '',
        role: '',
        active: '',
        offset: 0,
        rows: [],
        meta: { total: 0, limit: PAGE_SIZE, offset: 0 },
        loading: false,
    };

    const listRegion = el('div', { class: 'list-region' });

    const qControl = el('input', {
        id: 'filter-q',
        name: 'q',
        type: 'search',
        value: state.q,
        maxlength: 120,
        autocomplete: 'off',
    });
    const roleControl = el('select', { id: 'filter-role', name: 'role' }, [
        el('option', { value: '', selected: true }, 'Todos los roles'),
        ...ROLES.map((role) => el('option', { value: role.code }, role.label)),
    ]);
    const activeControl = el('select', { id: 'filter-active', name: 'active' }, [
        el('option', { value: '', selected: true }, 'Todas las cuentas'),
        el('option', { value: '1' }, 'Solo activas'),
        el('option', { value: '0' }, 'Solo desactivadas'),
    ]);

    const filters = el('form', { class: 'filters', role: 'search', novalidate: true }, [
        el('div', { class: 'filter-grid' }, [
            el('div', { class: 'filter-field' }, [
                el('label', { for: 'filter-q', text: 'Buscar por nombre o correo' }),
                qControl,
            ]),
            el('div', { class: 'filter-field' }, [
                el('label', { for: 'filter-role', text: 'Rol' }),
                roleControl,
            ]),
            el('div', { class: 'filter-field' }, [
                el('label', { for: 'filter-active', text: 'Estado' }),
                activeControl,
            ]),
        ]),
        el('div', { class: 'filter-actions' }, [
            el('button', { type: 'submit', class: 'btn btn-primary' }, 'Aplicar filtros'),
            el('button', {
                type: 'button',
                class: 'btn btn-secondary',
                onClick: () => {
                    qControl.value = '';
                    roleControl.value = '';
                    activeControl.value = '';
                    state.q = '';
                    state.role = '';
                    state.active = '';
                    state.offset = 0;
                    void load();
                },
            }, 'Limpiar'),
        ]),
    ]);

    filters.addEventListener('submit', (event) => {
        event.preventDefault();
        state.q = qControl.value.trim();
        state.role = roleControl.value;
        state.active = activeControl.value;
        state.offset = 0;
        void load();
    });

    const newUserButton = el('button', {
        type: 'button',
        class: 'btn btn-primary',
        onClick: () => openCreateDialog(ctx, () => {
            state.offset = 0;
            void load();
        }),
    }, 'Nuevo usuario');

    const header = pageHeader({
        title: 'Usuarios',
        lead: 'Altas, roles, estado de las cuentas y restablecimiento de acceso. Solo el administrador puede entrar aquí.',
        actions: newUserButton,
    });

    container.replaceChildren(el('div', { class: 'stack' }, [
        header,
        el('section', { class: 'panel' }, [
            el('div', { class: 'panel-body' }, filters),
        ]),
        listRegion,
    ]));

    const timezone = ctx.session.user.timezone;

    /**
     * @param {number} offset
     */
    function query(offset) {
        const params = new URLSearchParams({
            limit: String(PAGE_SIZE),
            offset: String(offset),
            sort: 'name',
            direction: 'asc',
        });

        if (state.q !== '') {
            params.set('q', state.q);
        }

        if (state.role !== '') {
            params.set('role', state.role);
        }

        if (state.active !== '') {
            params.set('active', state.active);
        }

        return `/users?${params.toString()}`;
    }

    async function load() {
        if (state.loading) {
            return;
        }

        state.loading = true;
        mount(listRegion, loadingState('Cargando usuarios…'));

        try {
            const payload = await api(query(state.offset));
            state.rows = Array.isArray(payload.data) ? payload.data : [];
            state.meta = {
                total: Number(payload.meta.total ?? 0),
                limit: Number(payload.meta.limit ?? PAGE_SIZE),
                offset: Number(payload.meta.offset ?? state.offset),
            };
            state.offset = state.meta.offset;
            renderList();
        } catch (error) {
            mount(listRegion, errorState({
                error,
                title: 'No se pudo cargar la lista de usuarios',
                onRetry: () => { void load(); },
            }));
        } finally {
            state.loading = false;
        }
    }

    function renderList() {
        const hasFilters = state.q !== '' || state.role !== '' || state.active !== '';

        const table = dataTable({
            caption: 'Cuentas de usuario del estudio',
            emptyTitle: hasFilters ? 'Ningún usuario coincide con los filtros' : 'Todavía no hay usuarios',
            emptyMessage: hasFilters
                ? 'Prueba con otro texto o quita algún filtro.'
                : 'Crea la primera cuenta con el botón «Nuevo usuario».',
            columns: [
                {
                    label: 'Nombre',
                    render: (user) => el('div', { class: 'cell-stack' }, [
                        el('span', { class: 'cell-strong' }, [
                            user.name,
                            user.id === ctx.session.user.id
                                ? el('span', { class: 'tag' }, 'Tú')
                                : null,
                        ]),
                        el('span', { class: 'cell-sub', text: user.email }),
                    ]),
                },
                {
                    label: 'Rol',
                    render: (user) => roleLabel(user.role ? user.role.code : ''),
                },
                {
                    label: 'Estado',
                    render: (user) => statusBadge(user.active),
                },
                {
                    label: 'Último acceso',
                    render: (user) => formatDateTime(user.last_login_at, timezone),
                },
                {
                    label: 'Acciones',
                    className: 'cell-actions',
                    render: (user) => el('div', { class: 'row-actions' }, [
                        el('button', {
                            type: 'button',
                            class: 'btn btn-secondary btn-sm',
                            'aria-label': `Editar a ${user.name}`,
                            onClick: () => { void startEdit(user); },
                        }, 'Editar'),
                        user.id === ctx.session.user.id
                            ? null
                            : el('button', {
                                type: 'button',
                                class: `btn ${user.active ? 'btn-danger' : 'btn-secondary'} btn-sm`,
                                'aria-label': `${user.active ? 'Desactivar' : 'Reactivar'} a ${user.name}`,
                                onClick: () => {
                                    if (user.active) {
                                        void deactivate(user);
                                    } else {
                                        void reactivate(user);
                                    }
                                },
                            }, user.active ? 'Desactivar' : 'Reactivar'),
                        el('button', {
                            type: 'button',
                            class: 'btn btn-secondary btn-sm',
                            'aria-label': `Restablecer acceso de ${user.name}`,
                            onClick: () => { void resetPassword(user); },
                        }, 'Restablecer'),
                    ]),
                },
            ],
            rows: state.rows,
        });

        const pager = pagination({
            total: state.meta.total,
            limit: state.meta.limit,
            offset: state.meta.offset,
            label: 'usuarios',
            onChange: (offset) => {
                state.offset = offset;
                void load();
            },
        });

        listRegion.replaceChildren(el('section', { class: 'panel' }, [
            el('div', { class: 'panel-header' }, [
                el('h2', { class: 'panel-title', text: 'Directorio de cuentas' }),
                el('p', { class: 'panel-description', text: 'El correo no se edita desde aquí; el alta y el restablecimiento controlan el acceso.' }),
            ]),
            el('div', { class: 'panel-body' }, [table, pager]),
        ]));
    }

    /**
     * @param {any} user
     */
    async function startEdit(user) {
        try {
            const { data } = await api(`/users/${encodeURIComponent(user.id)}`);
            openEditDialog(ctx, data, () => { void load(); });
        } catch (error) {
            reportActionError(error, 'No se pudo abrir la ficha del usuario');
        }
    }

    /**
     * @param {any} user
     */
    async function deactivate(user) {
        const confirmation = await confirmAction({
            title: `Desactivar a ${user.name}`,
            message: 'La cuenta pierde el acceso de inmediato: se revocan sus dispositivos y sus sesiones abiertas dejan de valer.',
            summary: [
                `Rol actual: ${roleLabel(user.role ? user.role.code : '')}`,
                'No se borra historial: la cuenta queda inactiva y puede reactivarse.',
                'El último administrador activo no se puede desactivar.',
            ],
            requireReason: false,
            confirmLabel: 'Desactivar cuenta',
        });

        if (confirmation === null) {
            return;
        }

        try {
            await api(`/users/${encodeURIComponent(user.id)}/deactivate`, { method: 'POST' });
            toast('Cuenta desactivada.', { tone: 'success' });
            void load();
        } catch (error) {
            reportActionError(error, 'No se pudo desactivar la cuenta');
        }
    }

    /**
     * @param {any} user
     */
    async function reactivate(user) {
        const confirmation = await confirmAction({
            title: `Reactivar a ${user.name}`,
            message: 'La cuenta volverá a poder iniciar sesión con su contraseña actual.',
            confirmLabel: 'Reactivar cuenta',
            destructive: false,
        });

        if (confirmation === null) {
            return;
        }

        try {
            await api(`/users/${encodeURIComponent(user.id)}/reactivate`, { method: 'POST' });
            toast('Cuenta reactivada.', { tone: 'success' });
            void load();
        } catch (error) {
            reportActionError(error, 'No se pudo reactivar la cuenta');
        }
    }

    /**
     * @param {any} user
     */
    async function resetPassword(user) {
        const confirmation = await confirmAction({
            title: `Restablecer acceso de ${user.name}`,
            message: 'Se emitirá una contraseña temporal de un solo uso. La persona deberá cambiarla al entrar.',
            summary: [
                'Se revocan sus dispositivos móviles y se cierran sus sesiones.',
                'La contraseña temporal se muestra una sola vez y no se guarda.',
            ],
            confirmLabel: 'Generar contraseña temporal',
            destructive: false,
        });

        if (confirmation === null) {
            return;
        }

        try {
            const { data } = await api(`/users/${encodeURIComponent(user.id)}/reset-password`, { method: 'POST' });
            showTemporaryPassword(data, ctx);
            void load();
        } catch (error) {
            reportActionError(error, 'No se pudo restablecer el acceso');
        }
    }

    void load();
}

/**
 * @param {any} error
 * @param {string} fallback
 */
function reportActionError(error, fallback) {
    const message = error && typeof error.message === 'string' && error.message !== ''
        ? error.message
        : 'Ocurrió un error inesperado.';
    const code = error && error.code && error.code !== 'UNKNOWN_ERROR' ? ` Código: ${error.code}.` : '';

    toast(`${fallback}. ${message}${code}`, { tone: 'error' });
}

/**
 * @param {any} ctx
 * @param {(user: any) => void} onCreated
 */
function openCreateDialog(ctx, onCreated) {
    const name = field({
        label: 'Nombre completo',
        name: 'name',
        required: true,
        maxLength: 120,
        autocomplete: 'off',
    });
    const email = field({
        label: 'Correo electrónico',
        name: 'email',
        type: 'email',
        required: true,
        maxLength: 180,
        autocomplete: 'off',
        inputMode: 'email',
    });
    const password = field({
        label: 'Contraseña inicial',
        name: 'password',
        type: 'password',
        required: true,
        autocomplete: 'new-password',
        help: 'Al menos 12 caracteres. La cuenta nacerá obligada a cambiarla al primer acceso.',
    });
    const role = field({
        label: 'Rol',
        name: 'role',
        type: 'select',
        required: true,
        options: [
            { value: '', label: 'Selecciona un rol' },
            ...ROLES.map((item) => ({ value: item.code, label: item.label })),
        ],
    });
    const phone = field({
        label: 'Teléfono',
        name: 'phone',
        type: 'tel',
        maxLength: 32,
        help: 'Opcional.',
    });
    const jobTitle = field({
        label: 'Cargo',
        name: 'job_title',
        maxLength: 120,
        help: 'Opcional.',
    });
    const timezone = field({
        label: 'Zona horaria',
        name: 'timezone',
        type: 'select',
        value: DEFAULT_TIMEZONE,
        options: timezoneOptions(DEFAULT_TIMEZONE),
    });

    const alert = formAlert();
    const form = el('form', { class: 'form-grid', novalidate: true }, [
        name,
        email,
        password,
        role,
        phone,
        jobTitle,
        timezone,
        alert,
    ]);

    const cancelButton = el('button', { type: 'button', class: 'btn btn-secondary' }, 'Cancelar');
    const submitButton = el('button', { type: 'submit', class: 'btn btn-primary', form: 'create-user-form' }, 'Crear usuario');
    form.id = 'create-user-form';

    const dialog = openDialog({
        title: 'Nuevo usuario',
        description: 'La cuenta se crea activa y con cambio de contraseña obligatorio en el primer acceso.',
        content: form,
        actions: [cancelButton, submitButton],
        initialFocus: '.dialog-body input',
    });

    cancelButton.addEventListener('click', () => dialog.close());

    let busy = false;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (busy) {
            return;
        }

        clearFieldErrors(form);
        alert.hidden = true;
        const values = formValues(form);
        const localErrors = {};

        if (!values.name || values.name.trim() === '') {
            localErrors.name = 'El nombre es obligatorio.';
        }

        if (!values.email || values.email.trim() === '') {
            localErrors.email = 'El correo es obligatorio.';
        }

        if (!values.password) {
            localErrors.password = 'Escribe una contraseña inicial.';
        }

        if (!values.role) {
            localErrors.role = 'Selecciona un rol.';
        }

        if (Object.keys(localErrors).length > 0) {
            setFieldErrors(form, localErrors);
            const firstInvalid = form.querySelector('[aria-invalid="true"]');

            if (firstInvalid instanceof HTMLElement) {
                firstInvalid.focus();
            }

            return;
        }

        busy = true;
        submitButton.disabled = true;
        submitButton.textContent = 'Creando…';
        form.setAttribute('aria-busy', 'true');

        try {
            const { data } = await api('/users', {
                method: 'POST',
                body: {
                    name: values.name.trim(),
                    email: values.email.trim(),
                    password: values.password,
                    role: values.role,
                    phone: values.phone.trim() === '' ? null : values.phone.trim(),
                    job_title: values.job_title.trim() === '' ? null : values.job_title.trim(),
                    timezone: values.timezone,
                },
            });

            dialog.close();
            toast(`Usuario ${data.name} creado.`, { tone: 'success' });
            onCreated(data);
        } catch (error) {
            applyFormError(error, form, alert);
        } finally {
            busy = false;
            submitButton.disabled = false;
            submitButton.textContent = 'Crear usuario';
            form.removeAttribute('aria-busy');
        }
    });
}

/**
 * @param {any} ctx
 * @param {any} user
 * @param {() => void} onSaved
 */
function openEditDialog(ctx, user, onSaved) {
    const isSelf = user.id === ctx.session.user.id;

    const name = field({
        label: 'Nombre completo',
        name: 'name',
        value: user.name,
        required: true,
        maxLength: 120,
    });
    const phone = field({
        label: 'Teléfono',
        name: 'phone',
        type: 'tel',
        value: user.phone ?? '',
        maxLength: 32,
        help: 'Opcional.',
    });
    const jobTitle = field({
        label: 'Cargo',
        name: 'job_title',
        value: user.job_title ?? '',
        maxLength: 120,
        help: 'Opcional.',
    });
    const timezone = field({
        label: 'Zona horaria',
        name: 'timezone',
        type: 'select',
        value: user.timezone ?? DEFAULT_TIMEZONE,
        options: timezoneOptions(user.timezone),
    });
    const role = field({
        label: 'Rol',
        name: 'role',
        type: 'select',
        value: user.role ? user.role.code : '',
        options: ROLES.map((item) => ({ value: item.code, label: item.label })),
        disabled: isSelf,
        help: isSelf ? 'No puedes cambiar tu propio rol.' : 'Un cambio de rol queda auditado.',
    });

    const alert = formAlert();
    const form = el('form', { class: 'form-grid', novalidate: true }, [
        el('div', { class: 'read-only-field' }, [
            el('p', { class: 'field-label', text: 'Correo electrónico' }),
            el('p', { class: 'read-only-value', text: user.email }),
            el('p', { class: 'help', text: 'El correo identifica la cuenta y no se edita desde esta pantalla.' }),
        ]),
        name,
        phone,
        jobTitle,
        timezone,
        role,
        alert,
    ]);
    form.id = 'edit-user-form';

    const cancelButton = el('button', { type: 'button', class: 'btn btn-secondary' }, 'Cancelar');
    const submitButton = el('button', {
        type: 'submit',
        class: 'btn btn-primary',
        form: 'edit-user-form',
    }, 'Guardar cambios');

    const dialog = openDialog({
        title: `Editar a ${user.name}`,
        description: `Versión actual: ${user.version}. Un conflicto te pedirá recargar los datos.`,
        content: form,
        actions: [cancelButton, submitButton],
    });

    cancelButton.addEventListener('click', () => dialog.close());

    let busy = false;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (busy) {
            return;
        }

        clearFieldErrors(form);
        alert.hidden = true;
        const values = formValues(form);

        if (!values.name || values.name.trim() === '') {
            setFieldErrors(form, { name: 'El nombre es obligatorio.' });

            return;
        }

        busy = true;
        submitButton.disabled = true;
        submitButton.textContent = 'Guardando…';
        form.setAttribute('aria-busy', 'true');

        const body = {
            name: values.name.trim(),
            phone: values.phone.trim() === '' ? null : values.phone.trim(),
            job_title: values.job_title.trim() === '' ? null : values.job_title.trim(),
            timezone: values.timezone,
            version: user.version,
        };

        if (!isSelf) {
            body.role = values.role;
        }

        try {
            const { data } = await api(`/users/${encodeURIComponent(user.id)}`, {
                method: 'PATCH',
                version: user.version,
                body,
            });

            dialog.close();
            toast(`Usuario ${data.name} actualizado.`, { tone: 'success' });

            if (isSelf) {
                await ctx.refreshSession();
            }

            onSaved();
        } catch (error) {
            applyFormError(error, form, alert);

            if (error && error.code === 'VERSION_CONFLICT') {
                alert.append(el('button', {
                    type: 'button',
                    class: 'btn btn-secondary',
                    onClick: () => {
                        dialog.close();
                        void reloadAndEdit(ctx, user.id, onSaved);
                    },
                }, 'Recargar datos y editar de nuevo'));
            }
        } finally {
            busy = false;
            submitButton.disabled = false;
            submitButton.textContent = 'Guardar cambios';
            form.removeAttribute('aria-busy');
        }
    });
}

/**
 * @param {any} ctx
 * @param {string} id
 * @param {() => void} onSaved
 */
async function reloadAndEdit(ctx, id, onSaved) {
    try {
        const { data } = await api(`/users/${encodeURIComponent(id)}`);
        openEditDialog(ctx, data, onSaved);
    } catch (error) {
        reportActionError(error, 'No se pudieron recargar los datos del usuario');
    }
}

/**
 * Muestra la clave temporal una única vez, con botón de copiar.
 *
 * @param {{user: any, temporary_password: string}} data
 * @param {any} ctx
 */
function showTemporaryPassword(data, ctx) {
    const value = typeof data.temporary_password === 'string' ? data.temporary_password : '';
    const inputId = 'temporary-password';
    const status = el('p', { class: 'inline-status', role: 'status', 'aria-live': 'polite' });
    const input = el('input', {
        id: inputId,
        name: 'temporary_password',
        type: 'text',
        value,
        readonly: true,
        class: 'mono',
        autocomplete: 'off',
        'aria-describedby': 'temporary-password-help',
        onFocus: (event) => event.target.select(),
    });

    const copyButton = el('button', {
        type: 'button',
        class: 'btn btn-secondary',
        onClick: async () => {
            try {
                await navigator.clipboard.writeText(value);
                status.textContent = 'Clave copiada al portapapeles.';
            } catch {
                input.focus();
                input.select();
                status.textContent = 'No se pudo copiar automáticamente. La clave está seleccionada: cópiala manualmente.';
            }
        },
    }, 'Copiar clave');

    const dialog = openDialog({
        title: 'Contraseña temporal emitida',
        description: `Para ${data.user ? data.user.name : 'la cuenta'}.`,
        content: el('div', { class: 'stack-sm' }, [
            el('label', { for: inputId, class: 'field-label', text: 'Contraseña temporal' }),
            el('div', { class: 'input-with-button' }, [input, copyButton]),
            el('p', {
                class: 'help',
                id: 'temporary-password-help',
                text: 'Se muestra una sola vez. No se guarda ni se puede volver a consultar; si se pierde, habrá que restablecerla de nuevo.',
            }),
            status,
        ]),
        actions: [el('button', {
            type: 'button',
            class: 'btn btn-primary',
            onClick: () => dialog.close(),
        }, 'He guardado la clave')],
    });

    input.focus();
    input.select();

    if (data.user && data.user.id === ctx.session.user.id) {
        toast('Se restableció tu propio acceso: usa la clave temporal en el próximo inicio de sesión.', {
            tone: 'warning',
        });
    }
}
