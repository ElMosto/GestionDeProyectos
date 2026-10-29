import { api, el } from '../api.js';
import { applyFormError, clearFieldErrors, field, formAlert, formValues, loadingState, mount, errorState, notice, pageHeader, toast } from '../ui.js';
import { button } from '../project-ui.js';
import { adminAllowed } from '../admin-ui.js';

export async function renderConfiguracion(root, ctx) {
    if (!adminAllowed(root, ctx, 'settings.manage')) return;
    let disposed = false; let sequence = 0;
    ctx.onCleanup?.(() => { disposed = true; sequence++; });
    const load = async () => {
        const ticket = ++sequence; mount(root, loadingState('Cargando configuración…'));
        try {
            const { data } = await api('/settings');
            if (disposed || ticket !== sequence) return;
            const alert = formAlert();
            const name = field({ name: 'studio_name', label: 'Nombre del estudio', value: data.studio_name, required: true });
            const zone = field({ name: 'timezone', label: 'Zona horaria IANA', value: data.timezone, required: true, help: 'Por ejemplo America/Mexico_City. No se reinterpretan ocupaciones futuras al cambiarla.' });
            const submit = el('button', { type: 'submit', class: 'btn btn-primary' }, 'Guardar configuración');
            const form = el('form', { class: 'stack phase2-form' }, [alert, name, zone, field({ name: 'currency', label: 'Moneda (solo lectura)', value: data.currency, disabled: true }), submit]);
            mount(root, [pageHeader({ title: 'Configuración del estudio' }), notice({ message: 'Solo nombre y zona horaria. El nombre se utiliza en los comprobantes; esta pantalla no expone secretos ni rutas del servidor.' }), form]);
            let busy = false;
            form.addEventListener('submit', async (event) => {
                event.preventDefault(); if (busy || !form.reportValidity()) return;
                busy = true; submit.disabled = true; clearFieldErrors(form); alert.hidden = true;
                const values = formValues(form);
                try {
                    // Digest string: nunca convertir la versión a número.
                    await api('/settings', { method: 'PATCH', body: { studio_name: values.studio_name, timezone: values.timezone, expected_version: data.version } });
                    if (!disposed) { toast('Configuración guardada.', { tone: 'success' }); await load(); }
                } catch (error) {
                    if (disposed) return; applyFormError(error, form, alert);
                    if (error.status === 409) alert.append(el('p', { text: error.code === 'SETTINGS_TIMEZONE_IN_USE' ? 'Hay ocupaciones futuras: no se cambió su interpretación horaria.' : 'La configuración cambió. Recarga antes de guardar.' }), button('Recargar configuración', load));
                } finally { busy = false; submit.disabled = false; }
            });
        } catch (error) { if (!disposed && ticket === sequence) mount(root, errorState({ error, onRetry: load })); }
    };
    await load();
}
