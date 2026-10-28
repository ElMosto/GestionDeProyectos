<?php

declare(strict_types=1);

namespace App\Repositories;

final class SpaceBlockRepository extends ProjectStorage
{
    public function find(int $id, bool $lock = false): ?array
    {
        if ($lock) {
            $row = $this->one('SELECT * FROM space_blocks WHERE id=? FOR UPDATE', [$id]);
            if ($row === null) {
                return null;
            }
            $occupancy = $this->one('SELECT space_id,start_at,end_at,status FROM space_occupancies WHERE id=? FOR UPDATE', [$row['occupancy_id']]);
            return $row + $occupancy;
        }
        return $this->one('SELECT b.*,o.space_id,o.start_at,o.end_at,o.status FROM space_blocks b JOIN space_occupancies o ON o.id=b.occupancy_id WHERE b.id=?', [$id]);
    }

    public function paginate(array $q): array
    {
        $where = ['1=1'];
        $params = [];
        if (isset($q['q'])) {
            $where[] = 'b.reason LIKE ?';
            $params[] = '%' . $q['q'] . '%';
        }
        foreach (['space_id', 'status'] as $key) {
            if (isset($q[$key])) {
                $where[] = 'o.' . $key . '=?';
                $params[] = $q[$key];
            }
        }
        foreach (['from' => 'o.end_at>?', 'to' => 'o.start_at<?'] as $key => $condition) {
            if (isset($q[$key])) {
                $where[] = $condition;
                $params[] = $q[$key];
            }
        }
        return $this->page('b.*,o.space_id,o.start_at,o.end_at,o.status',
            'FROM space_blocks b JOIN space_occupancies o ON o.id=b.occupancy_id', implode(' AND ', $where), $params, 'o.start_at,b.id', $q);
    }

    public function create(int $occupancy, string $reason, int $actor): int
    {
        return $this->insertRow('space_blocks', ['occupancy_id' => $occupancy, 'reason' => $reason, 'created_by' => $actor], ['occupancy_id', 'reason', 'created_by']);
    }

    public function cancel(int $id): void
    {
        $this->changeRow('space_blocks', $id, [], []);
    }
}
