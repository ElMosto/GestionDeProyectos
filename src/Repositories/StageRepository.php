<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Validation\ProjectInput;

final class StageRepository extends ProjectStorage
{
    private const SELECT = 's.*, (SELECT COUNT(*) FROM tasks t WHERE t.stage_id=s.id AND t.status<>\'CANCELADA\') task_count,'
        . "(SELECT COUNT(*) FROM tasks t WHERE t.stage_id=s.id AND t.status='COMPLETADA') completed_tasks";

    public function find(int $id, bool $lock = false): ?array
    {
        return $this->one('SELECT ' . self::SELECT . ' FROM project_stages s WHERE s.id=?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
    }

    public function paginate(int $projectId, array $q): array
    {
        return $this->page(self::SELECT, 'FROM project_stages s', 's.project_id=?', [$projectId], 's.sort_order,s.id', $q);
    }

    public function create(int $projectId, array $fields, int $actor): int
    {
        $count = $this->one('SELECT COUNT(*) n FROM project_stages WHERE project_id=?', [$projectId]);
        return $this->insertRow('project_stages', [...$fields, 'project_id' => $projectId, 'sort_order' => (int) $count['n'], 'created_by' => $actor],
            [...array_keys(ProjectInput::STAGE), 'project_id', 'sort_order', 'created_by']);
    }

    public function update(int $id, array $fields): void
    {
        $this->changeRow('project_stages', $id, $fields, array_keys(ProjectInput::STAGE));
    }

    public function ids(int $projectId): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->rows('SELECT id FROM project_stages WHERE project_id=? ORDER BY id FOR UPDATE', [$projectId]));
    }

    public function reorder(array $ids): void
    {
        foreach ($ids as $position => $id) {
            $this->execute('UPDATE project_stages SET sort_order=?,version=version+1 WHERE id=?', [$position, $id]);
        }
    }

    public function taskDates(int $id): array
    {
        return $this->rows('SELECT start_date,end_date FROM tasks WHERE stage_id=?', [$id]);
    }

    public function incomplete(int $id): bool
    {
        return $this->one("SELECT id FROM tasks WHERE stage_id=? AND is_required=1 AND status<>'COMPLETADA' LIMIT 1", [$id]) !== null;
    }
}
