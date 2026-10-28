/** Fase 2: presentación compartida, sin autoridad ni aritmética monetaria (§5, §8, §17). */
import { api, el } from './api.js';
import { applyFormError, clearFieldErrors, errorState, field, formAlert, formValues, loadingState, mount, openDialog, toast, pagination } from './ui.js';
import { PAGE_SIZE } from './data.js';

export const button = (label, action, primary = false) => el('button', { type: 'button', class: `btn ${primary ? 'btn-primary' : 'btn-secondary'}`, onClick: action }, label);
export const cap = (ctx, key) => ctx.session?.capabilities?.[key] === true;
export const owns = (ctx, project) => String(project.leader_id ?? project.leader?.id) === String(ctx.session.user.id);
export const manages = (ctx, project) => cap(ctx, 'projects.manageAny') || (cap(ctx, 'projects.manageOwn') && owns(ctx, project));
export const finance = (ctx, project) => cap(ctx, 'projects.viewFinanceAny') || (cap(ctx, 'projects.viewFinanceOwn') && owns(ctx, project));
export const roleCode = (user) => typeof user.role === 'string' ? user.role : user.role?.code;
export const idPath = (value) => encodeURIComponent(String(value));
export const dateText = (value) => typeof value === 'string' && value ? value.slice(0, 10) : '—';
export const moneyText = (value, currency = '') => typeof value === 'string' ? `${value}${currency ? ` ${currency}` : ''}` : '—';
export const dateField = (name, label, value = '') => field({ name, label, type: 'date', value: value?.slice(0, 10) ?? '' });
export const selectField = (name, label, options, value = '', required = false) => field({ name, label, type: 'select', options, value, required });
export const userOptions = (users) => [{ value: '', label: 'Selecciona una persona' }, ...users.map((u) => ({ value: String(u.id), label: u.name }))];
export async function directory() { return (await api('/users/directory')).data; }

/** Complete snapshots are necessary for replacement/reorder commands, never just page 1. */
export async function completeCollection(path) {
    const rows = [];
    let offset = 0;
    while (true) {
        const { data, meta } = await api(`${path}${path.includes('?') ? '&' : '?'}limit=100&offset=${offset}`);
        rows.push(...data);
        if (!meta || !Number.isFinite(meta.total) || rows.length >= meta.total) return rows;
        if (data.length === 0) throw new Error('La lista cambió mientras se cargaba. Recarga para obtener una lista completa.');
        offset += data.length;
    }
}

export async function loadSection(root, load, paint) {
    mount(root, loadingState());
    try { const value = await load(); mount(root, await paint(value)); }
    catch (error) { mount(root, errorState({ error, onRetry: () => { void loadSection(root, load, paint); } })); }
}

/** Dialog owns its request and keeps user input on validation/network failures. */
export function editDialog({ title, fields, save, done, submitLabel = 'Guardar', transform = (v) => v }) {
    const alert = formAlert();
    const form = el('form', { class: 'stack phase2-form' }, [alert, ...fields]);
    let busy = false;
    const submit = el('button', { type: 'submit', class: 'btn btn-primary' }, submitLabel);
    const cancel = button('Cancelar', () => { if (!busy) dialog.close(); });
    form.append(el('div', { class: 'form-actions' }, [cancel, submit]));
    const dialog = openDialog({ title, content: form });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        clearFieldErrors(form); alert.hidden = true;
        busy = true; submit.disabled = true; cancel.disabled = true;
        try {
            const response = await save(transform(formValues(form), form));
            dialog.close(); toast('Cambios guardados.', { tone: 'success' });
            if (done) await done(response);
        } catch (error) {
            applyFormError(error, form, alert);
            if (error.status === 409) alert.append(el('p', { text: 'Los datos cambiaron o la operación no es válida. Cierra y recarga antes de volver a enviar.' }), button('Cerrar y recargar', () => { dialog.close(); void done?.(); }));
        } finally { busy = false; submit.disabled = false; cancel.disabled = false; }
    });
    return dialog;
}

export function pagedList(root, path, filters, paint, label) {
    let offset = 0;
    let sequence = 0;
    const load = async () => {
        const ticket = ++sequence;
        mount(root, loadingState());
        const query = new URLSearchParams({ limit: String(PAGE_SIZE), offset: String(offset) });
        for (const [key, value] of Object.entries(filters)) if (value) query.set(key, value);
        try {
            const result = await api(`${path}?${query}`);
            if (ticket !== sequence) return;
            mount(root, [paint(result.data), pagination({ total: result.meta.total, limit: PAGE_SIZE, offset, label, onChange: (value) => { offset = value; void load(); } })]);
        } catch (error) { if (ticket === sequence) mount(root, errorState({ error, onRetry: () => { void load(); } })); }
    };
    return { load, reset: () => { offset = 0; return load(); } };
}

/** Paged client selector, never downloads the entire client catalogue. */
export function clientPicker(value = '', label = '') {
    const choice = selectField('client_id', 'Cliente', [{ value: '', label: 'Sin cliente' }, ...(value ? [{ value: String(value), label: label || `Cliente ${value}` }] : [])], value);
    const search = field({ name: 'client_search', label: 'Buscar cliente activo', type: 'search' });
    const status = el('div', { 'aria-live': 'polite' });
    let offset = 0;
    let sequence = 0;
    let current = value ? { value: String(value), label: label || `Cliente ${value}` } : null;
    const load = async () => {
        const ticket = ++sequence;
        current = choice.control.value ? { value: choice.control.value, label: choice.control.selectedOptions[0].textContent } : current;
        mount(status, loadingState('Buscando clientes…'));
        try {
            const { data, meta } = await api(`/clients?${new URLSearchParams({ active: '1', q: search.control.value, limit: '25', offset: String(offset) })}`);
            if (ticket !== sequence) return;
            const options = [{ value: '', label: 'Sin cliente' }, ...data.map((c) => ({ value: String(c.id), label: c.name }))];
            if (current && !options.some((o) => o.value === current.value)) options.push(current);
            mount(choice.control, options.map((o) => el('option', { value: o.value }, o.label)));
            choice.control.value = current?.value ?? '';
            mount(status, pagination({ total: meta.total, limit: 25, offset, label: 'clientes', onChange: (next) => { offset = next; void load(); } }));
        } catch (error) { if (ticket === sequence) mount(status, errorState({ error, onRetry: () => { void load(); } })); }
    };
    choice.control.addEventListener('change', () => { current = choice.control.value ? { value: choice.control.value, label: choice.control.selectedOptions[0].textContent } : null; });
    const find = button('Buscar clientes', () => { offset = 0; void load(); });
    search.control.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); find.click(); } });
    const root = el('fieldset', { class: 'stack' }, [el('legend', { text: 'Cliente del proyecto' }), search, find, choice, status]);
    void load();
    return root;
}
