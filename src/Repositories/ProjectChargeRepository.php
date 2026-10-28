<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Validation\ProjectInput;

final class ProjectChargeRepository extends ProjectStorage
{
    public function find(int $id, bool $lock = false): ?array
    {
        return $this->one('SELECT * FROM project_charges WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
    }

    public function paginate(int $projectId, array $q): array
    {
        return $this->page('c.*', 'FROM project_charges c', 'c.project_id=?', [$projectId], 'c.charge_date,c.id', $q);
    }

    public function create(int $projectId, array $data, int $actorId): int
    {
        return $this->insertRow('project_charges', [...$data, 'project_id' => $projectId, 'author_id' => $actorId, 'created_by' => $actorId],
            [...array_keys(ProjectInput::CHARGE), 'amount', 'project_id', 'author_id', 'created_by']);
    }

    public function update(int $id, array $fields): void
    {
        $this->changeRow('project_charges', $id, $fields, [...array_keys(ProjectInput::CHARGE), 'amount']);
    }
}
