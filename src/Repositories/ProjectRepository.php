<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Validation\ProjectInput;

final class ProjectRepository extends ProjectStorage
{
    private const COUNTS = ", (SELECT COUNT(*) FROM tasks t JOIN project_stages s ON s.id=t.stage_id WHERE s.project_id=p.id AND t.status<>'CANCELADA') task_count"
        . ", (SELECT COUNT(*) FROM tasks t JOIN project_stages s ON s.id=t.stage_id WHERE s.project_id=p.id AND t.status='COMPLETADA') completed_tasks"
        . ", (SELECT COALESCE(SUM(c.amount),0) FROM project_charges c WHERE c.project_id=p.id AND c.status='ACTIVO') charges_total"
        . ", (SELECT COALESCE(SUM(m.amount),0) FROM payments m WHERE m.project_id=p.id AND m.status='REGISTRADO') paid_amount"
        . ", (SELECT COALESCE(SUM(r.amount),0) FROM refunds r JOIN payments m ON m.id=r.payment_id WHERE m.project_id=p.id AND m.status='REGISTRADO' AND r.status='REGISTRADO') refunded_amount";

    public function find(int $id, int $actorId, bool $lock = false): ?array
    {
        // Primero adquirir el mutex del documento. Evaluar agregados antes de
        // esperar ese lock podría observar un saldo/progreso anterior al commit.
        if ($lock && $this->one('SELECT id FROM projects WHERE id=? FOR UPDATE', [$id]) === null) {
            return null;
        }
        $row = $this->one('SELECT p.*, c.name AS client_name, u.name AS leader_name, EXISTS(SELECT 1 FROM project_members pm WHERE pm.project_id=p.id AND pm.user_id=?) is_member'
            . self::COUNTS . ' FROM projects p LEFT JOIN clients c ON c.id=p.client_id LEFT JOIN users u ON u.id=p.leader_id WHERE p.id=?', [$actorId, $id]);
        if ($row !== null) {
            $row['is_member'] = (bool) $row['is_member'];
        }
        return $row;
    }

    public function paginate(array $q, int $actorId, string $role): array
    {
        [$where, $params] = self::visibility($actorId, $role);
        foreach (['type', 'status', 'leader_id', 'client_id'] as $key) {
            if (isset($q[$key])) {
                $where .= ' AND p.' . $key . '=?';
                $params[] = $q[$key];
            }
        }
        if (isset($q['q'])) {
            $where .= ' AND (p.name LIKE ? OR p.folio LIKE ?)';
            array_push($params, '%' . $q['q'] . '%', '%' . $q['q'] . '%');
        }
        $page = $this->page('p.*, c.name AS client_name, u.name AS leader_name' . self::COUNTS, 'FROM projects p LEFT JOIN clients c ON c.id=p.client_id LEFT JOIN users u ON u.id=p.leader_id', $where, $params, 'p.id DESC', $q);
        return $page;
    }

    /** SQL mirror of the policy supplied by seguridad-permisos; never a payload flag. */
    public static function visibility(int $actorId, string $role): array
    {
        return match ($role) {
            'ADMINISTRADOR' => ['1=1', []],
            'LIDER_PROYECTO' => ['p.leader_id=?', [$actorId]],
            'COLABORADOR' => ['EXISTS(SELECT 1 FROM project_members pm WHERE pm.project_id=p.id AND pm.user_id=?)', [$actorId]],
            default => ['1=0', []],
        };
    }

    public function create(array $data): int
    {
        return $this->insertRow('projects', $data, [...array_keys(ProjectInput::PROJECT), 'folio', 'created_by']);
    }

    public function update(int $id, array $fields): void
    {
        $this->changeRow('projects', $id, $fields, array_keys(ProjectInput::PROJECT));
    }

    public function transition(int $id, string $status, ?string $start, ?string $end): void
    {
        $this->execute('UPDATE projects SET status=?,actual_start=?,actual_end=?,version=version+1 WHERE id=?', [$status, $start, $end, $id]);
    }

    public function bump(int $id): void
    {
        $this->execute('UPDATE projects SET version=version+1 WHERE id=?', [$id]);
    }

    public function user(int $id, bool $lock = true): ?array
    {
        return $this->one('SELECT u.id,u.active,r.code role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?'
            . ($lock ? ' FOR UPDATE' : ''), [$id]);
    }

    public function client(int $id): ?array
    {
        return $this->one('SELECT id,active FROM clients WHERE id=? FOR UPDATE', [$id]);
    }

    public function members(int $id, array $q): array
    {
        return $this->page('u.id user_id,u.name,r.code role,u.active,pm.created_at',
            'FROM project_members pm JOIN users u ON u.id=pm.user_id JOIN roles r ON r.id=u.role_id',
            'pm.project_id=?', [$id], 'u.name,u.id', $q);
    }

    public function memberIds(int $id): array
    {
        return array_map(static fn (array $r): int => (int) $r['user_id'], $this->rows('SELECT user_id FROM project_members WHERE project_id=? ORDER BY user_id FOR UPDATE', [$id]));
    }

    public function isMember(int $projectId, int $userId): bool
    {
        return $this->one('SELECT id FROM project_members WHERE project_id=? AND user_id=?', [$projectId, $userId]) !== null;
    }

    public function replaceMembers(int $id, array $ids, int $actor): void
    {
        $old = $this->memberIds($id);
        foreach (array_diff($old, $ids) as $userId) {
            $this->execute('DELETE FROM project_members WHERE project_id=? AND user_id=?', [$id, $userId]);
        }
        foreach (array_diff($ids, $old) as $userId) {
            $this->execute('INSERT INTO project_members(project_id,user_id,created_by) VALUES (?,?,?)', [$id, $userId, $actor]);
        }
        $this->bump($id);
    }

    public function hasAssignedTasks(int $projectId, int $userId): bool
    {
        return $this->one("SELECT t.id FROM tasks t JOIN project_stages s ON s.id=t.stage_id WHERE s.project_id=? AND t.assignee_id=? AND t.status<>'CANCELADA' LIMIT 1", [$projectId, $userId]) !== null;
    }

    public function incompleteRequired(int $id): bool
    {
        return $this->one("SELECT t.id FROM tasks t JOIN project_stages s ON s.id=t.stage_id WHERE s.project_id=? AND t.is_required=1 AND t.status<>'COMPLETADA' LIMIT 1", [$id]) !== null;
    }

    public function descendantDates(int $id): array
    {
        return $this->rows('SELECT start_date,end_date FROM project_stages WHERE project_id=? UNION ALL SELECT t.start_date,t.end_date FROM tasks t JOIN project_stages s ON s.id=t.stage_id WHERE s.project_id=?', [$id, $id]);
    }

    public function payments(int $id, array $q): array
    {
        return $this->page('m.id,m.folio,m.amount,m.effective_at,m.method,m.reference,m.notes,m.status,m.registered_by,m.voided_at,m.void_reason,m.version,'
            . "(SELECT COALESCE(SUM(r.amount),0) FROM refunds r WHERE r.payment_id=m.id AND r.status='REGISTRADO') refunded_amount",
            'FROM payments m', 'm.project_id=?', [$id], 'm.effective_at DESC,m.id DESC', $q);
    }
}
