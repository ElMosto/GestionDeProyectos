import { el } from '../api.js';
import { formatDateTime, loadingState, mount, notice, pageHeader } from '../ui.js';
import { adminAllowed, documentLink } from '../admin-ui.js';
import { reportFilters, reportList } from '../finance-ui.js';

export async function renderAuditoria(root, ctx) {
    if (!adminAllowed(root, ctx, 'audit.view')) return;
    let disposed = false; let current;
    ctx.onCleanup?.(() => { disposed = true; current?.dispose(); });
    mount(root, loadingState('Cargando auditoría…'));
    const results = el('div');
    const controls = await reportFilters(ctx, { audit: true, onApply: (values) => load(values) });
    if (disposed) return;
    function load(filters) {
        current?.dispose();
        current = reportList(results, '/audit-logs', filters, 'Registro de auditoría', [
            { label: 'Fecha', render: (r) => formatDateTime(r.created_at, ctx.session.user.timezone) },
            { label: 'Actor', render: (r) => r.actor_id ? `Usuario ${r.actor_id}` : 'Sistema' },
            { label: 'Acción', render: (r) => r.action },
            { label: 'Tipo de entidad', render: (r) => r.entity_type },
            { label: 'Entidad', render: (r) => documentLink(r.entity_type, r.entity_id, r.entity_id ?? '—') },
            { label: 'Solicitud', render: (r) => r.request_id ?? '—' },
            { label: 'Dirección IP', render: (r) => r.ip_address ?? '—' },
        ], controls.showError);
        return current.load();
    }
    mount(root, [pageHeader({ title: 'Auditoría' }), notice({ message: 'Metadatos de trazabilidad. No se muestran copias arbitrarias de datos, notas ni contenidos privados de reuniones.' }), controls.form, results]);
    await load(controls.values());
}
