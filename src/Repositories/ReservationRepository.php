<?php

declare(strict_types=1);

namespace App\Repositories;

final class ReservationRepository extends ProjectStorage
{
    private const FROM = 'FROM reservations r JOIN space_occupancies o ON o.id=r.occupancy_id JOIN spaces s ON s.id=o.space_id JOIN clients c ON c.id=r.band_client_id';
    private const SELECT = 'r.*,o.space_id,o.start_at,o.end_at,s.name AS space_name,c.name AS band_name';

    public function find(int $id, bool $lock = false): ?array
    {
        if ($lock) {
            // No bloquear JOIN spaces: una reprogramación concurrente puede haber cambiado
            // el espacio desde la prelectura y no está permitido adquirir ese mutex tarde.
            $row = $this->one('SELECT * FROM reservations WHERE id=? FOR UPDATE', [$id]);
            if ($row === null) {
                return null;
            }
            $occupancy = $this->one('SELECT space_id,start_at,end_at FROM space_occupancies WHERE id=? FOR UPDATE', [$row['occupancy_id']]);
            $names = $this->one('SELECT s.name AS space_name,c.name AS band_name FROM spaces s JOIN clients c ON c.id=? WHERE s.id=?', [$row['band_client_id'], $occupancy['space_id']]);
            return $row + $occupancy + $names;
        }
        return $this->one('SELECT ' . self::SELECT . ' ' . self::FROM . ' WHERE r.id=?', [$id]);
    }

    public function paginate(array $q, int $actor, bool $admin): array
    {
        $where = ['1=1'];
        $params = [];
        if (!$admin) {
            $where[] = 'r.responsible_id=?';
            $params[] = $actor;
        }
        foreach (['status' => 'r.status', 'band_client_id' => 'r.band_client_id', 'space_id' => 'o.space_id'] as $key => $column) {
            if (isset($q[$key])) {
                $where[] = $column . '=?';
                $params[] = $q[$key];
            }
        }
        if (isset($q['from'])) {
            $where[] = 'o.end_at>?';
            $params[] = $q['from'];
        }
        if (isset($q['to'])) {
            $where[] = 'o.start_at<?';
            $params[] = $q['to'];
        }
        if (isset($q['q'])) {
            $where[] = '(c.name LIKE ? OR s.name LIKE ?)';
            $params[] = '%' . $q['q'] . '%';
            $params[] = '%' . $q['q'] . '%';
        }
        if (!empty($q['agenda'])) {
            $where[] = "r.status<>'CANCELLED'";
        }
        return $this->page(self::SELECT, self::FROM, implode(' AND ', $where), $params, 'o.start_at,r.id', $q);
    }

    public function band(int $id): ?array
    {
        return $this->one('SELECT id,type,active FROM clients WHERE id=? FOR UPDATE', [$id]);
    }

    public function create(array $fields): int
    {
        return $this->insertRow('reservations', $fields, ['occupancy_id', 'band_client_id', 'sale_id', 'responsible_id', 'hourly_rate_snapshot', 'total', 'notes', 'created_by']);
    }

    public function updateNotes(int $id, ?string $notes): void
    {
        $this->changeRow('reservations', $id, ['notes' => $notes], ['notes']);
    }

    public function reschedule(int $id, string $rate, string $total): void
    {
        $this->changeRow('reservations', $id, ['hourly_rate_snapshot' => $rate, 'total' => $total], ['hourly_rate_snapshot', 'total']);
    }

    public function transition(int $id, string $status): void
    {
        $this->changeRow('reservations', $id, ['status' => $status], ['status']);
    }
}
