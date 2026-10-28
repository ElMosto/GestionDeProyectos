<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Policies\Authorizer;
use App\Repositories\MeetingRepository;
use App\Repositories\SpaceOccupancyRepository;
use App\Support\Version;
use DateTimeImmutable;
use DateTimeZone;

/** §13 / §19.F. Parent lock serializes response/reset, even when no space is used. */
final class MeetingService
{
    public function __construct(
        private readonly MeetingRepository $meetings,
        private readonly SpaceOccupancyRepository $occupancies,
        private readonly SpaceOccupancyService $spaces,
        private readonly NotificationService $notifications,
        private readonly MeetingPresenter $presenter,
        private readonly Authorizer $authorizer,
        private readonly ProjectAudit $audit,
        private readonly ProjectIdempotency $idempotency,
    ) {
    }

    public function list(array $query): array
    {
        $this->authorizer->requireCapability('meetings.create');
        $page = $this->meetings->listing($this->actor(), $query);
        return ['data' => array_map($this->presenter->detail(...), $page['rows']), 'meta' => $page['meta']];
    }

    public function show(int $id): array
    {
        $this->authorizer->requireUser();
        $row = $this->meetings->find($id, $this->actor());
        if (!$this->authorizer->canViewMeeting($row)) {
            throw ApiException::notFound();
        }
        return $this->presenter->detail($row, $this->authorizer->canManageMeeting($row)
            ? $this->meetings->invitations($id) : null);
    }

    public function agenda(array $query): array
    {
        $this->authorizer->requireCapability('meetings.create');
        $zone = $this->userZone();
        $from = new DateTimeImmutable($query['from'], $zone);
        $to = (new DateTimeImmutable($query['to'], $zone))->modify('+1 day');
        $days = (int) $from->diff($to)->format('%r%a');
        if ($days < 1 || $days > 31) {
            throw ApiException::validation(['to' => 'La agenda admite de 1 a 31 días inclusivos.']);
        }
        $query['since'] = $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $query['until'] = $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $page = $this->meetings->listing($this->actor(), $query, true);
        return ['data' => array_map($this->presenter->event(...), $page['rows']), 'meta' => $page['meta']];
    }

    public function create(array $input, ?string $key): array
    {
        $this->authorizer->requireCapability('meetings.create');
        return $this->write('meetings.create', $key, ['method' => 'POST', 'path' => '/api/v1/meetings', 'body' => $input],
            function () use ($input): int {
                $invitees = $input['invited_user_ids'];
                if (in_array($this->actor(), $invitees, true)) {
                    throw ApiException::validation(['invited_user_ids' => 'El organizador no se invita a sí mismo.']);
                }
                $space = $input['space_id'] ?? null;
                $locked = $this->spaces->lockSpaces($space === null ? [] : [$space]);
                $this->spaces->assertRange($input['start_at'], $input['end_at']);
                if ($space !== null) {
                    $this->spaces->assertWindow($locked[$space], $input['start_at'], $input['end_at']);
                    $this->spaces->assertAvailable($space, $input['start_at'], $input['end_at']);
                }
                $this->assertPersonalConflicts($input['start_at'], $input['end_at'], 0, $input['acknowledge_conflicts'] ?? false);
                if (!$this->meetings->assertActiveUsers([$this->actor(), ...$invitees])) {
                    throw ApiException::validation(['invited_user_ids' => 'Selecciona únicamente usuarios activos.']);
                }
                $occupancy = $space === null ? null : $this->spaces->createOccupancy(
                    $space, $input['start_at'], $input['end_at'], 'MEETING', $this->actor(),
                );
                $id = $this->meetings->create(['organizer_id' => $this->actor(), 'title' => $input['title'],
                    'description' => $input['description'] ?? null, 'start_at' => $input['start_at'],
                    'end_at' => $input['end_at'], 'modality' => $input['modality'] ?? 'PRESENCIAL',
                    'location' => $input['location'] ?? null, 'occupancy_id' => $occupancy,
                    'status' => 'PROGRAMADA', 'created_by' => $this->actor()]);
                sort($invitees, SORT_NUMERIC);
                foreach ($invitees as $invitee) {
                    $this->meetings->invite($id, $invitee);
                }
                $this->notifications->notify($invitees, 'MEETING_INVITED', 'meeting', $id);
                $this->audit->record('meetings.created', 'meeting', $id, null,
                    ['status' => 'PROGRAMADA', 'start_at' => $input['start_at'], 'end_at' => $input['end_at'],
                        'space_id' => $space, 'invited_user_ids' => $invitees, 'version' => 1]);
                return $id;
            }, 201);
    }

    public function update(int $id, int $version, array $input, ?string $key): array
    {
        $this->authorizer->requireUser();
        return $this->write('meetings.update', $key,
            ['method' => 'PATCH', 'path' => '/api/v1/meetings/' . $id, 'version' => $version, 'body' => $input],
            function () use ($id, $version, $input): int {
                [$row, $occupancy, $locked] = $this->lockForManagement($id, $version, $input);
                $invitations = $this->meetings->invitations($id, true);
                $start = $input['start_at'] ?? $row['start_at'];
                $end = $input['end_at'] ?? $row['end_at'];
                $space = array_key_exists('space_id', $input) ? $input['space_id'] : $row['space_id'];
                $changedTime = $start !== $row['start_at'] || $end !== $row['end_at'];
                $changedSpace = $space !== $row['space_id'];
                $this->spaces->assertRange($start, $end);
                if ($space !== null && ($changedTime || $changedSpace)) {
                    $this->spaces->assertWindow($locked[$space], $start, $end);
                    $this->spaces->assertAvailable($space, $start, $end, $occupancy === null ? null : (int) $occupancy['id']);
                }
                if ($changedTime) {
                    $this->assertPersonalConflicts($start, $end, $id, $input['acknowledge_conflicts'] ?? false);
                }
                $occupancyId = $row['occupancy_id'] === null ? null : (int) $row['occupancy_id'];
                if ($space === null && $occupancyId !== null) {
                    $this->occupancies->transition($occupancyId, 'CANCELLED');
                    $occupancyId = null;
                } elseif ($space !== null && $occupancyId === null) {
                    $occupancyId = $this->spaces->createOccupancy($space, $start, $end, 'MEETING', $this->actor());
                } elseif ($space !== null && ($changedTime || $changedSpace)) {
                    $this->spaces->updateOccupancy($occupancyId, $space, $start, $end);
                }
                $fields = array_intersect_key($input, array_flip(['title', 'description', 'modality', 'location']));
                $fields += ['start_at' => $start, 'end_at' => $end, 'occupancy_id' => $occupancyId];
                $this->meetings->editMeeting($id, $fields);
                if ($changedTime) {
                    // D-24: rejected invitations are reset too; version invalidates old schedule responses.
                    $this->meetings->resetInvitations($id);
                }
                $this->notifications->notify(array_column($invitations, 'invitee_id'), 'MEETING_CHANGED', 'meeting', $id);
                $this->audit->record($changedTime ? 'meetings.rescheduled' : 'meetings.updated', 'meeting', $id,
                    $this->snapshot($row) + ($changedTime ? ['invitations' => $this->invitationSnapshot($invitations)] : []),
                    ['start_at' => $start, 'end_at' => $end, 'space_id' => $space, 'occupancy_id' => $occupancyId,
                        'status' => 'PROGRAMADA', 'version' => $version + 1,
                        'changed_fields' => array_keys($input), 'invitations_reset' => $changedTime]);
                return $id;
            });
    }

    public function cancel(int $id, int $version, string $reason, ?string $key): array
    {
        $this->authorizer->requireUser();
        if (trim($reason) === '') {
            throw ApiException::validation(['reason' => 'Indica el motivo de cancelación.']);
        }
        return $this->write('meetings.cancel', $key,
            ['method' => 'POST', 'path' => '/api/v1/meetings/' . $id . '/cancel', 'version' => $version, 'reason' => $reason],
            function () use ($id, $version, $reason): int {
                [$row] = $this->lockForManagement($id, $version, []);
                $invitations = $this->meetings->invitations($id, true);
                if ($row['occupancy_id'] !== null) {
                    $this->occupancies->transition((int) $row['occupancy_id'], 'CANCELLED');
                }
                $this->meetings->cancelMeeting($id);
                $this->notifications->notify(array_column($invitations, 'invitee_id'), 'MEETING_CANCELLED', 'meeting', $id);
                $this->audit->record('meetings.cancelled', 'meeting', $id, $this->snapshot($row),
                    ['status' => 'CANCELADA', 'version' => $version + 1, 'reason' => $reason]);
                return $id;
            });
    }

    public function respond(int $id, int $version, array $input, ?string $key): array
    {
        $this->authorizer->requireUser();
        return $this->write('meetings.response', $key,
            ['method' => 'POST', 'path' => '/api/v1/meetings/' . $id . '/response', 'version' => $version, 'body' => $input],
            function () use ($id, $version, $input): int {
                // No space mutex: accepting does not acquire or release occupancy.
                $base = $this->meetings->lockMeeting($id);
                if ($base === null) {
                    throw ApiException::notFound();
                }
                $invitation = $this->meetings->ownInvitation($id, $this->actor());
                $row = $this->meetings->decorate($base, $invitation, null);
                if (!$this->authorizer->canRespondMeeting($row)) {
                    throw ApiException::notFound();
                }
                Version::assert($invitation, $version);
                $this->assertScheduled($row);
                if ($input['response'] === 'ACEPTADA') {
                    $this->assertPersonalConflicts($row['start_at'], $row['end_at'], $id, $input['acknowledge_conflicts'] ?? false);
                }
                $this->meetings->respond((int) $invitation['id'], $input['response']);
                $this->notifications->notify([(int) $row['organizer_id']], 'MEETING_RESPONDED', 'meeting', $id);
                $this->audit->record('meetings.responded', 'meeting', $id,
                    ['invitation_id' => (string) $invitation['id'], 'response' => $invitation['response'], 'version' => $version],
                    ['invitation_id' => (string) $invitation['id'], 'response' => $input['response'],
                        'version' => $version + 1, 'invitee_id' => (string) $this->actor()]);
                return $id;
            });
    }

    /** Requests -> spaces ASC -> meeting -> occupancy -> invitations. */
    private function lockForManagement(int $id, int $version, array $input): array
    {
        $pre = $this->meetings->find($id, $this->actor());
        if (!$this->authorizer->canManageMeeting($pre)) {
            throw ApiException::notFound();
        }
        $ids = array_filter([$pre['space_id'], $input['space_id'] ?? null], static fn ($id): bool => $id !== null);
        $locked = $this->spaces->lockSpaces($ids);
        $row = $this->meetings->lockMeeting($id);
        if (!$this->authorizer->canManageMeeting($row)) {
            throw ApiException::notFound();
        }
        Version::assert($row, $version);
        $this->assertScheduled($row);
        // Version alone is not permission to lock a newly discovered space out of order.
        if ($row['occupancy_id'] !== $pre['occupancy_id']) {
            throw new ApiException(409, 'VERSION_CONFLICT', 'La reunión cambió. Recárgala.');
        }
        $occupancy = $row['occupancy_id'] === null ? null : $this->occupancies->find((int) $row['occupancy_id'], true);
        $space = $occupancy === null ? null : (int) $occupancy['space_id'];
        if ($space !== null && !isset($locked[$space])) {
            throw new ApiException(409, 'VERSION_CONFLICT', 'La reunión cambió de espacio. Recárgala.');
        }
        if ($occupancy !== null && ($occupancy['kind'] !== 'MEETING' || $occupancy['status'] !== 'CONFIRMED')) {
            throw new ApiException(409, 'INVALID_STATE', 'La ocupación de la reunión no permite este cambio.');
        }
        $row['space_id'] = $space;
        return [$row, $occupancy, $locked];
    }

    private function write(string $operation, ?string $key, array $fingerprint, callable $work, int $status = 200): array
    {
        // Store identity, not a private representation that could outlive current acceptance.
        $result = $this->idempotency->run($operation, $key, $fingerprint,
            static fn (): array => ['id' => (string) $work()], $status,
            function (array $result): void {
                $id = (int) $result['id'];
                $row = $this->meetings->lockMeeting($id);
                if ($row === null) {
                    throw ApiException::notFound();
                }
                $invitation = $this->meetings->ownInvitation($id, $this->actor());
                if (!$this->authorizer->canViewMeeting($this->meetings->decorate($row, $invitation, null))) {
                    throw ApiException::notFound();
                }
            });
        return $this->show((int) $result['id']);
    }

    private function assertPersonalConflicts(string $start, string $end, int $exclude, bool $acknowledged): void
    {
        $zone = $this->userZone();
        $first = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');
        $last = (new DateTimeImmutable($end, new DateTimeZone('UTC')))->modify('-1 second')->setTimezone($zone)->format('Y-m-d');
        if (!$acknowledged && $this->meetings->hasPersonalConflict($this->actor(), $start, $end, $exclude, $first, $last)) {
            throw new ApiException(409, 'CONFLICTS_REQUIRE_ACK', 'Hay un cruce con actividades de tu agenda. Confirma para continuar.');
        }
    }

    private function userZone(): DateTimeZone
    {
        return new DateTimeZone($this->authorizer->user()['timezone'] ?? 'UTC');
    }

    private function actor(): int
    {
        return (int) $this->authorizer->id();
    }

    private function assertScheduled(array $row): void
    {
        if ($row['status'] !== 'PROGRAMADA') {
            throw new ApiException(409, 'INVALID_STATE', 'La reunión ya no está programada.');
        }
    }

    private function snapshot(array $row): array
    {
        return array_intersect_key($row, array_flip(['start_at', 'end_at', 'space_id', 'occupancy_id', 'status', 'version']));
    }

    private function invitationSnapshot(array $invitations): array
    {
        return array_map(static fn (array $i): array => array_intersect_key($i,
            array_flip(['id', 'invitee_id', 'response', 'responded_at', 'version'])), $invitations);
    }
}
