<?php

declare(strict_types=1);

namespace App\Repositories;

final class SpaceRepository extends ProjectStorage
{
    public function find(int $id, bool $lock = false): ?array
    {
        return $this->one('SELECT * FROM spaces WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
    }

    public function hours(int $id): array
    {
        return $this->rows('SELECT weekday,local_start_time,local_end_time FROM space_hours WHERE space_id=? ORDER BY weekday,local_start_time', [$id]);
    }

    public function paginate(array $q): array
    {
        $where = ['1=1'];
        $params = [];
        if (isset($q['q'])) {
            $where[] = 'name LIKE ?';
            $params[] = '%' . $q['q'] . '%';
        }
        if (isset($q['active'])) {
            $where[] = 'active=?';
            $params[] = $q['active'];
        }
        return $this->page('*', 'FROM spaces', implode(' AND ', $where), $params, 'name,id', $q);
    }

    public function nameExists(string $name, int $except = 0): bool
    {
        return $this->one('SELECT id FROM spaces WHERE name=? AND id<>? LIMIT 1', [$name, $except]) !== null;
    }

    public function create(array $fields): int
    {
        return $this->insertRow('spaces', $fields, ['name', 'description', 'hourly_rate', 'block_minutes', 'active', 'created_by']);
    }

    public function update(int $id, array $fields): void
    {
        $this->changeRow('spaces', $id, $fields, ['name', 'description', 'hourly_rate', 'block_minutes', 'active']);
    }

    public function replaceHours(int $id, array $hours): void
    {
        $this->execute('DELETE FROM space_hours WHERE space_id=?', [$id]);
        foreach ($hours as $hour) {
            $this->insertRow('space_hours', ['space_id' => $id] + $hour,
                ['space_id', 'weekday', 'local_start_time', 'local_end_time']);
        }
        $this->update($id, []);
    }
}
