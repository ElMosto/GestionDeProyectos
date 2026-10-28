import { el } from '../api.js';
import { dataTable, field, formValues, mount, pageHeader, toast } from '../ui.js';
import { PROJECT_STATUSES, workLabel, workOptions } from '../data.js';
import { button, cap, dateText, idPath, pagedList, selectField } from '../project-ui.js';
import { projectForm } from '../project-forms.js';

export async function renderProyectos(root, ctx) {
    const filters = { q: '', type: '', status: '' };
    const results = el('div', { class: 'stack' });
    const form = el('form', { class: 'filters' }, [
        field({ name: 'q', label: 'Buscar proyecto', type: 'search' }),
        selectField('type', 'Tipo', [{ value: '', label: 'Todos' }, ...workOptions(['INTERNO', 'EXTERNO'])]),
        selectField('status', 'Estado', [{ value: '', label: 'Todos' }, ...workOptions(PROJECT_STATUSES)]),
        el('button', { type: 'submit', class: 'btn btn-secondary' }, 'Filtrar'),
    ]);
    const list = pagedList(results, '/projects', filters, (rows) => dataTable({ caption: 'Proyectos', rows, emptyTitle: 'No hay proyectos', emptyMessage: 'Prueba otros filtros o crea un proyecto si tienes permiso.', columns: [
        { label: 'Proyecto', render: (p) => el('a', { href: `#/proyectos/${idPath(p.id)}` }, p.name) },
        { label: 'Folio', render: (p) => p.folio ?? '—' },
        { label: 'Tipo', render: (p) => workLabel(p.type) },
        { label: 'Estado', render: (p) => workLabel(p.status) },
        { label: 'Inicio', render: (p) => dateText(p.planned_start) },
        { label: 'Fin', render: (p) => dateText(p.planned_end) },
    ] }), 'proyectos');
    const create = cap(ctx, 'projects.create') ? button('Nuevo proyecto', async () => {
        create.disabled = true;
        try { await projectForm(ctx, null, (response) => { if (response?.data?.id) ctx.navigate(`/proyectos/${idPath(response.data.id)}`); else void list.load(); }); }
        catch (error) { toast(error.message, { tone: 'error' }); }
        finally { create.disabled = false; }
    }, true) : null;
    mount(root, el('section', { class: 'stack' }, [pageHeader({ title: 'Proyectos', lead: 'Alcance y avance operativo de los proyectos a los que tienes acceso.', actions: create }), form, results]));
    form.addEventListener('submit', (event) => { event.preventDefault(); Object.assign(filters, formValues(form)); void list.reset(); });
    await list.load();
}
