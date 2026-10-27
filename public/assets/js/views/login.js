/**
 * Acceso (`#/login`). El error de credenciales es siempre genérico: la interfaz no
 * revela si el correo existe, si la cuenta está activa o si la contraseña falló.
 */

import { ApiError, api, el } from '../api.js';
import {
    applyFormError,
    clearFieldErrors,
    field,
    formAlert,
    formValues,
    mount,
    notice,
    setFieldErrors,
    showFormError,
} from '../ui.js';

const GENERIC_CREDENTIALS_MESSAGE = 'El correo o la contraseña no son correctos.';

/**
 * @param {HTMLElement} container
 * @param {{onAuthenticated: (data: any) => void}} ctx
 */
export function renderLogin(container, ctx) {
    const titleId = 'login-title';
    const email = field({
        label: 'Correo electrónico',
        name: 'email',
        type: 'email',
        autocomplete: 'username',
        inputMode: 'email',
        maxLength: 180,
        required: true,
        placeholder: 'nombre@estudio.local',
    });
    const password = field({
        label: 'Contraseña',
        name: 'password',
        type: 'password',
        autocomplete: 'current-password',
        required: true,
    });
    const alert = formAlert();
    const submit = el('button', { type: 'submit', class: 'btn btn-primary btn-block' }, 'Entrar');

    const form = el('form', { class: 'form-grid login-form', novalidate: true }, [
        email,
        password,
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

        if (!values.email || values.email.trim() === '') {
            localErrors.email = 'Escribe tu correo electrónico.';
        }

        if (!values.password) {
            localErrors.password = 'Escribe tu contraseña.';
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
        submit.textContent = 'Entrando…';
        form.setAttribute('aria-busy', 'true');

        try {
            const { data } = await api('/auth/login', {
                method: 'POST',
                body: {
                    email: values.email.trim(),
                    password: values.password,
                },
            });

            ctx.onAuthenticated(data);
        } catch (error) {
            if (error instanceof ApiError) {
                if (error.status === 401) {
                    showFormError(alert, GENERIC_CREDENTIALS_MESSAGE);
                    alert.focus();
                } else if (error.status === 429) {
                    showFormError(
                        alert,
                        'Demasiados intentos fallidos. Espera unos minutos antes de volver a intentarlo.',
                    );
                    alert.focus();
                } else if (error.status === 422) {
                    applyFormError(error, form, alert);
                } else if (error.status === 403 && error.code === 'CSRF_INVALID') {
                    showFormError(
                        alert,
                        'La protección de la sesión caducó. Recarga la página e inténtalo de nuevo.',
                    );
                    alert.focus();
                } else {
                    applyFormError(error, form, alert);
                }
            } else {
                showFormError(alert, 'No se pudo conectar con el servidor. Revisa tu conexión e inténtalo de nuevo.');
                alert.focus();
            }
        } finally {
            busy = false;
            submit.disabled = false;
            submit.textContent = 'Entrar';
            form.removeAttribute('aria-busy');
            password.control.value = '';
        }
    });

    mount(container, el('section', { class: 'auth', 'aria-labelledby': titleId }, [
        el('div', { class: 'auth-card' }, [
            el('span', { class: 'auth-mark', 'aria-hidden': 'true', text: '♪' }),
            el('h1', { class: 'auth-title', id: titleId, tabindex: '-1', text: 'Inicia sesión' }),
            el('p', { class: 'auth-lead', text: 'Acceso privado del estudio. Pide tus credenciales al administrador.' }),
            form,
        ]),
        notice({
            tone: 'info',
            title: 'Sin registro público',
            message: 'Las cuentas las crea un administrador. Si olvidaste tu contraseña, pide un restablecimiento.',
        }),
    ]));

    email.control.focus();
}
