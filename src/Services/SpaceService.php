<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Policies\Capabilities;
use App\Repositories\SpaceRepository;
use App\Repositories\SpaceOccupancyRepository;
use App\Support\Version;

final class SpaceService
{
    public function __construct(
        private readonly SpaceRepository $spaces,
        private readonly SpaceOccupancyRepository $occupancies,
        private readonly SpaceOccupancyService $occupancy,
        private readonly Database $db,
        private readonly Authorizer $authorizer,
        private readonly ProjectAudit $audit,
    ) {
    }

    public function list(array $query): array
    {
        $this->authorizer->requireUser();
        $page = $this->spaces->paginate($query);
        return ['data' => array_map(fn (array $row): array => $this->present($row, false), $page['rows']), 'meta' => $page['meta']];
    }

    public function show(int $id): array
    {
        $this->authorizer->requireUser();
        return $this->present($this->requireSpace($id));
    }

    public function create(array $fields): array
    {
        $this->authorizer->requireCapability(Capabilities::SPACES_MANAGE);
        return $this->db->transaction(function () use ($fields): array {
            $this->assertName($fields['name']);
            $id = $this->spaces->create($fields + ['created_by' => $this->authorizer->id()]);
            $this->audit->record('SPACE_CREATE', 'space', $id, null, $fields);
            return $this->show($id);
        });
    }

    public function update(int $id, array $fields, int $version): array
    {
        $this->authorizer->requireCapability(Capabilities::SPACES_MANAGE);
        return $this->db->transaction(function () use ($id, $fields, $version): array {
            $old = $this->occupancy->lockSpaces([$id])[$id];
            Version::assert($old, $version);
            if (isset($fields['name'])) {
                $this->assertName($fields['name'], $id);
            }
            if ((isset($fields['active']) && !$fields['active'])
                || (isset($fields['block_minutes']) && (int) $fields['block_minutes'] !== (int) $old['block_minutes'])) {
                $this->assertNoFuture($id);
            }
            $this->spaces->update($id, $fields);
            $this->audit->record('SPACE_UPDATE', 'space', $id, $this->present($old), $fields);
            return $this->show($id);
        });
    }

    public function replaceHours(int $id, array $hours, int $version): array
    {
        $this->authorizer->requireCapability(Capabilities::SPACES_MANAGE);
        return $this->db->transaction(function () use ($id, $hours, $version): array {
            $old = $this->occupancy->lockSpaces([$id])[$id];
            Version::assert($old, $version);
            $this->assertNoFuture($id);
            $before = $this->spaces->hours($id);
            $this->spaces->replaceHours($id, $hours);
            $this->audit->record('SPACE_HOURS_REPLACE', 'space', $id, ['hours' => $before], ['hours' => $hours]);
            return $this->show($id);
        });
    }

    public function availability(int $id, string $from, string $to): array
    {
        $this->authorizer->requireUser();
        $space = $this->requireSpace($id);
        $this->occupancy->assertRange($from, $to);
        $windows = $space['active'] ? $this->occupancy->windows($id, $from, $to) : [];
        $rows = $this->occupancies->calendar($id, $from, $to);
        if (count($rows) > 5000) {
            throw ApiException::validation(['from' => 'Reduce el rango: contiene demasiadas ocupaciones.']);
        }
        foreach ($rows as &$row) {
            foreach (['id', 'space_id'] as $key) {
                $row[$key] = (string) $row[$key];
            }
            foreach (['start_at', 'end_at'] as $key) {
                $row[$key] = ReservationPresenter::iso($row[$key]);
            }
        }
        unset($row);
        foreach ($windows as &$window) {
            $window = array_map(ReservationPresenter::iso(...), $window);
        }
        unset($window);
        return ['space_id' => (string) $id, 'timezone' => $this->occupancy->timezone()->getName(),
            'windows' => $windows, 'occupancies' => $rows, 'guaranteed' => false];
    }

    private function requireSpace(int $id): array
    {
        return $this->spaces->find($id) ?? throw ApiException::notFound('Espacio no encontrado.');
    }

    private function assertNoFuture(int $id): void
    {
        if ($this->occupancies->futureBlocking($id) !== []) {
            throw new ApiException(409, 'SPACE_HAS_FUTURE_OCCUPANCIES', 'Resuelve las ocupaciones futuras antes de cambiar la configuración.');
        }
    }

    private function assertName(string $name, int $except = 0): void
    {
        if ($this->spaces->nameExists($name, $except)) {
            throw ApiException::validation(['name' => 'Ya existe un espacio con ese nombre.']);
        }
    }

    private function present(array $space, bool $detail = true): array
    {
        $this->authorizer->requireUser();
        $out = array_intersect_key($space, array_flip(['name', 'description']));
        $out += ['id' => (string) $space['id'], 'version' => (string) $space['version'],
            'active' => (bool) $space['active'], 'block_minutes' => (int) $space['block_minutes']];
        if ($this->authorizer->can(Capabilities::SPACES_MANAGE)) {
            $out['hourly_rate'] = (string) $space['hourly_rate'];
        }
        if ($detail) {
            $out['hours'] = array_map(static fn (array $row): array => ['weekday' => (int) $row['weekday'],
                'local_start_time' => $row['local_start_time'], 'local_end_time' => $row['local_end_time']], $this->spaces->hours((int) $space['id']));
        }
        return $out;
    }
}
