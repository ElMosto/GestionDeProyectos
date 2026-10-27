/**
 * Clientes (`#/clientes`): directorio, alta y edición con recorte de campos (§7).
 *
 * El backend decide qué campos llegan según el rol y la propiedad del registro. La UI
 * solo muestra los botones que el usuario puede usar y envía `version` en cada edición.
 * Las advertencias de posibles duplicados llegan en `meta.warnings` y no bloquean el alta.
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
    notice,
    openDialog,
    pageHeader,
    pagination,
    setFieldErrors,
    statusBadge,
    toast,
} from '../ui.js';
import { CLIENT_TYPES, PAGE_SIZE, clientTypeLabel, matchedByLabel } from '../data.js';

/**
 * @param {HTMLElement} container
 * @param {any} ctx
 */
export function renderClientes(container, ctx) {
    if (ctx.session.capabilities['clients.view'] !== true) {
        container.replaceChildren(errorState({
            title: 'No tienes acceso al directorio de clientes',
            error: {
                message: 'Esta sección requiere el permiso de consulta de clientes. Si crees que es un error, consulta con un administrador.',
                code: 'FORBIDDEN',
            },
        }));

        return;
    }

    const capabilities = ctx.session.capabilities;
    const canCreate = capabilities['clients.create'] === true;
    const canEditAny = capabilities['clients.editAny'] === true;
    const canEditOwn = capabilities['clients.editOwn'] === true;
    const canDeactivate = capabilities['clients.deactivate'] === true;

    const state = {
        q: '',
        type: '',
        active: '',
        offset: 0,
        rows: [],
        meta: { total: 0, limit: PAGE_SIZE, offset: 0 },
        warnings: [],
        loading: false,
    };

    const listRegion = el('div', { class: 'list-region' });

    const qControl = el('input', {
        id: 'filter-q',
        name: 'q',
        type: 'search',
        maxlength: 120,
        autocomplete: 'off',
    });
    const typeControl = el('select', { id: 'filter-type', name: 'type' }, [
        el('option', { value: '', selected: true }, 'Todos los tipos'),
        ...CLIENT_TYPES.map((item) => el('option', { value: item.code }, item.label)),
    ]);
    const activeControl = el('select', { id: 'filter-active', name: 'active' }, [
        el('option', { value: '', selected: true }, 'Todos los estados'),
        el('option', { value: '1' }, 'Solo activos'),
        el('option', { value: '0' }, 'Solo desactivados'),
    ]);

    const filters = el('form', { class: 'filters', role: 'search', novalidate: true }, [
        el('div', { class: 'filter-grid' }, [
            el('div', { class: 'filter-field' }, [
                el('label', { for: 'filter-q', text: 'Buscar por nombre, contacto, teléfono o correo' }),
                qControl,
            ]),
            el('div', { class: 'filter-field' }, [
                el('label', { for: 'filter-type', text: 'Tipo' }),
                typeControl,
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
                    typeControl.value = '';
                    activeControl.value = '';
                    state.q = '';
                    state.type = '';
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
        state.type = typeControl.value;
        state.active = activeControl.value;
        state.offset = 0;
        void load();
    });

    const actions = canCreate
        ? el('button', {
            type: 'button',
            class: 'btn btn-primary',
            onClick: () => openCreateDialog((created, warnings) => {
                state.offset = 0;
                state.warnings = warnings;
                void load();

                if (created) {
                    toast(`Cliente ${created.name} creado.`, { tone: 'success' });
                }
            }),
        }, 'Nuevo cliente')
        : null;

    container.replaceChildren(el('div', { class: 'stack' }, [
        pageHeader({
            title: 'Clientes',
            lead: 'Clientes normales y bandas. Los datos sensibles solo se muestran a quien puede verlos.',
            actions,
        }),
        el('section', { class: 'panel' }, el('div', { class: 'panel-body' }, filters)),
        listRegion,
    ]));

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

        if (state.type !== '') {
            params.set('type', state.type);
        }

        if (state.active !== '') {
            params.set('active', state.active);
        }

        return `/clients?${params.toString()}`;
    }

    async function load() {
        if (state.loading) {
            return;
        }

        state.loading = true;
        mount(listRegion, loadingState('Cargando clientes…'));

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
                title: 'No se pudo cargar la lista de clientes',
                onRetry: () => { void load(); },
            }));
        } finally {
            state.loading = false;
        }
    }

    /**
     * @param {any} client
     * @returns {boolean}
     */
    function canEdit(client) {
        if (canEditAny) {
            return true;
        }

        return canEditOwn && client.created_by === ctx.session.user.id;
    }

    function renderList() {
        const hasFilters = state.q !== '' || state.type !== '' || state.active !== '';

        const warningsPanel = state.warnings.length > 0
            ? notice({
                tone: 'warning',
                title: 'Posibles duplicados',
                message: 'El cliente se creó, pero su nombre, teléfono o correo coincide con registros existentes. Revisa si se trata del mismo cliente.',
                children: el('div', { class: 'notice-extra' }, [
                    el('ul', { class: 'notice-list' }, state.warnings.map((warning) => el(
                        'li',
                        {},
                        `${warning.name} — coincide por ${matchedByLabel(warning.matched_by)}`,
                    ))),
                    el('button', {
                        type: 'button',
                        class: 'btn btn-secondary btn-sm',
                        onClick: () => {
                            state.warnings = [];
                            renderList();
                        },
                    }, 'Entendido'),
                ]),
            })
            : null;

        const table = dataTable({
            caption: 'Clientes del estudio',
            emptyTitle: hasFilters ? 'Ningún cliente coincide con los filtros' : 'Todavía no hay clientes',
            emptyMessage: hasFilters
                ? 'Prueba con otro texto o quita algún filtro.'
                : (canCreate ? 'Crea el primer cliente con el botón «Nuevo cliente».' : null),
            columns: [
                {
                    label: 'Cliente',
                    render: (client) => el('div', { class: 'cell-stack' }, [
                        el('span', { class: 'cell-strong', text: client.name }),
                        typeof client.representative_name === 'string' && client.representative_name !== ''
                            ? el('span', { class: 'cell-sub', text: `Representante: ${client.representative_name}` })
                            : null,
                    ]),
                },
                {
                    label: 'Tipo',
                    render: (client) => el('span', {
                        class: `tag ${client.type === 'BANDA' ? 'tag-banda' : ''}`,
                        text: clientTypeLabel(client.type),
                    }),
                },
                {
                    label: 'Contacto',
                    render: (client) => client.contact_name ?? '—',
                },
                {
                    label: 'Teléfono',
                    render: (client) => client.phone ?? '—',
                },
                {
                    label: 'Estado',
                    render: (client) => statusBadge(client.active),
                },
                {
                    label: 'Acciones',
                    className: 'cell-actions',
                    render: (client) => {
                        const rowActions = [];

                        if (canEdit(client)) {
                            rowActions.push(el('button', {
                                type: 'button',
                                class: 'btn btn-secondary btn-sm',
                                'aria-label': `Editar a ${client.name}`,
                                onClick: () => openEditDialog(client, canEditAny, () => { void load(); }),
                            }, 'Editar'));
                        }

                        if (canDeactivate && client.active) {
                            rowActions.push(el('button', {
                                type: 'button',
                                class: 'btn btn-danger btn-sm',
                                'aria-label': `Desactivar a ${client.name}`,
                                onClick: () => { void deactivate(client); },
                            }, 'Desactivar'));
                        }

                        return rowActions.length > 0
                            ? el('div', { class: 'row-actions' }, rowActions)
                            : el('span', { class: 'cell-sub', text: 'Solo lectura' });
                    },
                },
            ],
            rows: state.rows,
        });

        const pager = pagination({
            total: state.meta.total,
            limit: state.meta.limit,
            offset: state.meta.offset,
            label: 'clientes',
            onChange: (offset) => {
                state.offset = offset;
                void load();
            },
        });

        listRegion.replaceChildren(el('section', { class: 'panel' }, [
            el('div', { class: 'panel-header' }, [
                el('h2', { class: 'panel-title', text: 'Directorio de clientes' }),
                el('p', { class: 'panel-description', text: 'Las notas, la dirección y el correo solo se ven si tu rol o tu autoría lo permiten.' }),
            ]),
            warningsPanel ? el('div', { class: 'panel-body' }, warningsPanel) : null,
            el('div', { class: 'panel-body' }, [table, pager]),
        ]));
    }

    /**
     * @param {any} client
     */
    async function deactivate(client) {
        const confirmation = await confirmAction({
            title: `Desactivar a ${client.name}`,
            message: 'El cliente dejará de estar disponible para nuevas operaciones. No se borra su historial.',
            summary: [
                `Tipo: ${clientTypeLabel(client.type)}`,
                'Si tiene reservas futuras activas, el servidor rechazará la desactivación.',
            ],
            requireReason: false,
            confirmLabel: 'Desactivar cliente',
        });

        if (confirmation === null) {
            return;
        }

        try {
            await api(`/clients/${encodeURIComponent(client.id)}/deactivate`, { method: 'POST' });
            toast('Cliente desactivado.', { tone: 'success' });
            void load();
        } catch (error) {
            const message = error && typeof error.message === 'string' ? error.message : 'No se pudo desactivar el cliente.';
            const code = error && error.code ? ` Código: ${error.code}.` : '';
            toast(`${message}${code}`, { tone: 'error' });
        }
    }

    /**
     * @param {(created: any, warnings: any[]) => void} onCreated
     */
    function openCreateDialog(onCreated) {
        const type = field({
            label: 'Tipo de cliente',
            name: 'type',
            type: 'select',
            options: CLIENT_TYPES.map((item) => ({ value: item.code, label: item.label })),
            value: 'NORMAL',
            help: 'Una banda exige representante y teléfono.',
        });
        const name = field({
            label: 'Nombre comercial o de la banda',
            name: 'name',
            required: true,
            maxLength: 160,
        });
        const contactName = field({
            label: 'Contacto principal',
            name: 'contact_name',
            maxLength: 120,
            help: 'Opcional.',
        });
        const representative = field({
            label: 'Representante de la banda',
            name: 'representative_name',
            maxLength: 120,
            help: 'Obligatorio si el cliente es una banda.',
        });
        const phone = field({
            label: 'Teléfono',
            name: 'phone',
            type: 'tel',
            maxLength: 32,
            help: 'Obligatorio si el cliente es una banda.',
        });
        const email = field({
            label: 'Correo electrónico',
            name: 'email',
            type: 'email',
            maxLength: 180,
            inputMode: 'email',
            help: 'Opcional.',
        });
        const address = field({
            label: 'Dirección',
            name: 'address',
            maxLength: 255,
            help: 'Opcional.',
        });
        const notes = field({
            label: 'Notas',
            name: 'notes',
            type: 'textarea',
            maxLength: 5000,
            rows: 4,
            help: 'Opcional. Solo las ven el administrador y quien creó el cliente.',
        });

        const hint = el('p', { class: 'help', 'aria-live': 'polite' });
        const alert = formAlert();

        const syncBandFields = () => {
            const isBand = type.control.value === 'BANDA';
            representative.control.required = isBand;
            phone.control.required = isBand;
            representative.control.setAttribute('aria-required', isBand ? 'true' : 'false');
            phone.control.setAttribute('aria-required', isBand ? 'true' : 'false');
            hint.textContent = isBand
                ? 'Una banda exige el nombre del representante y un teléfono de contacto.'
                : '';
        };

        type.control.addEventListener('change', syncBandFields);
        syncBandFields();

        const form = el('form', { class: 'form-grid', novalidate: true, id: 'create-client-form' }, [
            type,
            name,
            contactName,
            representative,
            phone,
            email,
            address,
            notes,
            hint,
            alert,
        ]);

        const cancelButton = el('button', { type: 'button', class: 'btn btn-secondary' }, 'Cancelar');
        const submitButton = el('button', {
            type: 'submit',
            class: 'btn btn-primary',
            form: 'create-client-form',
        }, 'Crear cliente');

        const dialog = openDialog({
            title: 'Nuevo cliente',
            description: 'El alta nunca se bloquea por un posible duplicado: se avisa y se guarda.',
            content: form,
            actions: [cancelButton, submitButton],
            initialFocus: '.dialog-body input, .dialog-body select',
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

            if (values.type === 'BANDA') {
                if (!values.representative_name || values.representative_name.trim() === '') {
                    localErrors.representative_name = 'Una banda exige el nombre del representante.';
                }

                if (!values.phone || values.phone.trim() === '') {
                    localErrors.phone = 'Una banda exige un teléfono de contacto.';
                }
            }

            if (Object.keys(localErrors).length > 0) {
                setFieldErrors(form, localErrors);
                const firstInvalid = form.querySelector('[aria-invalid="true"]');

                if (firstInvalid instanceof HTMLElement) {
                    firstInvalid.focus();
                }

                return;
            }

            const nullable = (value) => (value.trim() === '' ? null : value.trim());

            busy = true;
            submitButton.disabled = true;
            submitButton.textContent = 'Creando…';
            form.setAttribute('aria-busy', 'true');

            try {
                const payload = await api('/clients', {
                    method: 'POST',
                    body: {
                        type: values.type,
                        name: values.name.trim(),
                        contact_name: nullable(values.contact_name),
                        representative_name: nullable(values.representative_name),
                        phone: nullable(values.phone),
                        email: nullable(values.email),
                        address: nullable(values.address),
                        notes: nullable(values.notes),
                    },
                });

                dialog.close();
                const warnings = Array.isArray(payload.meta.warnings) ? payload.meta.warnings : [];
                onCreated(payload.data, warnings);
            } catch (error) {
                applyFormError(error, form, alert);
            } finally {
                busy = false;
                submitButton.disabled = false;
                submitButton.textContent = 'Crear cliente';
                form.removeAttribute('aria-busy');
            }
        });
    }

    /**
     * @param {any} client
     * @param {boolean} canEditAnyField
     * @param {() => void} onSaved
     */
    function openEditDialog(client, canEditAnyField, onSaved) {
        const fields = {};

        if (canEditAnyField) {
            fields.type = field({
                label: 'Tipo de cliente',
                name: 'type',
                type: 'select',
                value: client.type,
                options: CLIENT_TYPES.map((item) => ({ value: item.code, label: item.label })),
                help: 'Convertir una banda en normal se rechaza si tiene reservas futuras.',
            });
            fields.name = field({
                label: 'Nombre comercial o de la banda',
                name: 'name',
                value: client.name,
                required: true,
                maxLength: 160,
            });
        }

        fields.contact_name = field({
            label: 'Contacto principal',
            name: 'contact_name',
            value: client.contact_name ?? '',
            maxLength: 120,
        });
        fields.representative_name = field({
            label: 'Representante de la banda',
            name: 'representative_name',
            value: client.representative_name ?? '',
            maxLength: 120,
        });
        fields.phone = field({
            label: 'Teléfono',
            name: 'phone',
            type: 'tel',
            value: client.phone ?? '',
            maxLength: 32,
        });
        fields.email = field({
            label: 'Correo electrónico',
            name: 'email',
            type: 'email',
            value: client.email ?? '',
            maxLength: 180,
            inputMode: 'email',
        });
        fields.address = field({
            label: 'Dirección',
            name: 'address',
            value: client.address ?? '',
            maxLength: 255,
        });

        if (canEditAnyField) {
            fields.notes = field({
                label: 'Notas',
                name: 'notes',
                type: 'textarea',
                value: client.notes ?? '',
                maxLength: 5000,
                rows: 4,
            });
        }

        const alert = formAlert();
        const form = el('form', { class: 'form-grid', novalidate: true, id: 'edit-client-form' }, [
            ...Object.values(fields),
            alert,
        ]);

        const cancelButton = el('button', { type: 'button', class: 'btn btn-secondary' }, 'Cancelar');
        const submitButton = el('button', {
            type: 'submit',
            class: 'btn btn-primary',
            form: 'edit-client-form',
        }, 'Guardar cambios');

        const dialog = openDialog({
            title: `Editar a ${client.name}`,
            description: canEditAnyField
                ? 'Como administrador puedes editar todos los campos. Los cambios quedan auditados.'
                : 'Solo puedes corregir los datos de contacto de los clientes que creaste.',
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

            if (canEditAnyField && (!values.name || values.name.trim() === '')) {
                setFieldErrors(form, { name: 'El nombre es obligatorio.' });

                return;
            }

            // El tipo solo se envía si el actor puede cambiarlo; para el resto manda el
            // valor actual del cliente (el backend sigue validando la regla de banda).
            const effectiveType = canEditAnyField ? values.type : client.type;

            if (effectiveType === 'BANDA') {
                const localErrors = {};

                if (!values.representative_name || values.representative_name.trim() === '') {
                    localErrors.representative_name = 'Una banda exige el nombre del representante.';
                }

                if (!values.phone || values.phone.trim() === '') {
                    localErrors.phone = 'Una banda exige un teléfono de contacto.';
                }

                if (Object.keys(localErrors).length > 0) {
                    setFieldErrors(form, localErrors);

                    return;
                }
            }

            const nullable = (value) => (value.trim() === '' ? null : value.trim());
            const body = {
                contact_name: nullable(values.contact_name),
                representative_name: nullable(values.representative_name),
                phone: nullable(values.phone),
                email: nullable(values.email),
                address: nullable(values.address),
                version: client.version,
            };

            if (canEditAnyField) {
                body.type = values.type;
                body.name = values.name.trim();
                body.notes = nullable(values.notes ?? '');
            }

            busy = true;
            submitButton.disabled = true;
            submitButton.textContent = 'Guardando…';
            form.setAttribute('aria-busy', 'true');

            try {
                const { data } = await api(`/clients/${encodeURIComponent(client.id)}`, {
                    method: 'PATCH',
                    version: client.version,
                    body,
                });

                dialog.close();
                toast(`Cliente ${data.name} actualizado.`, { tone: 'success' });
                onSaved();
            } catch (error) {
                applyFormError(error, form, alert);

                if (error && error.code === 'VERSION_CONFLICT') {
                    alert.append(el('button', {
                        type: 'button',
                        class: 'btn btn-secondary',
                        onClick: () => {
                            dialog.close();
                            void reloadAndEdit(client.id, canEditAnyField, onSaved);
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
     * @param {string} id
     * @param {boolean} canEditAnyField
     * @param {() => void} onSaved
     */
    async function reloadAndEdit(id, canEditAnyField, onSaved) {
        try {
            const { data } = await api(`/clients/${encodeURIComponent(id)}`);
            openEditDialog(data, canEditAnyField, onSaved);
        } catch (error) {
            const message = error && typeof error.message === 'string' ? error.message : 'No se pudo recargar el cliente.';
            toast(message, { tone: 'error' });
        }
    }

    void load();
}
