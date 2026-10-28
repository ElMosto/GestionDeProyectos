<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Policies\Authorizer;
use App\Policies\Capabilities;
use App\Repositories\SpaceBlockRepository;
use App\Repositories\SpaceOccupancyRepository;
use App\Support\Version;

final class SpaceBlockService
{
    public function __construct(
        private readonly SpaceBlockRepository $blocks,
        private readonly SpaceOccupancyRepository $occupancies,
        private readonly SpaceOccupancyService $occupancy,
        private readonly Authorizer $authorizer,
        private readonly ProjectAudit $audit,
        private readonly ProjectIdempotency $idempotency,
    ) {
    }

    public function list(array $query): array
    {
        $this->authorizer->requireCapability(Capabilities::SPACES_MANAGE);
        $page = $this->blocks->paginate($query);
        return ['data' => array_map($this->present(...), $page['rows']), 'meta' => $page['meta']];
    }

    public function create(array $input, ?string $key): array
    {
        $this->authorizer->requireCapability(Capabilities::SPACES_MANAGE);
        return $this->idempotency->run('space_block.create', $key, $input, function () use ($input): array {
            $space = $this->occupancy->lockSpaces([(int) $input['space_id']])[(int) $input['space_id']];
            if (!$space['active']) {
                throw new ApiException(409, 'SPACE_INACTIVE', 'El espacio no está activo.');
            }
            // Mantenimiento no depende de ventanas comerciales ni crea importe.
            $this->occupancy->assertRange($input['start_at'], $input['end_at']);
            $this->occupancy->assertAvailable((int) $space['id'], $input['start_at'], $input['end_at']);
            $occupancy = $this->occupancy->createOccupancy((int) $space['id'], $input['start_at'], $input['end_at'],
                'MAINTENANCE', (int) $this->authorizer->id());
            $id = $this->blocks->create($occupancy, $input['reason'], (int) $this->authorizer->id());
            $this->audit->record('SPACE_BLOCK_CREATE', 'space_block', $id, null, $input + ['occupancy_id' => (string) $occupancy]);
            return $this->present($this->blocks->find($id));
        }, 201, function (): void {
            $this->authorizer->requireCapability(Capabilities::SPACES_MANAGE);
        });
    }

    public function cancel(int $id, int $version, ?string $key, ?string $reason = null): array
    {
        $this->authorizer->requireCapability(Capabilities::SPACES_MANAGE);
        $pre = $this->blocks->find($id) ?? throw ApiException::notFound('Bloqueo no encontrado.');
        return $this->idempotency->run('space_block.cancel.' . $id, $key, ['version' => $version, 'reason' => $reason],
            function () use ($id, $version, $pre, $reason): array {
                $this->occupancy->lockSpaces([(int) $pre['space_id']]);
                $old = $this->blocks->find($id, true) ?? throw ApiException::notFound('Bloqueo no encontrado.');
                Version::assert($old, $version);
                if ((int) $old['space_id'] !== (int) $pre['space_id'] || (int) $old['occupancy_id'] !== (int) $pre['occupancy_id']) {
                    throw new ApiException(409, 'VERSION_CONFLICT', 'El bloqueo cambió. Recárgalo antes de continuar.');
                }
                $occupancy = $this->occupancies->find((int) $old['occupancy_id'], true);
                if ($occupancy === null || $occupancy['kind'] !== 'MAINTENANCE' || $old['status'] !== 'CONFIRMED') {
                    throw new ApiException(409, 'RESERVATION_STATE_CONFLICT', 'El bloqueo ya no se puede cancelar.');
                }
                $this->blocks->cancel($id);
                $this->occupancies->transition((int) $old['occupancy_id'], 'CANCELLED');
                $this->audit->record('SPACE_BLOCK_CANCEL', 'space_block', $id,
                    ['status' => $old['status']], ['status' => 'CANCELLED', 'reason' => $reason]);
                return $this->present($this->blocks->find($id));
            }, 200, function (): void {
                $this->authorizer->requireCapability(Capabilities::SPACES_MANAGE);
            });
    }

    private function present(array $row): array
    {
        $this->authorizer->requireCapability(Capabilities::SPACES_MANAGE);
        $out = ['reason' => $row['reason'], 'status' => $row['status']];
        foreach (['id', 'occupancy_id', 'space_id', 'version'] as $key) {
            $out[$key] = (string) $row[$key];
        }
        foreach (['start_at', 'end_at'] as $key) {
            $out[$key] = ReservationPresenter::iso($row[$key]);
        }
        return $out;
    }
}
