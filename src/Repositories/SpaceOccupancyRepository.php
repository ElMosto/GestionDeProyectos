<?php

declare(strict_types=1);

namespace App\Repositories;

final class SpaceOccupancyRepository extends ProjectStorage
{
    public function find(int $id, bool $lock = false): ?array
    {
        return $this->one('SELECT * FROM space_occupancies WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
    }

    /** Lectura actual, exclusivamente tras el mutex del espacio. */
    public function conflicts(int $space, string $start, string $end, ?int $exclude): array
    {
        return $this->rows("SELECT id FROM space_occupancies WHERE space_id=? AND status IN ('CONFIRMED','IN_PROGRESS') AND start_at<? AND end_at>? AND id<>? ORDER BY id FOR UPDATE",
            [$space, $end, $start, $exclude ?? 0]);
    }

    public function futureBlocking(int $space): array
    {
        return $this->rows("SELECT id FROM space_occupancies WHERE space_id=? AND status IN ('CONFIRMED','IN_PROGRESS') AND end_at>UTC_TIMESTAMP() ORDER BY id FOR UPDATE", [$space]);
    }

    public function calendar(int $space, string $from, string $to): array
    {
        return $this->rows("SELECT id,space_id,start_at,end_at,kind,status FROM space_occupancies WHERE space_id=? AND status IN ('CONFIRMED','IN_PROGRESS') AND start_at<? AND end_at>? ORDER BY start_at,id LIMIT 5001", [$space, $to, $from]);
    }

    public function create(array $fields): int
    {
        return $this->insertRow('space_occupancies', $fields, ['space_id', 'start_at', 'end_at', 'kind', 'status', 'created_by']);
    }

    public function reschedule(int $id, int $space, string $start, string $end): void
    {
        $this->changeRow('space_occupancies', $id, ['space_id' => $space, 'start_at' => $start, 'end_at' => $end], ['space_id', 'start_at', 'end_at']);
    }

    public function transition(int $id, string $status): void
    {
        $this->changeRow('space_occupancies', $id, ['status' => $status], ['status']);
    }
}
