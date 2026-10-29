<?php

declare(strict_types=1);

namespace App\Repositories;

/** Consultas acotadas para salidas; la política del proyecto se aplica antes de llamarlas. */
final class ProjectOutputRepository extends ProjectStorage
{
    public function people(int $projectId): array
    {
        return $this->one('SELECT u.id leader_id,u.name leader_name,c.id client_id,c.name client_name,c.type client_type,'
            . ' c.contact_name,c.phone FROM projects p JOIN users u ON u.id=p.leader_id'
            . ' LEFT JOIN clients c ON c.id=p.client_id WHERE p.id=?', [$projectId]) ?? [];
    }

    public function stages(int $projectId): array
    {
        return $this->rows('SELECT s.id,s.name,s.description,s.sort_order,s.start_date,s.end_date,s.status,'
            . " (SELECT COUNT(*) FROM tasks t WHERE t.stage_id=s.id AND t.status<>'CANCELADA') task_count,"
            . " (SELECT COUNT(*) FROM tasks t WHERE t.stage_id=s.id AND t.status='COMPLETADA') completed_tasks"
            . ' FROM project_stages s WHERE s.project_id=? ORDER BY s.sort_order,s.id LIMIT 1001', [$projectId]);
    }

    public function tasks(int $projectId): array
    {
        return $this->rows('SELECT t.id,t.stage_id,s.name stage_name,t.title,t.description,u.name assignee_name,'
            . ' t.start_date,t.end_date,t.status,t.progress,t.is_required FROM tasks t'
            . ' JOIN project_stages s ON s.id=t.stage_id JOIN users u ON u.id=t.assignee_id'
            . ' WHERE s.project_id=? ORDER BY s.sort_order,s.id,t.id LIMIT 10001', [$projectId]);
    }

    public function charges(int $projectId): array
    {
        return $this->rows('SELECT id,concept,quantity,unit_price,amount,charge_date,status'
            . ' FROM project_charges WHERE project_id=? ORDER BY charge_date,id LIMIT 10001', [$projectId]);
    }

    public function payments(int $projectId): array
    {
        return $this->rows('SELECT id,folio,amount,effective_at,method,status FROM payments'
            . ' WHERE project_id=? ORDER BY effective_at,id LIMIT 10001', [$projectId]);
    }

    public function refunds(int $projectId): array
    {
        return $this->rows('SELECT r.id,r.folio,r.amount,r.effective_at,r.status FROM refunds r'
            . ' JOIN payments p ON p.id=r.payment_id WHERE p.project_id=?'
            . ' ORDER BY r.effective_at,r.id LIMIT 10001', [$projectId]);
    }
}
