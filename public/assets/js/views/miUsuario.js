/**
 * «Mi usuario» (`#/mi-usuario`): perfil, preferencias, contraseña y avatar (§6).
 *
 * El perfil es una lista blanca del servidor: aquí no se envían rol, estado, correo ni
 * permisos. Los errores de campo llegan en `error.fields` y se pintan junto al campo.
 */

import { api, el, safeUrl } from '../api.js';
import {
    applyFormError,
    clearFieldErrors,
    errorState,
    field,
    formAlert,
    formValues,
    loadingState,
    mount,
    pageHeader,
    panel,
    setFieldErrors,
    toast,
    userAvatar,
} from '../ui.js';
import { NOTIFICATION_PREF_EMAIL, timezoneOptions } from '../data.js';
import { passwordForm } from './passwordForm.js';

const MAX_AVATAR_BYTES = 2 * 1024 * 1024;
const ALLOWED_AVATAR_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/**
 * @param {HTMLElement} container
 * @param {any} ctx
 */
export function renderMiUsuario(container, ctx) {
    const load = async () => {
        mount(container, loadingState('Cargando tu perfil…'));

        try {
            const { data } = await api('/me');
            ctx.setSession(data);
            renderProfile(container, ctx, data);
        } catch (error) {
            mount(container, errorState({ error, onRetry: () => { void load(); } }));
        }
    };

    void load();
}

/**
 * @param {HTMLElement} container
 * @param {any} ctx
 * @param {{user: any}} payload
 */
function renderProfile(container, ctx, payload) {
    const state = { user: payload.user };
    let currentAvatar = userAvatar(state.user, { alt: 'Tu avatar actual', loading: 'eager' });

    const profileForm = buildProfileForm({
        state,
        onSaved: (data) => {
            state.user = data.user;
            ctx.setSession(data);
        },
        onReload: () => { void reloadProfile(container, ctx); },
    });

    const avatarPanel = panel({
        title: 'Avatar',
        description: 'Formatos JPG, PNG o WebP. Máximo 2 MB. El servidor valida el contenido real y recorta la imagen.',
        children: el('div', { class: 'avatar-block' }, [
            el('div', { class: 'avatar-current' }, currentAvatar),
            el('div', { class: 'avatar-editor' }, buildAvatarForm({
                onUpdated: (data) => {
                    state.user = data.user;
                    const next = userAvatar(state.user, { alt: 'Tu avatar actual', loading: 'eager' });
                    currentAvatar.replaceWith(next);
                    currentAvatar = next;
                    ctx.setSession(data);
                    toast('Avatar actualizado.', { tone: 'success' });
                },
            })),
        ]),
    });

    container.replaceChildren(el('div', { class: 'stack' }, [
        pageHeader({
            title: 'Mi usuario',
            lead: 'Estos son tus datos. Tu rol, tu estado y tu correo solo los cambia un administrador.',
        }),
        avatarPanel,
        el('section', { class: 'panel' }, [
            el('div', { class: 'panel-header' }, [
                el('h2', { class: 'panel-title', text: 'Datos del perfil' }),
                el('p', { class: 'panel-description', text: 'El nombre, el teléfono y el cargo se muestran en asignaciones e invitaciones.' }),
            ]),
            el('div', { class: 'panel-body' }, profileForm),
        ]),
        panel({
            title: 'Contraseña',
            description: 'Al cambiarla se renueva esta sesión y se revocan los dispositivos móviles vinculados.',
            children: passwordForm(ctx),
        }),
        panel({
            title: 'Avisos',
            description: 'Los avisos por correo se enviarán cuando el estudio configure el servidor de correo.',
            children: el('p', {
                class: 'panel-text',
                text: 'La preferencia «Recibir avisos por correo electrónico» se guarda en tu perfil junto con los demás datos.',
            }),
        }),
    ]));
}

/**
 * Recarga el perfil tras un conflicto de versión.
 *
 * @param {HTMLElement} container
 * @param {any} ctx
 */
async function reloadProfile(container, ctx) {
    try {
        const { data } = await api('/me');
        ctx.setSession(data);
        renderProfile(container, ctx, data);
        toast('Datos recargados. Revisa los cambios antes de volver a guardar.', { tone: 'info' });
    } catch (error) {
        toast(error && error.message ? error.message : 'No se pudo recargar el perfil.', { tone: 'error' });
    }
}

/**
 * @param {{state: {user: any}, onSaved: (data: any) => void, onReload: () => void}} options
 * @returns {HTMLElement}
 */
function buildProfileForm({ state, onSaved, onReload }) {
    const user = state.user;
    const name = field({
        label: 'Nombre completo',
        name: 'name',
        value: user.name,
        maxLength: 120,
        autocomplete: 'name',
        required: true,
    });
    const phone = field({
        label: 'Teléfono',
        name: 'phone',
        type: 'tel',
        value: user.phone ?? '',
        maxLength: 32,
        autocomplete: 'tel',
        help: 'Opcional. Se usa para contacto interno.',
    });
    const jobTitle = field({
        label: 'Cargo',
        name: 'job_title',
        value: user.job_title ?? '',
        maxLength: 120,
        help: 'Por ejemplo: productor, ingeniero de mezcla, asistente.',
    });
    const timezone = field({
        label: 'Zona horaria',
        name: 'timezone',
        type: 'select',
        value: user.timezone ?? 'America/Mexico_City',
        options: timezoneOptions(user.timezone),
        help: 'Solo afecta a cómo se muestran las fechas; los datos se guardan en UTC.',
    });

    const prefs = user.notification_prefs && typeof user.notification_prefs === 'object'
        ? { ...user.notification_prefs }
        : {};
    const notifyId = 'notification-email';
    const notifyHelpId = 'notification-email-help';
    const notify = el('input', {
        type: 'checkbox',
        id: notifyId,
        name: NOTIFICATION_PREF_EMAIL,
        checked: prefs[NOTIFICATION_PREF_EMAIL] === true,
        'aria-describedby': notifyHelpId,
    });
    const notifyRow = el('div', { class: 'form-row checkbox-row' }, [
        el('div', { class: 'checkbox-control' }, [
            notify,
            el('label', { for: notifyId, text: 'Recibir avisos por correo electrónico' }),
        ]),
        el('p', { class: 'help', id: notifyHelpId, text: 'Se guarda como preferencia de notificación en tu perfil.' }),
    ]);

    const alert = formAlert();
    const submit = el('button', { type: 'submit', class: 'btn btn-primary' }, 'Guardar cambios');
    const form = el('form', { class: 'form-grid', novalidate: true }, [
        name,
        phone,
        jobTitle,
        timezone,
        notifyRow,
        alert,
        el('div', { class: 'form-actions' }, submit),
    ]);

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

        if (Object.keys(localErrors).length > 0) {
            setFieldErrors(form, localErrors);
            const firstInvalid = form.querySelector('[aria-invalid="true"]');

            if (firstInvalid instanceof HTMLElement) {
                firstInvalid.focus();
            }

            return;
        }

        busy = true;
        submit.disabled = true;
        submit.textContent = 'Guardando…';
        form.setAttribute('aria-busy', 'true');

        try {
            const version = state.user.version;
            const { data } = await api('/me', {
                method: 'PATCH',
                version,
                body: {
                    name: values.name.trim(),
                    phone: values.phone.trim() === '' ? null : values.phone.trim(),
                    job_title: values.job_title.trim() === '' ? null : values.job_title.trim(),
                    timezone: values.timezone,
                    notification_prefs: {
                        ...prefs,
                        [NOTIFICATION_PREF_EMAIL]: notify.checked,
                    },
                    version,
                },
            });

            toast('Perfil actualizado.', { tone: 'success' });
            onSaved(data);
        } catch (error) {
            applyFormError(error, form, alert);

            if (error && error.code === 'VERSION_CONFLICT') {
                alert.append(el('button', {
                    type: 'button',
                    class: 'btn btn-secondary',
                    onClick: onReload,
                }, 'Recargar mis datos'));
            }
        } finally {
            busy = false;
            submit.disabled = false;
            submit.textContent = 'Guardar cambios';
            form.removeAttribute('aria-busy');
        }
    });

    return form;
}

/**
 * Formulario de subida del avatar con vista previa local (data URL, nunca HTML).
 *
 * @param {{onUpdated: (data: any) => void}} options
 * @returns {HTMLElement}
 */
function buildAvatarForm({ onUpdated }) {
    const inputId = 'avatar-file';
    const helpId = 'avatar-help';
    const errorId = 'avatar-error';
    const preview = el('img', {
        class: 'avatar avatar-preview',
        alt: 'Vista previa de la imagen seleccionada',
        hidden: true,
    });
    const selectedName = el('p', { class: 'help', 'aria-live': 'polite' });
    const errorNode = el('p', { class: 'field-error', id: errorId, hidden: true });
    const input = el('input', {
        id: inputId,
        name: 'avatar',
        type: 'file',
        accept: ALLOWED_AVATAR_TYPES.join(','),
        'aria-describedby': `${helpId} ${errorId}`,
        'aria-invalid': 'false',
        required: true,
    });
    const submit = el('button', { type: 'submit', class: 'btn btn-primary', disabled: true }, 'Subir avatar');
    const form = el('form', { class: 'avatar-form', novalidate: true }, [
        el('label', { for: inputId, class: 'field-label', text: 'Elegir imagen' }),
        input,
        el('p', { class: 'help', id: helpId, text: 'JPG, PNG o WebP de hasta 2 MB.' }),
        selectedName,
        preview,
        errorNode,
        el('div', { class: 'form-actions' }, submit),
    ]);

    let selected = null;
    let busy = false;

    const fail = (message) => {
        selected = null;
        submit.disabled = true;
        input.setAttribute('aria-invalid', 'true');
        errorNode.textContent = message;
        errorNode.hidden = false;
        preview.hidden = true;
        preview.removeAttribute('src');
        selectedName.textContent = '';
    };

    const resetError = () => {
        input.setAttribute('aria-invalid', 'false');
        errorNode.textContent = '';
        errorNode.hidden = true;
    };

    input.addEventListener('change', () => {
        resetError();
        selected = null;
        submit.disabled = true;
        selectedName.textContent = '';
        preview.hidden = true;
        preview.removeAttribute('src');

        const file = input.files && input.files[0] ? input.files[0] : null;

        if (!file) {
            return;
        }

        if (!ALLOWED_AVATAR_TYPES.includes(file.type)) {
            fail('El archivo debe ser JPG, PNG o WebP.');
            input.value = '';

            return;
        }

        if (file.size > MAX_AVATAR_BYTES) {
            fail('La imagen supera los 2 MB permitidos.');
            input.value = '';

            return;
        }

        selected = file;
        selectedName.textContent = `Archivo elegido: ${file.name}`;
        submit.disabled = false;

        const reader = new FileReader();

        reader.addEventListener('load', () => {
            const safe = typeof reader.result === 'string' ? safeUrl(reader.result) : null;

            if (safe !== null) {
                preview.src = safe;
                preview.hidden = false;
            }
        });
        reader.readAsDataURL(file);
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (busy || !selected) {
            return;
        }

        busy = true;
        submit.disabled = true;
        submit.textContent = 'Subiendo…';
        form.setAttribute('aria-busy', 'true');
        resetError();

        try {
            const data = new FormData();
            data.append('avatar', selected, selected.name);
            const response = await api('/me/avatar', { method: 'POST', form: data });

            input.value = '';
            selected = null;
            selectedName.textContent = 'Avatar actualizado.';
            preview.hidden = true;
            preview.removeAttribute('src');
            onUpdated(response.data);
        } catch (error) {
            const message = error && error.fields && error.fields.avatar
                ? error.fields.avatar
                : (error && error.message ? error.message : 'No se pudo subir el avatar.');
            const reference = error && error.requestId ? ` Referencia: ${error.requestId}` : '';
            fail(`${message}${reference}`);
        } finally {
            busy = false;
            submit.textContent = 'Subir avatar';
            submit.disabled = !selected;
            form.removeAttribute('aria-busy');
        }
    });

    return form;
}
