<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Validation\ProjectInput;

final class TaskRepository extends ProjectStorage
{
    private const FROM = 'FROM tasks t LEFT JOIN project_stages s ON s.id=t.stage_id LEFT JOIN projects p ON p.id=s.project_id';
    private const SELECT = 't.*,s.project_id, (SELECT u.name FROM users u WHERE u.id=t.assignee_id) assignee_name';

    public function find(int $id, bool $lock = false): ?array
    {
        return $this->one('SELECT ' . self::SELECT . ' ' . self::FROM . ' WHERE t.id=?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
    }

    public function paginate(array $q, int $actorId, string $role, bool $myDefault = true): array
    {
        [$visible, $params] = ProjectRepository::visibility($actorId, $role);
        $where = "((t.stage_id IS NOT NULL AND ($visible)) OR (t.stage_id IS NULL AND "
            . ($role === 'ADMINISTRADOR' ? '1=1' : 't.assignee_id=?') . '))';
        if ($role !== 'ADMINISTRADOR') {
            $params[] = $actorId;
        }
        if ($myDefault && !isset($q['assignee_id']) && !isset($q['stage_id']) && !isset($q['project_id'])) {
            $q['assignee_id'] = $actorId;
        }
        foreach (['status' => 't.status', 'stage_id' => 't.stage_id', 'assignee_id' => 't.assignee_id', 'project_id' => 's.project_id'] as $key => $column) {
            if (isset($q[$key])) {
                $where .= ' AND ' . $column . '=?';
                $params[] = $q[$key];
            }
        }
        if (isset($q['standalone'])) {
            $where .= $q['standalone'] ? ' AND t.stage_id IS NULL' : ' AND t.stage_id IS NOT NULL';
        }
        if (isset($q['q'])) {
            $where .= ' AND t.title LIKE ?';
            $params[] = '%' . $q['q'] . '%';
        }
        if (isset($q['from'], $q['to'])) {
            $where .= " AND t.status<>'CANCELADA' AND COALESCE(t.start_date,t.end_date)<=? AND COALESCE(t.end_date,t.start_date)>=?";
            array_push($params, $q['to'], $q['from']);
        }
        return $this->page(self::SELECT, self::FROM, $where, $params, 't.end_date IS NULL,t.end_date,t.id', $q);
    }

    public function create(?int $stageId, array $fields, int $actorId): int
    {
        return $this->insertRow('tasks', [...$fields, 'stage_id' => $stageId, 'created_by' => $actorId],
            [...array_keys(ProjectInput::TASK), 'stage_id', 'created_by']);
    }

    public function update(int $id, array $fields): void
    {
        $this->changeRow('tasks', $id, $fields, array_keys(ProjectInput::TASK));
    }

    public function transition(int $id, string $status, int $progress, ?string $start, ?string $end): void
    {
        $this->execute('UPDATE tasks SET status=?,progress=?,actual_start=?,actual_end=?,version=version+1 WHERE id=?', [$status, $progress, $start, $end, $id]);
    }

    public function comments(int $id, array $q): array
    {
        return $this->page('c.id,c.task_id,c.author_id,u.name author_name,c.body,c.created_at',
            'FROM task_comments c JOIN users u ON u.id=c.author_id', 'c.task_id=?', [$id], 'c.created_at,c.id', $q);
    }

    public function comment(int $id, int $actorId, string $body): array
    {
        $this->execute('INSERT INTO task_comments(task_id,author_id,body) VALUES (?,?,?)', [$id, $actorId, $body]);
        return $this->one('SELECT c.id,c.task_id,c.author_id,u.name author_name,c.body,c.created_at FROM task_comments c JOIN users u ON u.id=c.author_id WHERE c.id=?', [(int) $this->db->pdo()->lastInsertId()]);
    }
}
