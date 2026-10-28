import { api, el } from '../api.js';
import { dataTable, field, mount, pageHeader, statusBadge } from '../ui.js';
import { button, cap, idPath, moneyText, pagedList, selectField } from '../project-ui.js';
import { asyncAction, catalogForm, productExtras } from '../catalog-ui.js';

export function renderProductos(root, ctx) {
    if (!cap(ctx, 'catalog.view')) { mount(root, el('p', { role: 'alert', text: 'No tienes permiso para consultar el catálogo.' })); return; }
    let kind = 'products';
    const manager = cap(ctx, 'catalog.manage');
    const body = el('div', { class: 'stack' });
    const tabs = el('nav', { class: 'project-tabs', 'aria-label': 'Catálogos' });
    const paint = () => {
        tabs.querySelectorAll('button').forEach((b, index) => b.setAttribute('aria-pressed', String(kind === (index === 0 ? 'products' : 'extras'))));
        const feedback = el('div');
        const rows = el('div', { class: 'stack' });
        const filters = { q: '', active: manager ? '' : '1', type: '' };
        const query = field({ name: 'q', label: 'Buscar por nombre o SKU', type: 'search' });
        const active = selectField('active', 'Estado', [{ value: '', label: 'Todos' }, { value: '1', label: 'Activos' }, { value: '0', label: 'Inactivos' }]);
        const type = selectField('type', 'Tipo', [{ value: '', label: 'Todos' }, { value: 'PRODUCTO', label: 'Producto' }, { value: 'SERVICIO', label: 'Servicio' }]);
        const resource = kind;
        const listing = pagedList(rows, `/${resource}`, filters, (data) => dataTable({ caption: resource === 'products' ? 'Productos y servicios' : 'Extras', rows: data, emptyTitle: 'Sin resultados en el catálogo', columns: [
            { label: 'Nombre', render: (p) => p.name },
            ...(resource === 'products' ? [{ label: 'SKU / unidad', render: (p) => `${p.sku ?? 'Sin SKU'} · ${p.unit}` }, { label: 'Tipo', render: (p) => p.type === 'SERVICIO' ? 'Servicio' : 'Producto' }] : []),
            { label: 'Precio actual', render: (p) => moneyText(p.price, 'MXN') },
            { label: 'Estado', render: (p) => statusBadge(p.active) },
            ...(manager ? [{ label: 'Acciones', render: (p) => el('div', { class: 'form-actions' }, [
                button('Editar', asyncAction(feedback, async () => { const { data } = await api(`/${resource}/${idPath(p.id)}`); catalogForm(resource, data, listing.load); })),
                resource === 'products' ? button('Extras permitidos', asyncAction(feedback, () => productExtras(p, listing.load))) : null,
            ]) }] : []),
        ] }), resource === 'products' ? 'productos' : 'extras');
        const form = el('form', { class: 'filters' }, [query, manager ? active : null, resource === 'products' ? type : null, el('button', { type: 'submit', class: 'btn btn-secondary' }, 'Filtrar')]);
        form.addEventListener('submit', (event) => { event.preventDefault(); filters.q = query.control.value.trim(); filters.active = manager ? active.control.value : '1'; filters.type = resource === 'products' ? type.control.value : ''; void listing.reset(); });
        mount(body, [manager ? button(resource === 'products' ? 'Nuevo producto o servicio' : 'Nuevo extra', () => catalogForm(resource, null, listing.load), true) : null, form, feedback, rows]);
        void listing.load();
    };
    tabs.append(button('Productos y servicios', () => { kind = 'products'; paint(); }), button('Extras', () => { kind = 'extras'; paint(); }));
    mount(root, [pageHeader({ title: 'Productos y extras', lead: 'Precios vigentes del catálogo. Las ventas emitidas conservan sus precios históricos.' }), tabs, body]);
    paint();
}
