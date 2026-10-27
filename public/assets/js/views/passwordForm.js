/**
 * Formulario de cambio de contraseña, compartido por «Mi usuario» y por la pantalla
 * obligatoria cuando `must_change_password = true` (§6).
 *
 * El servidor exige la contraseña actual, la longitud mínima configurada y que la nueva
 * sea distinta. Aquí solo se comprueba la forma (campos vacíos y confirmación).
 */

import { api, el } from '../api.js';
import {
    applyFormError,
    clearFieldErrors,
    field,
    formAlert,
    formValues,
    setFieldErrors,
    toast,
} from '../ui.js';

/**
 * @param {{setSession: (data: any) => void, navigate: (path: string, options?: {replace?: boolean}) => void}} ctx
 * @param {{forced?: boolean, onSuccess?: ((data: any) => void)|null}} [options]
 * @returns {HTMLElement}
 */
export function passwordForm(ctx, { forced = false, onSuccess = null } = {}) {
    const current = field({
        label: 'Contraseña actual',
        name: 'current_password',
        type: 'password',
        autocomplete: 'current-password',
        required: true,
    });
    const next = field({
        label: forced ? 'Contraseña nueva' : 'Nueva contraseña',
        name: 'new_password',
        type: 'password',
        autocomplete: 'new-password',
        required: true,
        help: 'Usa una frase larga y difícil de adivinar. La longitud mínima la valida el servidor.',
    });
    const confirmation = field({
        label: 'Repite la contraseña nueva',
        name: 'new_password_confirmation',
        type: 'password',
        autocomplete: 'new-password',
        required: true,
    });

    const alert = formAlert();
    const submit = el('button', { type: 'submit', class: 'btn btn-primary' }, forced
        ? 'Cambiar contraseña y continuar'
        : 'Cambiar contraseña');

    const form = el('form', { class: 'form-grid', novalidate: true }, [
        current,
        next,
        confirmation,
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

        if (!values.current_password) {
            localErrors.current_password = 'Escribe tu contraseña actual.';
        }

        if (!values.new_password) {
            localErrors.new_password = 'Escribe la contraseña nueva.';
        }

        if (!values.new_password_confirmation) {
            localErrors.new_password_confirmation = 'Repite la contraseña nueva.';
        } else if (values.new_password && values.new_password_confirmation !== values.new_password) {
            localErrors.new_password_confirmation = 'La confirmación no coincide.';
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
            const { data } = await api('/me/password', {
                method: 'POST',
                body: {
                    current_password: values.current_password,
                    new_password: values.new_password,
                    new_password_confirmation: values.new_password_confirmation,
                },
            });

            ctx.setSession(data);
            form.reset();
            toast('Contraseña actualizada. Se revocaron los dispositivos vinculados.', {
                tone: 'success',
            });

            if (typeof onSuccess === 'function') {
                onSuccess(data);
            }
        } catch (error) {
            applyFormError(error, form, alert);
        } finally {
            busy = false;
            submit.disabled = false;
            submit.textContent = forced ? 'Cambiar contraseña y continuar' : 'Cambiar contraseña';
            form.removeAttribute('aria-busy');
        }
    });

    return el('div', { class: 'password-form' }, form);
}
