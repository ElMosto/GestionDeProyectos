<?php

declare(strict_types=1);

namespace App\Repositories;

/** §11 / D-28,D-29: evidencia inmutable, en la transacción de la auditoría. */
final class CommercialHistoryRepository extends ProjectStorage
{
    public function capture(int $auditId, string $action, string $entityType, int $entityId): void
    {
        if ($entityType === 'project_charge') {
            $charge = $this->one('SELECT project_id FROM project_charges WHERE id=?', [$entityId]);
            if ($charge === null) {
                return;
            }
            $entityType = 'project';
            $entityId = (int) $charge['project_id'];
        }
        if (!in_array($entityType, ['project', 'sale'], true)) {
            return;
        }
        if ($entityType === 'project') {
            $row = $this->one("SELECT p.base_amount+COALESCE((SELECT SUM(c.amount) FROM project_charges c WHERE c.project_id=p.id AND c.status='ACTIVO'),0.00) AS total,
                p.billable,p.type AS source_type,p.client_id,p.leader_id AS responsible_id,p.currency,
                (p.status='CANCELADO') AS cancelled FROM projects p WHERE p.id=?", [$entityId]);
        } else {
            $row = $this->one("SELECT total,1 AS billable,type AS source_type,client_id,responsible_id,currency,
                (status='CANCELADA') AS cancelled,status,issued_at FROM sales WHERE id=?", [$entityId]);
            if ($row !== null && ($row['status'] === 'BORRADOR' || $row['issued_at'] === null)) {
                return;
            }
        }
        if ($row === null) {
            return;
        }
        $column = $entityType === 'project' ? 'project_id' : 'sale_id';
        $last = $this->one('SELECT * FROM commercial_document_events WHERE ' . $column . '=? ORDER BY occurred_at DESC,id DESC LIMIT 1', [$entityId]);
        $fields = ['total','billable','source_type','client_id','responsible_id','currency','cancelled'];
        if ($last !== null) {
            $same = true;
            foreach ($fields as $field) {
                if ((string) $last[$field] !== (string) $row[$field]) {
                    $same = false;
                }
            }
            if ($same) {
                return;
            }
        }
        $audit = $this->one('SELECT created_at FROM audit_logs WHERE id=?', [$auditId]);
        if ($audit === null) {
            throw new \LogicException('La captura requiere auditoría persistida.');
        }
        $explicit = ($entityType === 'project' && $action === 'PROJECT_CREATE')
            || ($entityType === 'sale' && $action === 'SALE_ISSUE');
        $kind = $last === null ? ($explicit ? 'ISSUE' : 'BASELINE')
            : ((int) $last['cancelled'] !== (int) $row['cancelled'] ? ((int) $row['cancelled'] === 1 ? 'CANCEL' : 'REOPEN') : 'CHANGE');
        $issued = $last['issued_at'] ?? ($explicit ? $audit['created_at'] : null);
        $this->execute('INSERT INTO commercial_document_events (' . $column . ',audit_log_id,kind,occurred_at,issued_at,total,billable,source_type,client_id,responsible_id,currency,cancelled) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [$entityId,$auditId,$kind,$audit['created_at'],$issued,...array_map(static fn ($f) => $row[$f], $fields)]);
    }
}
