/** Partidas de venta (§10). El servidor calcula el borrador y congela al emitir. */
import { api, el } from './api.js';
import { applyFormError, clearFieldErrors, field, formAlert, formValues, loadingState, mount, openDialog, toast } from './ui.js';
import { button, cap, clientPicker, completeCollection, idPath, moneyText, selectField } from './project-ui.js';
import { decimal, moneyField, optional, quantityField } from './catalog-ui.js';

export const saleOwner = (ctx, sale) => String(sale.responsible_id) === String(ctx.session.user.id);
export const editSale = (ctx, sale) => sale.type === 'ORDINARIA' && sale.status === 'BORRADOR' && (cap(ctx, 'sales.editDraftAny') || (cap(ctx, 'sales.editDraftOwn') && saleOwner(ctx, sale)));

export async function saleForm(ctx, sale, done) {
    const [products, extras] = await Promise.all([completeCollection('/products?active=1'), completeCollection('/extras?active=1')]);
    const alert = formAlert();
    const list = el('div', { class: 'stack sale-lines' });
    const form = el('form', { class: 'stack sale-form' }, [alert]);
    const entries = [];
    let busy = false;
    let loading = 0;
    const submit = el('button', { type: 'submit', class: 'btn btn-primary' }, 'Guardar borrador y ver total');
    const syncSubmit = () => { submit.disabled = busy || loading > 0; };
    const reindex = () => entries.forEach((row, index) => row.rename(index));

    function addLine(initial = null, free = false) {
        free = free || (initial !== null && initial.product_id === null);
        const index = entries.length;
        const box = el('fieldset', { class: 'work-card stack sale-line' }, [el('legend', { text: free ? 'Concepto libre (administrador)' : 'Partida de catálogo' })]);
        const quantity = quantityField(`items.${index}.quantity`, 'Cantidad del producto', initial?.quantity ?? '1.000');
        const extraRoot = el('div', { class: 'stack' });
        const quantityRows = [];
        let product = null;
        let freeName = null;
        let freePrice = null;
        let generation = 0;
        let failed = false;
        const row = {
            box,
            rename: (i) => {
                quantity.control.name = `items.${i}.quantity`;
                if (product) product.control.name = `items.${i}.product_id`;
                if (freeName) freeName.control.name = `items.${i}.name`;
                if (freePrice) freePrice.control.name = `items.${i}.unit_price`;
                let selected = 0;
                quantityRows.forEach((extra) => {
                    extra.check.control.name = extra.check.control.checked ? `items.${i}.extras.${selected}.extra_id` : `items.${i}.extra_choice.${extra.id}`;
                    extra.quantity.control.name = extra.check.control.checked ? `items.${i}.extras.${selected}.quantity_per_unit` : `items.${i}.extra_choice_quantity.${extra.id}`;
                    if (extra.check.control.checked) selected += 1;
                });
            },
            value: () => {
                if (failed) throw new Error('Recarga los extras de la partida antes de guardar.');
                return { product_id: free ? null : product.control.value, quantity: decimal(quantity.control.value, 3),
                    ...(free ? { name: freeName.control.value.trim(), unit_price: decimal(freePrice.control.value, 2) } : {}),
                    extras: quantityRows.filter((extra) => extra.check.control.checked).map((extra) => ({ extra_id: extra.id, quantity_per_unit: decimal(extra.quantity.control.value, 3) })),
                };
            },
        };
        async function loadExtras(preserve = false) {
            const ticket = ++generation;
            quantityRows.length = 0;
            failed = false;
            if (!product.control.value) { mount(extraRoot, el('p', { text: 'Selecciona un producto para ver sus extras permitidos.' })); return; }
            loading += 1; syncSubmit(); mount(extraRoot, loadingState('Cargando extras permitidos…'));
            try {
                const { data: detail } = await api(`/products/${idPath(product.control.value)}`);
                if (ticket !== generation) return;
                const allowed = new Set(detail.extra_ids.map(String));
                const rows = extras.filter((extra) => allowed.has(String(extra.id))).map((extra) => {
                    const previous = preserve ? initial?.extras?.find((e) => String(e.extra_id) === String(extra.id)) : null;
                    const check = field({ name: `extra-${extra.id}`, label: `${extra.name} · ${moneyText(extra.price, 'MXN')} por unidad`, type: 'checkbox', value: String(extra.id) });
                    check.control.checked = Boolean(previous);
                    const qty = quantityField(`extra-quantity-${extra.id}`, `Cantidad de ${extra.name} por unidad del producto`, previous?.quantity_per_unit ?? '1.000');
                    qty.control.disabled = !previous;
                    check.control.addEventListener('change', () => { qty.control.disabled = !check.control.checked; reindex(); });
                    quantityRows.push({ id: String(extra.id), check, quantity: qty });
                    return el('div', { class: 'sale-extra' }, [check, qty]);
                });
                const unavailable = preserve && initial?.extras?.some((old) => !quantityRows.some((extra) => extra.id === String(old.extra_id)));
                mount(extraRoot, [el('h3', { text: 'Extras por unidad de esta partida' }), ...rows,
                    !rows.length ? el('p', { text: 'Este producto no tiene extras activos permitidos.' }) : null,
                    unavailable ? field({ name: 'acknowledge_extras', label: 'Confirmo quitar los extras que ya no están disponibles en el catálogo', type: 'checkbox', required: true }) : null]);
                reindex();
            } catch (error) {
                if (ticket !== generation) return;
                failed = true;
                mount(extraRoot, [el('p', { role: 'alert', text: error.message }), button('Reintentar cargar extras', () => { void loadExtras(preserve); })]);
            } finally { loading -= 1; syncSubmit(); }
        }
        if (free) {
            freeName = field({ name: `items.${index}.name`, label: 'Concepto', value: initial?.name_snapshot, required: true });
            freePrice = moneyField(`items.${index}.unit_price`, 'Precio unitario (MXN)', initial?.unit_price ?? '');
            box.append(freeName, freePrice, quantity);
        } else {
            const options = [{ value: '', label: 'Selecciona un producto o servicio' }, ...products.map((p) => ({ value: String(p.id), label: `${p.name} · ${moneyText(p.price, 'MXN')} / ${p.unit}` }))];
            if (initial?.product_id && !products.some((p) => String(p.id) === String(initial.product_id))) options.push({ value: String(initial.product_id), label: `${initial.name_snapshot} (no disponible: reemplazar)` });
            product = selectField(`items.${index}.product_id`, 'Producto o servicio', options, initial?.product_id ?? '', true);
            product.control.addEventListener('change', () => { void loadExtras(); });
            box.append(product, quantity, extraRoot);
        }
        box.append(button('Quitar partida', () => { generation += 1; entries.splice(entries.indexOf(row), 1); box.remove(); reindex(); }));
        entries.push(row); list.append(box); reindex();
        if (product) void loadExtras(true);
    }

    const client = clientPicker(sale?.client_id ?? '', sale?.client?.name ?? '');
    client.querySelector('legend').textContent = 'Cliente de la venta (opcional)';
    form.append(el('p', { text: 'No se calcula dinero en el navegador. Guarda el borrador para consultar el total vigente del servidor; emitir no cobra. Si las unidades llevan extras distintos, crea partidas separadas.' }),
        client, field({ name: 'notes', label: 'Observaciones (opcional)', type: 'textarea', value: sale?.notes }), list,
        el('div', { class: 'form-actions' }, [button('Añadir producto', () => addLine()), cap(ctx, 'catalog.manage') ? button('Añadir concepto libre', () => addLine(null, true)) : null]),
        el('div', { class: 'form-actions' }, [submit]));
    const dialog = openDialog({ title: sale ? 'Editar borrador' : 'Nueva venta ordinaria', content: form });
    if (sale) sale.items.forEach((item) => addLine(item));
    else addLine();
    form.addEventListener('submit', async (event) => {
        event.preventDefault(); reindex();
        if (busy || loading || !form.reportValidity()) return;
        clearFieldErrors(form); alert.hidden = true; busy = true;
        // Freeze all controls while the exact payload is in flight.
        const enabled = [...form.querySelectorAll('input,select,textarea,button')].filter((node) => !node.disabled);
        try {
            const v = formValues(form);
            const body = { client_id: optional(v.client_id), notes: optional(v.notes), items: entries.map((entry) => entry.value()), ...(sale ? { version: sale.version } : {}) };
            enabled.forEach((node) => { node.disabled = true; });
            const response = await api(`/sales${sale ? `/${idPath(sale.id)}` : ''}`, { method: sale ? 'PATCH' : 'POST', body, version: sale?.version, idempotent: true });
            dialog.close(); toast('Borrador guardado. Revisa los totales antes de emitir.', { tone: 'success' }); await done(response);
        } catch (error) {
            enabled.forEach((node) => { node.disabled = false; });
            applyFormError(error, form, alert);
            if (error.status === 409) alert.append(button('Cerrar y recargar datos actuales', () => { dialog.close(); if (sale) ctx.navigate(`/ventas/${sale.id}`); else ctx.navigate('/ventas'); }));
        } finally { enabled.forEach((node) => { node.disabled = false; }); busy = false; syncSubmit(); }
    });
    return dialog;
}
