/**
 * Componentes reutilizables de interfaz (§17).
 *
 * Todo se construye con `document.createElement` a través de `el()`: nunca se
 * interpreta HTML del servidor. Los estados se comunican con texto y símbolo,
 * no solo con color, y los diálogos atrapan el foco.
 */

import { el, append, text } from './api.js';

let autoId = 0;

/**
 * Identificador único para asociar `label`, ayuda y error.
 *
 * @param {string} prefix
 * @returns {string}
 */
export function uid(prefix = 'ui') {
    autoId += 1;

    return `${prefix}-${autoId}`;
}

/**
 * @param {Node} node
 */
export function clear(node) {
    while (node.firstChild) {
        node.removeChild(node.firstChild);
    }
}

/**
 * Reemplaza el contenido de un contenedor.
 *
 * @param {Element} container
 * @param {unknown} content
 */
export function mount(container, content) {
    clear(container);
    append(container, content);
}

/**
 * Insignia de estado: siempre símbolo + texto.
 *
 * @param {boolean} active
 * @param {{activeLabel?: string, inactiveLabel?: string}} [labels]
 * @returns {HTMLElement}
 */
export function statusBadge(active, labels = {}) {
    const activeLabel = labels.activeLabel ?? 'Activo';
    const inactiveLabel = labels.inactiveLabel ?? 'Inactivo';

    return el(
        'span',
        { class: `badge ${active ? 'badge-active' : 'badge-inactive'}` },
        [
            el('span', { class: 'badge-icon', 'aria-hidden': 'true', text: active ? '✓' : '⛔' }),
            active ? activeLabel : inactiveLabel,
        ],
    );
}

/**
 * Cabecera de una vista con su `<h1>` enfocable por el router.
 *
 * @param {{title: string, lead?: string, actions?: unknown}} options
 * @returns {HTMLElement}
 */
export function pageHeader({ title, lead = null, actions = null }) {
    return el('header', { class: 'page-header' }, [
        el('div', { class: 'page-heading' }, [
            el('h1', { class: 'page-title', tabindex: '-1', text: title }),
            lead ? el('p', { class: 'page-lead', text: lead }) : null,
        ]),
        actions ? el('div', { class: 'page-actions' }, actions) : null,
    ]);
}

/**
 * @param {string} [label]
 * @returns {HTMLElement}
 */
export function loadingState(label = 'Cargando…') {
    return el('div', { class: 'state state-loading', role: 'status', 'aria-live': 'polite' }, [
        el('span', { class: 'spinner', 'aria-hidden': 'true' }),
        el('p', { class: 'state-message', text: label }),
    ]);
}

/**
 * @param {{title: string, message?: string, action?: unknown}} options
 * @returns {HTMLElement}
 */
export function emptyState({ title, message = null, action = null }) {
    return el('div', { class: 'state state-empty' }, [
        el('span', { class: 'state-icon', 'aria-hidden': 'true', text: '∅' }),
        el('h2', { class: 'state-title', text: title }),
        message ? el('p', { class: 'state-message', text: message }) : null,
        action ? el('div', { class: 'state-action' }, action) : null,
    ]);
}

/**
 * Error de pantalla con referencia de soporte y reintento explícito.
 *
 * @param {{error?: {message?: string, code?: string, requestId?: string}|null, title?: string, onRetry?: (() => void)|null}} options
 * @returns {HTMLElement}
 */
export function errorState({ error = null, title = 'No se pudo cargar la información', onRetry = null } = {}) {
    const message = error && typeof error.message === 'string' && error.message !== ''
        ? error.message
        : 'Ocurrió un error inesperado. Inténtalo de nuevo.';
    const detail = [];

    if (error && error.code && error.code !== 'UNKNOWN_ERROR') {
        detail.push(`Código: ${error.code}`);
    }

    if (error && error.requestId) {
        detail.push(`Referencia: ${error.requestId}`);
    }

    return el('div', { class: 'state state-error', role: 'alert' }, [
        el('span', { class: 'state-icon', 'aria-hidden': 'true', text: '⚠' }),
        el('h2', { class: 'state-title', text: title }),
        el('p', { class: 'state-message', text: message }),
        detail.length > 0 ? el('p', { class: 'state-detail', text: detail.join(' · ') }) : null,
        onRetry
            ? el('div', { class: 'state-action' }, el('button', {
                type: 'button',
                class: 'btn btn-secondary',
                onClick: onRetry,
            }, 'Reintentar'))
            : null,
    ]);
}

/**
 * Aviso en línea (información, advertencia o error) con título y contenido.
 *
 * @param {{tone?: 'info'|'warning'|'error'|'success', title?: string|null, message?: string|null, children?: unknown}} options
 * @returns {HTMLElement}
 */
export function notice({ tone = 'info', title = null, message = null, children = null } = {}) {
    const role = tone === 'error' ? 'alert' : 'status';
    const icons = { info: 'i', warning: '⚠', error: '⚠', success: '✓' };

    return el('div', { class: `notice notice-${tone}`, role }, [
        el('span', { class: 'notice-icon', 'aria-hidden': 'true', text: icons[tone] ?? 'i' }),
        el('div', { class: 'notice-body' }, [
            title ? el('p', { class: 'notice-title', text: title }) : null,
            message ? el('p', { class: 'notice-message', text: message }) : null,
            children,
        ]),
    ]);
}

/**
 * Panel con título, usado para agrupar formularios.
 *
 * @param {{title: string, description?: string|null, children?: unknown}} options
 * @returns {HTMLElement}
 */
export function panel({ title, description = null, children = null }) {
    return el('section', { class: 'panel' }, [
        el('div', { class: 'panel-header' }, [
            el('h2', { class: 'panel-title', text: title }),
            description ? el('p', { class: 'panel-description', text: description }) : null,
        ]),
        el('div', { class: 'panel-body' }, children),
    ]);
}

/**
 * Tabla accesible con cabeceras `scope="col"`.
 *
 * @param {{
 *   caption: string,
 *   columns: {label: string, className?: string, render: (row: any) => unknown}[],
 *   rows: any[],
 *   emptyTitle?: string,
 *   emptyMessage?: string,
 * }} options
 * @returns {HTMLElement}
 */
export function dataTable({ caption, columns, rows, emptyTitle = 'No hay resultados', emptyMessage = null }) {
    if (!Array.isArray(rows) || rows.length === 0) {
        return emptyState({ title: emptyTitle, message: emptyMessage });
    }

    return el('div', {
        class: 'table-wrap',
        tabindex: '0',
        role: 'region',
        'aria-label': caption,
    }, el('table', { class: 'data-table' }, [
        el('caption', { class: 'visually-hidden', text: caption }),
        el('thead', {}, el('tr', {}, columns.map((column) => el('th', {
            scope: 'col',
            class: column.className ?? null,
        }, column.label)))),
        el('tbody', {}, rows.map((row) => el('tr', {}, columns.map((column) => el(
            'td',
            { class: column.className ?? null },
            column.render(row),
        ))))),
    ]));
}

/**
 * Paginador con rango anunciado por `aria-live` y botones nunca dependientes solo del color.
 *
 * @param {{total: number, limit: number, offset: number, label: string, onChange: (offset: number) => void, disabled?: boolean}} options
 * @returns {HTMLElement}
 */
export function pagination({ total, limit, offset, label, onChange, disabled = false }) {
    const safeTotal = Number.isFinite(total) ? Math.max(0, total) : 0;
    const from = safeTotal === 0 ? 0 : offset + 1;
    const to = Math.min(offset + limit, safeTotal);
    const hasPrev = offset > 0;
    const hasNext = offset + limit < safeTotal;
    const summary = safeTotal === 0
        ? `Sin ${label} que mostrar`
        : `Mostrando ${from}–${to} de ${safeTotal} ${label}`;

    return el('nav', { class: 'pagination', 'aria-label': `Paginación de ${label}` }, [
        el('p', { class: 'pagination-info', 'aria-live': 'polite', text: summary }),
        el('div', { class: 'pagination-actions' }, [
            el('button', {
                type: 'button',
                class: 'btn btn-secondary',
                disabled: disabled || !hasPrev || undefined,
                onClick: () => onChange(Math.max(0, offset - limit)),
            }, '← Anterior'),
            el('button', {
                type: 'button',
                class: 'btn btn-secondary',
                disabled: disabled || !hasNext || undefined,
                onClick: () => onChange(offset + limit),
            }, 'Siguiente →'),
        ]),
    ]);
}

/* ------------------------------------------------------------------------- *
 * Formularios
 * ------------------------------------------------------------------------- */

/**
 * Campo con `<label for>`, ayuda y hueco de error (`aria-describedby`/`aria-invalid`).
 *
 * @param {{
 *   label: string,
 *   name: string,
 *   type?: string,
 *   value?: string|number|null,
 *   required?: boolean,
 *   help?: string|null,
 *   autocomplete?: string|null,
 *   placeholder?: string|null,
 *   inputMode?: string|null,
 *   maxLength?: number|null,
 *   minLength?: number|null,
 *   pattern?: string|null,
 *   accept?: string|null,
 *   rows?: number|null,
 *   options?: {value: string, label: string}[]|null,
 *   disabled?: boolean,
 * }} options
 * @returns {HTMLElement}
 */
export function field({
    label,
    name,
    type = 'text',
    value = '',
    required = false,
    help = null,
    autocomplete = null,
    placeholder = null,
    inputMode = null,
    maxLength = null,
    minLength = null,
    pattern = null,
    accept = null,
    rows = null,
    options = null,
    disabled = false,
}) {
    const id = `${name}-${uid('field')}`;
    const helpId = `${id}-help`;
    const errorId = `${id}-error`;
    const describedBy = [help ? helpId : null, errorId].filter(Boolean).join(' ');

    const common = {
        id,
        name,
        required: required || undefined,
        'aria-describedby': describedBy,
        'aria-invalid': 'false',
        autocomplete: autocomplete || undefined,
        placeholder: placeholder || undefined,
        maxlength: maxLength ?? undefined,
        minlength: minLength ?? undefined,
        pattern: pattern || undefined,
        inputmode: inputMode || undefined,
        disabled: disabled || undefined,
    };

    /** @type {HTMLElement} */
    let control;

    if (type === 'select') {
        control = el('select', common, (options ?? []).map((option) => el('option', {
            value: option.value,
            selected: String(option.value) === String(value) || undefined,
        }, option.label)));

        const fallback = options && options.length > 0 ? options[0].value : '';
        control.value = value === null || value === undefined || value === '' ? fallback : String(value);
    } else if (type === 'textarea') {
        control = el('textarea', { ...common, rows: rows ?? 4 }, value === null || value === undefined ? '' : String(value));
    } else {
        control = el('input', {
            ...common,
            type,
            value: value === null || value === undefined ? '' : String(value),
            accept: accept || undefined,
        });
    }

    const row = el('div', { class: 'form-row' }, [
        el('label', { for: id, class: required ? 'is-required' : null }, [
            label,
            required ? el('span', { class: 'required-mark', 'aria-hidden': 'true', text: ' *' }) : null,
        ]),
        control,
        help ? el('p', { class: 'help', id: helpId, text: help }) : null,
        el('p', { class: 'field-error', id: errorId, hidden: true }),
    ]);

    row.control = control;

    return row;
}

/**
 * @param {HTMLFormElement} form
 * @returns {Record<string, string>}
 */
export function formValues(form) {
    /** @type {Record<string, string>} */
    const values = {};

    for (const [key, value] of new FormData(form).entries()) {
        if (typeof value === 'string') {
            values[key] = value;
        }
    }

    return values;
}

/**
 * Pinta `error.fields` junto a cada campo. Devuelve los campos que no existen en el formulario.
 *
 * @param {HTMLFormElement} form
 * @param {Record<string, string>} fields
 * @returns {{name: string, message: string}[]}
 */
export function setFieldErrors(form, fields = {}) {
    const unmatched = [];
    const known = new Set();

    form.querySelectorAll('input[name], select[name], textarea[name]').forEach((control) => {
        const name = control.name;
        known.add(name);
        const message = typeof fields[name] === 'string' ? fields[name] : null;
        const errorNode = document.getElementById(`${control.id}-error`);

        if (message) {
            control.setAttribute('aria-invalid', 'true');

            if (errorNode) {
                errorNode.textContent = message;
                errorNode.hidden = false;
            }
        } else {
            control.setAttribute('aria-invalid', 'false');

            if (errorNode) {
                errorNode.textContent = '';
                errorNode.hidden = true;
            }
        }
    });

    for (const [name, message] of Object.entries(fields || {})) {
        if (!known.has(name) && typeof message === 'string') {
            unmatched.push({ name, message });
        }
    }

    return unmatched;
}

/**
 * @param {HTMLFormElement} form
 */
export function clearFieldErrors(form) {
    setFieldErrors(form, {});
}

/**
 * Contenedor de error general del formulario (role="alert").
 *
 * @returns {HTMLElement}
 */
export function formAlert() {
    return el('div', { class: 'form-alert', role: 'alert', tabindex: '-1', hidden: true });
}

/**
 * @param {HTMLElement} box
 * @param {string} message
 * @param {string|null} detail
 */
export function showFormError(box, message, detail = null) {
    clear(box);
    box.hidden = false;
    box.append(el('p', { class: 'form-alert-message', text: message }));

    if (detail) {
        box.append(el('p', { class: 'form-alert-detail', text: detail }));
    }
}

/**
 * Aplica un error de API: mensaje general + `error.fields` junto a cada campo.
 *
 * @param {{message?: string, code?: string, fields?: Record<string,string>, requestId?: string|null}|null} error
 * @param {HTMLFormElement|null} form
 * @param {HTMLElement|null} alertBox
 */
export function applyFormError(error, form, alertBox) {
    const fields = error && error.fields ? error.fields : {};
    const unmatched = form ? setFieldErrors(form, fields) : [];
    const message = error && typeof error.message === 'string' && error.message !== ''
        ? error.message
        : 'Ocurrió un error inesperado.';
    const detail = [];

    if (error && error.code && error.code !== 'UNKNOWN_ERROR') {
        detail.push(`Código: ${error.code}`);
    }

    if (error && error.requestId) {
        detail.push(`Referencia: ${error.requestId}`);
    }

    if (unmatched.length > 0) {
        detail.push(unmatched.map((item) => `${item.name}: ${item.message}`).join(' · '));
    }

    if (alertBox) {
        showFormError(alertBox, message, detail.length > 0 ? detail.join(' · ') : null);
    }

    const firstInvalid = form ? form.querySelector('[aria-invalid="true"]') : null;

    if (firstInvalid instanceof HTMLElement) {
        firstInvalid.focus();
    } else if (alertBox) {
        alertBox.focus();
    }
}

/* ------------------------------------------------------------------------- *
 * Diálogos
 * ------------------------------------------------------------------------- */

const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

/**
 * @param {HTMLElement} scope
 * @returns {HTMLElement[]}
 */
function focusableIn(scope) {
    return Array.from(scope.querySelectorAll(FOCUSABLE)).filter((node) => node instanceof HTMLElement
        && !node.hasAttribute('hidden')
        && node.getClientRects().length > 0);
}

/**
 * Diálogo modal accesible: `role="dialog"`, `aria-modal`, foco atrapado, `Esc` cierra
 * y el foco vuelve al elemento que abrió el diálogo.
 *
 * @param {{
 *   title: string,
 *   description?: string|null,
 *   content?: unknown,
 *   actions?: (HTMLElement|{label: string, onClick?: (event: MouseEvent) => void, variant?: 'primary'|'secondary'|'danger', type?: string, form?: string})[],
 *   initialFocus?: string|null,
 *   onClose?: (() => void)|null,
 * }} options
 * @returns {{close: () => void, panel: HTMLElement, body: HTMLElement, setTitle: (title: string) => void}}
 */
export function openDialog({ title, description = null, content = null, actions = [], initialFocus = null, onClose = null }) {
    const titleId = uid('dialog-title');
    const descriptionId = description ? uid('dialog-desc') : null;
    const previouslyFocused = document.activeElement;
    let closed = false;

    const heading = el('h2', { class: 'dialog-title', id: titleId, text: title });
    const body = el('div', { class: 'dialog-body' }, [
        description ? el('p', { class: 'dialog-description', id: descriptionId ?? undefined, text: description }) : null,
        content,
    ]);
    const footer = actions && actions.length > 0
        ? el('div', { class: 'dialog-footer' }, actions.map((action) => {
            if (action instanceof Node) {
                return action;
            }

            const variant = action.variant ?? 'secondary';

            return el('button', {
                type: action.type ?? 'button',
                class: `btn ${variant === 'primary' ? 'btn-primary' : variant === 'danger' ? 'btn-danger' : 'btn-secondary'}`,
                form: action.form ?? undefined,
                onClick: action.onClick,
            }, action.label);
        }))
        : null;
    const closeButton = el('button', {
        type: 'button',
        class: 'btn btn-ghost dialog-close',
        'aria-label': 'Cerrar diálogo',
        onClick: () => close(),
    }, '✕');
    const panel = el('div', {
        class: 'dialog',
        role: 'dialog',
        'aria-modal': 'true',
        'aria-labelledby': titleId,
        'aria-describedby': descriptionId ?? undefined,
        tabindex: '-1',
    }, [
        el('div', { class: 'dialog-header' }, [heading, closeButton]),
        body,
        footer,
    ]);
    const overlay = el('div', { class: 'dialog-overlay' }, panel);

    const onKeydown = (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            close();

            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusables = focusableIn(panel);

        if (focusables.length === 0) {
            event.preventDefault();
            panel.focus();

            return;
        }

        const first = focusables[0];
        const last = focusables[focusables.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    };

    function close() {
        if (closed) {
            return;
        }

        closed = true;
        document.removeEventListener('keydown', onKeydown, true);
        overlay.remove();
        document.body.classList.remove('dialog-open');

        if (previouslyFocused instanceof HTMLElement) {
            previouslyFocused.focus();
        }

        if (typeof onClose === 'function') {
            onClose();
        }
    }

    document.addEventListener('keydown', onKeydown, true);
    document.body.append(overlay);
    document.body.classList.add('dialog-open');

    const preferred = initialFocus ? panel.querySelector(initialFocus) : null;
    const target = preferred instanceof HTMLElement ? preferred : focusableIn(panel)[0];

    if (target instanceof HTMLElement) {
        target.focus();
    } else {
        panel.focus();
    }

    return {
        close,
        panel,
        body,
        setTitle: (nextTitle) => { heading.textContent = nextTitle; },
    };
}

/**
 * Confirmación de una acción sensible. Con `requireReason`, el botón de confirmar
 * permanece deshabilitado hasta que se escribe un motivo.
 *
 * @param {{
 *   title: string,
 *   message: string,
 *   confirmLabel?: string,
 *   cancelLabel?: string,
 *   destructive?: boolean,
 *   requireReason?: boolean,
 *   reasonLabel?: string,
 *   reasonHelp?: string|null,
 *   summary?: string[],
 * }} options
 * @returns {Promise<{reason: string}|null>}
 */
export function confirmAction({
    title,
    message,
    confirmLabel = 'Confirmar',
    cancelLabel = 'Cancelar',
    destructive = true,
    requireReason = false,
    reasonLabel = 'Motivo',
    reasonHelp = 'Mínimo 5 caracteres.',
    summary = [],
}) {
    return new Promise((resolve) => {
        let settled = false;
        let dialog = null;

        const finish = (value) => {
            if (settled) {
                return;
            }

            settled = true;

            if (dialog) {
                dialog.close();
            }

            resolve(value);
        };

        const reasonId = requireReason ? uid('reason') : null;
        const reasonInput = requireReason
            ? el('textarea', {
                id: reasonId,
                rows: 3,
                maxlength: 500,
                required: true,
                'aria-describedby': reasonHelp ? `${reasonId}-help` : undefined,
            })
            : null;
        const reasonError = requireReason
            ? el('p', { class: 'field-error', id: `${reasonId}-error`, hidden: true })
            : null;

        const cancelButton = el('button', {
            type: 'button',
            class: 'btn btn-secondary',
            'data-autofocus': '',
            onClick: () => finish(null),
        }, cancelLabel);

        const confirmButton = el('button', {
            type: 'button',
            class: `btn ${destructive ? 'btn-danger' : 'btn-primary'}`,
            disabled: requireReason || undefined,
            onClick: () => finish({ reason: reasonInput ? reasonInput.value.trim() : '' }),
        }, confirmLabel);

        if (reasonInput) {
            reasonInput.addEventListener('input', () => {
                const valid = reasonInput.value.trim().length >= 5;
                confirmButton.disabled = !valid;
                reasonInput.setAttribute('aria-invalid', valid ? 'false' : 'true');

                if (reasonError) {
                    reasonError.textContent = valid ? '' : 'Escribe el motivo (mínimo 5 caracteres).';
                    reasonError.hidden = valid;
                }
            });
        }

        const content = el('div', { class: 'confirm' }, [
            el('p', { class: 'confirm-message', text: message }),
            summary.length > 0
                ? el('ul', { class: 'confirm-summary' }, summary.map((item) => el('li', { text: item })))
                : null,
            requireReason
                ? el('div', { class: 'form-row' }, [
                    el('label', { for: reasonId }, [
                        reasonLabel,
                        el('span', { class: 'required-mark', 'aria-hidden': 'true', text: ' *' }),
                    ]),
                    reasonInput,
                    reasonHelp ? el('p', { class: 'help', id: `${reasonId}-help`, text: reasonHelp }) : null,
                    reasonError,
                ])
                : null,
        ]);

        dialog = openDialog({
            title,
            content,
            actions: [cancelButton, confirmButton],
            initialFocus: requireReason ? `#${reasonId}` : '[data-autofocus]',
            onClose: () => {
                if (!settled) {
                    settled = true;
                    resolve(null);
                }
            },
        });
    });
}

/* ------------------------------------------------------------------------- *
 * Toasts
 * ------------------------------------------------------------------------- */

/**
 * Aviso flotante en una región `aria-live="polite"`.
 *
 * @param {string} message
 * @param {{tone?: 'info'|'success'|'error'|'warning', duration?: number}} [options]
 * @returns {HTMLElement}
 */
export function toast(message, { tone = 'info', duration = null } = {}) {
    let region = document.getElementById('toast-region');

    if (!region) {
        region = el('div', {
            id: 'toast-region',
            class: 'toast-region',
            'aria-live': 'polite',
            'aria-atomic': 'true',
        });
        document.body.append(region);
    }

    while (region.children.length >= 4) {
        region.removeChild(region.firstChild);
    }

    const icons = { info: 'i', success: '✓', warning: '⚠', error: '⚠' };
    const node = el('div', {
        class: `toast toast-${tone}`,
        role: tone === 'error' ? 'alert' : 'status',
    }, [
        el('span', { class: 'toast-icon', 'aria-hidden': 'true', text: icons[tone] ?? 'i' }),
        el('p', { class: 'toast-message', text: message }),
        el('button', {
            type: 'button',
            class: 'toast-close',
            'aria-label': 'Cerrar aviso',
            onClick: () => node.remove(),
        }, '✕'),
    ]);

    region.append(node);

    const timeout = duration ?? (tone === 'error' ? 0 : 6000);

    if (timeout > 0) {
        window.setTimeout(() => node.remove(), timeout);
    }

    return node;
}

/* ------------------------------------------------------------------------- *
 * Presentación
 * ------------------------------------------------------------------------- */

/**
 * @param {string|null|undefined} iso
 * @param {string|null|undefined} timeZone
 * @returns {string}
 */
export function formatDateTime(iso, timeZone = null) {
    if (typeof iso !== 'string' || iso === '') {
        return '—';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    try {
        return new Intl.DateTimeFormat('es-MX', {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: timeZone || undefined,
        }).format(date);
    } catch {
        return new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
    }
}

/**
 * @param {string|null|undefined} name
 * @returns {string}
 */
export function initials(name) {
    if (typeof name !== 'string' || name.trim() === '') {
        return '?';
    }

    return name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

/**
 * Avatar actual del usuario o iniciales.
 *
 * @param {{name?: string, avatar_url?: string|null}} user
 * @param {{small?: boolean, alt?: string, loading?: 'lazy'|'eager'}} [options]
 * @returns {HTMLElement}
 */
export function userAvatar(user, { small = false, alt = '', loading = 'lazy' } = {}) {
    const name = user && typeof user.name === 'string' ? user.name : '';
    const sizeClass = small ? 'avatar-sm' : 'avatar-md';
    const url = user ? user.avatar_url : null;

    if (typeof url === 'string' && url !== '') {
        return el('img', {
            class: `avatar ${sizeClass}`,
            src: url,
            alt,
            loading,
            width: small ? '32' : '64',
            height: small ? '32' : '64',
        });
    }

    return el('span', {
        class: `avatar ${sizeClass} avatar-initials`,
        'aria-hidden': alt === '' ? 'true' : undefined,
        role: alt === '' ? undefined : 'img',
        'aria-label': alt === '' ? undefined : alt,
        text: initials(name),
    });
}

/** Exporta `text` por comodidad de las vistas. */
export { text };
