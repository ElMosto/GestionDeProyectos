import { api, downloadFile, el } from '../api.js';
import { dataTable, emptyState, errorState, field, formatDateTime, loadingState, mount, notice, pageHeader } from '../ui.js';
import { PROJECT_STATUSES, workLabel, workOptions } from '../data.js';
import { button, cap, completeCollection, dateField, dateText, directory, editDialog, finance, idPath, loadSection, manages, moneyText, pagedList, roleCode, selectField } from '../project-ui.js';
import { chargeForm, projectForm, stageForm } from '../project-forms.js';
import { taskDetail, taskForm, taskTable } from '../task-ui.js';
import { renderPayments } from '../payment-ui.js';

export async function renderProyecto(root, ctx, id) {
    let active = 'Resumen';
    let generation = 0;
    const path = `/projects/${idPath(id)}`;
    const reload = async () => {
        const ticket = ++generation;
        mount(root, loadingState('Cargando proyecto…'));
        try {
            const { data: project } = await api(path);
            if (ticket !== generation) return;
            paint(project);
        } catch (error) { if (ticket === generation) mount(root, errorState({ error, onRetry: reload })); }
    };

    function paint(p) {
        const manager = manages(ctx, p);
        const canFinance = finance(ctx, p);
        const content = el('div', { class: 'stack', 'aria-live': 'polite' });
        const navigation = el('nav', { class: 'project-tabs', 'aria-label': 'Secciones del proyecto' });
        const sections = ['Resumen', 'Etapas y tareas', 'Integrantes', ...(canFinance ? ['Finanzas'] : [])];
        if (!sections.includes(active)) active = 'Resumen';
        let sectionGeneration = 0;
        const select = async (name) => {
            active = name;
            const ticket = ++sectionGeneration;
            navigation.querySelectorAll('button').forEach((item) => item.setAttribute('aria-pressed', String(item.textContent === name)));
            const section = el('section', { class: 'stack', 'aria-label': name });
            mount(content, section);
            if (name === 'Resumen') summary(section, p, manager);
            if (name === 'Etapas y tareas') await stages(section, p, manager);
            if (name === 'Integrantes') await members(section, p, manager);
            if (name === 'Finanzas' && canFinance) await finances(section, p, manager);
            // Each section owns its node; late network completions cannot replace another tab.
            if (ticket !== sectionGeneration) return;
        };
        sections.forEach((name) => navigation.append(button(name, () => { void select(name); })));
        mount(root, el('article', { class: 'stack' }, [
            el('p', {}, el('a', { href: '#/proyectos' }, '← Volver a proyectos')),
            pageHeader({ title: p.name, lead: `${p.folio ?? ''} · ${workLabel(p.type)} · ${workLabel(p.status)}` }),
            el('div', { class: 'form-actions' }, [
                cap(ctx, 'projects.export') ? button('Imprimir proyecto', () => editDialog({
                    title: 'Exportar Gantt XLSX',
                    fields: [dateField('from', 'Desde (opcional)'), dateField('to', 'Hasta (opcional)'),
                        notice({ message: 'Sin rango se exporta el proyecto completo. Para proyectos extensos, divide por periodos. Las tareas no programadas permanecen identificadas.' })],
                    save: async (values) => {
                        const query = new URLSearchParams();
                        if (values.from) query.set('from', values.from);
                        if (values.to) query.set('to', values.to);
                        await downloadFile(`${path}/gantt.xlsx${query.size ? `?${query}` : ''}`);
                        return { data: null };
                    }, done: () => {}, submitLabel: 'Descargar XLSX', toastMessage: 'Archivo descargado.',
                })) : null,
                canFinance && cap(ctx, 'projects.ticket') ? el('a', { class: 'btn btn-secondary', href: `/api/v1${path}/ticket?paper=80mm`, target: '_blank', rel: 'noopener noreferrer' }, 'Imprimir ticket') : null,
                canFinance && cap(ctx, 'projects.ticket') ? el('a', { class: 'btn btn-secondary', href: `/api/v1${path}/ticket?paper=A4`, target: '_blank', rel: 'noopener noreferrer' }, 'Ticket A4') : null,
            ]),
            el('p', { class: 'help', text: 'La impresión es una lectura: no registra pagos. La exportación ajusta la escala al rango; si el servidor indica un límite, consulta administración para exportar un periodo acotado.' }),
            navigation, content,
        ]));
        void select(active);
    }

    function summary(section, p, manager) {
        const actions = el('div', { class: 'form-actions' });
        const feedback = el('div');
        if (manager) {
            actions.append(button('Editar proyecto', () => {
                void projectForm(ctx, p, reload).catch((error) => mount(feedback, errorState({ error, onRetry: () => { void reload(); } })));
            }), button('Cambiar estado', () => transition(p)));
        }
        const details = [
            ['Descripción', p.description], ['Líder', p.leader?.name ?? p.leader_name ?? (p.leader_id ? `Usuario ${p.leader_id}` : '—')],
            ['Cliente', p.client?.name ?? p.client_name ?? (p.client_id ? `Cliente ${p.client_id}` : 'Sin cliente')], ['Prioridad', workLabel(p.priority)],
            ['Inicio previsto', dateText(p.planned_start)], ['Fin previsto', dateText(p.planned_end)],
            ['Inicio real', dateText(p.actual_start)], ['Fin real', dateText(p.actual_end)], ['Notas', p.notes ?? '—'],
        ];
        mount(section, [el('h2', { text: 'Resumen' }),
            notice({ title: `Avance operativo: ${p.progress ?? 0}%`, message: p.has_tasks === false ? 'Sin tareas. Este avance no representa el estado financiero.' : 'Avance informado por el servidor; no representa el estado de pago.' }),
            el('dl', { class: 'project-facts' }, details.flatMap(([label, value]) => [el('dt', { text: label }), el('dd', { text: value || '—' })])), actions, feedback,
        ]);
    }

    function transition(p) {
        const status = selectField('status', 'Nuevo estado', workOptions(PROJECT_STATUSES), p.status, true);
        const reason = field({ name: 'reason', label: 'Motivo / excepción administrativa', type: 'textarea', help: 'Documenta la cancelación o la excepción al finalizar con tareas obligatorias pendientes. El servidor valida quién puede realizarla.' });
        const exception = field({ name: 'exception', label: 'Aplicar excepción administrativa al finalizar con tareas obligatorias pendientes', type: 'checkbox', value: 'true' });
        const sync = () => { reason.control.required = status.control.value === 'CANCELADO' || (['FINALIZADO', 'CANCELADO'].includes(p.status) && status.control.value === 'EN_CURSO') || exception.control.checked; };
        exception.control.addEventListener('change', sync);
        status.control.addEventListener('change', sync); sync();
        editDialog({ title: 'Confirmar cambio de estado del proyecto', fields: [
            el('p', { text: `${p.name}. Estado actual: ${workLabel(p.status)}. Finalizar el trabajo no liquida pagos; cancelar conserva el historial.` }),
            status, ...(cap(ctx, 'projects.manageAny') ? [exception] : []), reason, field({ name: 'confirmation', label: 'Confirmo el cambio indicado', type: 'checkbox', required: true }),
        ], transform: (v) => ({ status: v.status, version: p.version, ...(v.reason.trim() ? { reason: v.reason.trim() } : {}), ...(v.exception ? { exception: true } : {}) }),
        save: (body) => api(`${path}/transitions`, { method: 'POST', body, version: p.version }), done: reload });
    }

    async function stages(section, p, manager) {
        await loadSection(section, () => completeCollection(`${path}/stages`), (rows) => {
            const status = el('div');
            const list = el('div', { class: 'stack' });
            const create = manager ? button('Nueva etapa', () => stageForm(p, null, reload), true) : null;
            rows.forEach((stage, index) => {
                const taskRoot = el('div');
                const actions = el('div', { class: 'form-actions' });
                const feedback = el('div');
                if (manager) {
                    const move = async (delta) => {
                        const order = rows.map((s) => String(s.id));
                        [order[index], order[index + delta]] = [order[index + delta], order[index]];
                        actions.querySelectorAll('button').forEach((b) => { b.disabled = true; });
                        try {
                            await api(`${path}/stages/reorder`, { method: 'POST', version: p.version, body: { stage_ids: order, version: p.version } });
                            await reload();
                        } catch (error) {
                            mount(feedback, errorState({ error, title: error.status === 409 ? 'El proyecto cambió. Recarga antes de reordenar.' : 'No se pudo reordenar', onRetry: reload }));
                            actions.querySelectorAll('button').forEach((b) => { b.disabled = false; });
                            up.disabled = index === 0; down.disabled = index === rows.length - 1;
                        }
                    };
                    const up = button('Mover arriba', () => { void move(-1); }); up.disabled = index === 0;
                    const down = button('Mover abajo', () => { void move(1); }); down.disabled = index === rows.length - 1;
                    actions.append(button('Editar etapa', () => stageForm(p, stage, reload)), up, down, button('Nueva tarea', async () => {
                        try { const users = await taskAssignees(); await taskForm(ctx, { stage, users, done: reload }); }
                        catch (error) { mount(feedback, errorState({ error, onRetry: reload })); }
                    }, true));
                }
                list.append(el('section', { class: 'work-card stack' }, [
                    el('h3', { text: stage.name }), el('p', { text: stage.description ?? '' }),
                    el('p', { class: 'help', text: `${dateText(stage.start_date)} — ${dateText(stage.end_date)} · Avance operativo: ${stage.progress ?? 0}%${stage.has_tasks === false ? ' · Sin tareas' : ''}` }),
                    actions, feedback, taskRoot,
                ]));
                const taskList = pagedList(taskRoot, `/stages/${idPath(stage.id)}/tasks`, {}, (data) => taskTable(data, (task) => {
                    // Read-only member views do not need the global directory.
                    if (!manager) { taskDetail(ctx, task, { done: reload }); return; }
                    void taskAssignees().then((users) => taskDetail(ctx, task, { manager, stage, users, done: reload })).catch((error) => mount(feedback, errorState({ error, onRetry: reload })));
                }), 'tareas');
                void taskList.load();
            });
            return el('div', { class: 'stack' }, [el('h2', { text: 'Etapas y tareas' }), create, status, rows.length ? list : emptyState({ title: 'Sin etapas', message: 'Crea una etapa para organizar las tareas del proyecto.' })]);
        });
    }

    async function taskAssignees() {
        const [membership, users] = await Promise.all([completeCollection(`${path}/members`), directory()]);
        const ids = new Set(membership.map((m) => String(typeof m === 'object' ? m.user_id ?? m.id ?? m.user?.id : m)));
        return users.filter((u) => ids.has(String(u.id)) && roleCode(u) === 'COLABORADOR');
    }

    async function members(section, p, manager) {
        await loadSection(section, async () => {
            const data = await completeCollection(`${path}/members`);
            // Directory only supplies public names; backend still validates full membership writes.
            return { data, users: await directory() };
        }, ({ data, users }) => {
            const ids = new Set(data.map((m) => String(typeof m === 'object' ? m.user_id ?? m.id ?? m.user?.id : m)));
            const names = new Map(users.map((u) => [String(u.id), u.name]));
            data.forEach((m) => { if (typeof m === 'object' && m.name) names.set(String(m.user_id ?? m.id ?? m.user?.id), m.name); });
            const choices = [...users, ...[...ids].filter((memberId) => !users.some((u) => String(u.id) === memberId)).map((memberId) => ({ id: memberId, name: `${names.get(memberId) ?? `Usuario ${memberId}`} (fuera del directorio activo)` }))];
            const edit = manager ? button('Editar integrantes', () => editDialog({
                title: 'Integrantes del proyecto',
                fields: [el('p', { text: 'Selecciona la lista completa de integrantes. Esto no modifica roles globales ni concede acceso financiero.' }),
                    ...choices.map((u) => {
                        const input = field({ name: 'user_ids', label: `${u.name} (${roleCode(u) === 'COLABORADOR' ? 'Colaborador' : 'Otro rol'})`, type: 'checkbox', value: String(u.id) });
                        input.control.checked = ids.has(String(u.id)); return input;
                    }),
                ], transform: (v, form) => ({ user_ids: new FormData(form).getAll('user_ids'), version: p.version }),
                save: (body) => api(`${path}/members`, { method: 'PUT', body, version: p.version }), done: reload,
            }), true) : null;
            return el('div', { class: 'stack' }, [el('h2', { text: 'Integrantes' }), edit,
                ids.size ? el('ul', { class: 'work-members' }, [...ids].map((memberId) => el('li', { text: names.get(memberId) ?? `Usuario ${memberId}` }))) : emptyState({ title: 'Sin integrantes' }),
            ]);
        });
    }

    async function finances(section, p, manager) {
        const charges = el('section', { class: 'stack', 'aria-label': 'Cargos adicionales' });
        const payments = el('section', { class: 'stack', 'aria-label': 'Pagos' });
        mount(section, [el('h2', { text: 'Finanzas del proyecto' }),
            notice({ title: 'Información comercial', message: 'Los importes y saldos se muestran tal como los informa el servidor. Los cargos no son pagos y los gastos internos no se incluyen aquí.' }),
            el('dl', { class: 'project-facts' }, [
                el('dt', { text: 'Importe base' }), el('dd', { text: moneyText(p.base_amount, p.currency) }),
                el('dt', { text: 'Total comercial' }), el('dd', { text: moneyText(p.financial_summary?.total, p.currency) }),
                el('dt', { text: 'Saldo' }), el('dd', { text: moneyText(p.financial_summary?.balance, p.currency) }),
                el('dt', { text: 'Neto cobrado' }), el('dd', { text: moneyText(p.financial_summary?.net_paid, p.currency) }),
                el('dt', { text: 'Condición' }), el('dd', { text: p.billable ? 'Cobrable' : 'No cobrable: valor estimado, no cuenta por cobrar' }),
                el('dt', { text: 'Estado de pago' }), el('dd', { text: p.billable ? workLabel(p.financial_summary?.payment_status) : 'No aplica: valor interno estimado' }),
            ]), charges, payments,
        ]);
        const edit = manager && p.type === 'INTERNO';
        const chargeRows = el('div', { class: 'stack' });
        mount(charges, [el('h3', { text: 'Cargos adicionales' }), edit ? button('Añadir cargo', () => chargeForm(p, null, reload), true) : null, chargeRows]);
        const chargeList = pagedList(chargeRows, `${path}/charges`, {}, (data) => dataTable({ caption: 'Cargos adicionales', rows: data, emptyTitle: 'Sin cargos adicionales', columns: [
                    { label: 'Concepto', render: (c) => c.concept }, { label: 'Cantidad', render: (c) => c.quantity },
                    { label: 'Precio unitario', render: (c) => moneyText(c.unit_price, p.currency) },
                    { label: 'Importe', render: (c) => moneyText(c.amount, p.currency) },
                    { label: 'Fecha', render: (c) => dateText(c.charge_date) },
                    ...(edit ? [{ label: 'Acciones', render: (c) => button('Editar cargo', () => chargeForm(p, c, reload)) }] : []),
                ] }), 'cargos');
        await Promise.all([chargeList.load(), renderPayments(payments, ctx, p, 'project', reload)]);
    }

    await reload();
}
