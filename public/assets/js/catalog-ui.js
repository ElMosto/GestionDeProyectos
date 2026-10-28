/** Catálogo (§10): precios literales y vínculos completos, nunca solo la primera página. */
import { api, el } from './api.js';
import { field, errorState, mount } from './ui.js';
import { completeCollection, editDialog, idPath, moneyText, selectField } from './project-ui.js';

export const optional = (value) => value?.trim() || null;
/** Normalización de forma exclusivamente: sin convertir cantidades/importes a Number. */
export function decimal(value, scale) {
    const raw = String(value).trim();
    if (!/^\d+(?:\.\d+)?$/.test(raw)) return raw;
    const [whole, fraction = ''] = raw.split('.');
    if (fraction.length > scale) return raw;
    return `${whole.replace(/^0+(?=\d)/, '')}.${fraction.padEnd(scale, '0')}`;
}
export const moneyField = (name, label, value = '', required = true) => field({ name, label, value, required, inputMode: 'decimal', pattern: '[0-9]+([.][0-9]{1,2})?', help: 'Importe decimal, hasta dos decimales. Ejemplo: 150.00.' });
export const quantityField = (name, label, value = '1.000') => field({ name, label, value, required: true, inputMode: 'decimal', pattern: '[0-9]+([.][0-9]{1,3})?', help: 'Cantidad positiva, hasta tres decimales.' });

export function catalogForm(kind, item, done) {
    const product = kind === 'products';
    const active = selectField('active', 'Estado', [{ value: '1', label: 'Activo' }, { value: '0', label: 'Inactivo' }], item?.active === false ? '0' : '1');
    return editDialog({ title: `${item ? 'Editar' : 'Crear'} ${product ? 'producto o servicio' : 'extra'}`, fields: [
        field({ name: 'name', label: 'Nombre', value: item?.name, required: true }),
        ...(product ? [field({ name: 'sku', label: 'SKU (opcional)', value: item?.sku }),
            field({ name: 'description', label: 'Descripción (opcional)', type: 'textarea', value: item?.description }),
            selectField('type', 'Tipo', [{ value: 'PRODUCTO', label: 'Producto' }, { value: 'SERVICIO', label: 'Servicio' }], item?.type ?? 'PRODUCTO'),
            field({ name: 'unit', label: 'Unidad', value: item?.unit ?? 'pieza', required: true })] : []),
        moneyField('price', 'Precio actual (MXN)', item?.price ?? ''), active,
    ], transform: (v) => ({ name: v.name.trim(), price: decimal(v.price, 2), active: v.active === '1',
        ...(product ? { sku: optional(v.sku), description: optional(v.description), type: v.type, unit: v.unit.trim() } : {}),
        ...(item ? { version: item.version } : {}),
    }), save: (body) => api(`/${kind}${item ? `/${idPath(item.id)}` : ''}`, { method: item ? 'PATCH' : 'POST', body, version: item?.version }), done });
}

export async function productExtras(item, done) {
    const [{ data: product }, extras] = await Promise.all([api(`/products/${idPath(item.id)}`), completeCollection('/extras')]);
    const selected = new Set(product.extra_ids.map(String));
    return editDialog({ title: `Extras permitidos: ${product.name}`, fields: [
        el('p', { text: 'Se guarda la selección completa. Los extras se cobran por unidad del producto; solo se admiten extras activos.' }),
        ...extras.map((extra) => {
            const row = field({ name: 'extra_ids', label: `${extra.name} · ${moneyText(extra.price, 'MXN')}${extra.active ? '' : ' · Inactivo (quitar para guardar)'}`, type: 'checkbox', value: String(extra.id) });
            row.control.checked = selected.has(String(extra.id));
            return row;
        }),
        !extras.length ? el('p', { text: 'Sin extras en el catálogo.' }) : null,
    ], transform: (_v, form) => ({ version: product.version, extra_ids: new FormData(form).getAll('extra_ids') }),
    save: (body) => api(`/products/${idPath(item.id)}/extras`, { method: 'PUT', body, version: product.version }), done });
}

export function asyncAction(root, action) {
    return async () => {
        try { await action(); }
        catch (error) { mount(root, errorState({ error, onRetry: asyncAction(root, action) })); }
    };
}
