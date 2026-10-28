<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Policies\Authorizer;
use App\Repositories\ReservationRepository;
use App\Repositories\ReservationSaleRepository;
use App\Repositories\SpaceOccupancyRepository;
use App\Repositories\SequenceRepository;
use App\Support\Dates;
use App\Support\Money;
use App\Support\Pagination;
use App\Support\Version;
use DateTimeImmutable;
use DateTimeZone;

final class ReservationService
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ReservationSaleRepository $sales,
        private readonly SpaceOccupancyRepository $occupancies,
        private readonly SpaceOccupancyService $occupancy,
        private readonly SequenceRepository $sequences,
        private readonly PaymentLedger $ledger,
        private readonly Authorizer $authorizer,
        private readonly ProjectAudit $audit,
        private readonly ProjectIdempotency $idempotency,
    ) {
    }

    public function requireReservation(int $id): array
    {
        $this->authorizer->requireUser();
        $row = $this->reservations->find($id);
        if ($row === null || !$this->authorizer->canViewReservation($row)) {
            throw ApiException::notFound('Reserva no encontrada.');
        }
        return $row;
    }

    public function list(array $query): array
    {
        $this->authorizer->requireUser();
        $page = $this->reservations->paginate($query, (int) $this->authorizer->id(), $this->authorizer->isAdmin());
        return ['data' => array_map(fn (array $row): array => $this->present($row, false), $page['rows']), 'meta' => $page['meta']];
    }

    public function show(int $id): array
    {
        return $this->present($this->requireReservation($id));
    }

    public function create(array $input, ?string $key): array
    {
        $this->authorizer->requireUser();
        if (!$this->authorizer->canManageReservation(['responsible_id' => $this->authorizer->id()])) {
            throw ApiException::forbidden();
        }
        return $this->idempotency->run('reservation.create', $key, $input, function () use ($input): array {
            $spaceId = (int) $input['space_id'];
            $space = $this->occupancy->lockSpaces([$spaceId])[$spaceId];
            $minutes = $this->validateSchedule($space, $input);
            $this->occupancy->assertAvailable($spaceId, $input['start_at'], $input['end_at']);
            $band = $this->reservations->band((int) $input['band_client_id']);
            if ($band === null || !$band['active'] || $band['type'] !== 'BANDA') {
                throw ApiException::validation(['band_client_id' => 'Selecciona un cliente BANDA activo.']);
            }
            $total = Money::reservationTotal((string) $space['hourly_rate'], $minutes);
            $actor = (int) $this->authorizer->id();
            $sale = $this->sales->issue(['folio' => $this->sequences->nextSale(), 'client_id' => $input['band_client_id'],
                'responsible_id' => $actor, 'created_by' => $actor, 'total' => $total], $this->description($space, $minutes));
            $occupancy = $this->occupancy->createOccupancy($spaceId, $input['start_at'], $input['end_at'], 'RESERVATION', $actor);
            $id = $this->reservations->create(['occupancy_id' => $occupancy, 'band_client_id' => $input['band_client_id'],
                'sale_id' => $sale, 'responsible_id' => $actor, 'hourly_rate_snapshot' => (string) $space['hourly_rate'],
                'total' => $total, 'notes' => $input['notes'] ?? null, 'created_by' => $actor]);
            $this->audit->record('RESERVATION_CREATE', 'reservation', $id, null,
                $input + ['sale_id' => (string) $sale, 'occupancy_id' => (string) $occupancy,
                    'hourly_rate_snapshot' => (string) $space['hourly_rate'], 'total' => $total, 'status' => 'CONFIRMED']);
            $this->audit->record('SALE_ISSUE', 'sale', $sale, null, ['reservation_id' => (string) $id, 'total' => $total, 'status' => 'EMITIDA']);
            return $this->show($id);
        }, 201, function (array $result): void {
            $this->requireReservation((int) $result['id']);
        });
    }

    /** Obtiene la vinculación preliminar fuera de TX; relee tras mutex sin tomar locks tardíos. */
    private function locked(array $pre, int $version, ?int $destination = null): array
    {
        $spaceIds = [(int) $pre['space_id']];
        if ($destination !== null) {
            $spaceIds[] = $destination;
        }
        $spaces = $this->occupancy->lockSpaces($spaceIds);
        $sale = $this->sales->find((int) $pre['sale_id'], true);
        $row = $this->reservations->find((int) $pre['id'], true);
        if ($row === null || !$this->authorizer->canViewReservation($row)) {
            throw ApiException::notFound('Reserva no encontrada.');
        }
        if ((int) $row['space_id'] !== (int) $pre['space_id'] || (int) $row['sale_id'] !== (int) $pre['sale_id']
            || (int) $row['occupancy_id'] !== (int) $pre['occupancy_id']) {
            throw new ApiException(409, 'VERSION_CONFLICT', 'La reserva cambió de ubicación. Recárgala antes de continuar.');
        }
        $occupancy = $this->occupancies->find((int) $row['occupancy_id'], true);
        Version::assert($row, $version);
        if (!$this->authorizer->canManageReservation($row)) {
            throw ApiException::forbidden();
        }
        if ($sale === null || $occupancy === null || $sale['type'] !== 'RESERVA'
            || (int) $sale['client_id'] !== (int) $row['band_client_id']
            || (int) $sale['responsible_id'] !== (int) $row['responsible_id']
            || $sale['status'] !== ($row['status'] === 'CANCELLED' ? 'CANCELADA' : 'EMITIDA')
            || Money::compare((string) $sale['total'], (string) $row['total']) !== 0
            || $occupancy['kind'] !== 'RESERVATION' || $occupancy['status'] !== $row['status']) {
            throw new ApiException(409, 'RESERVATION_STATE_CONFLICT', 'La reserva requiere revisión administrativa.');
        }
        return [$row, $spaces, $sale];
    }

    public function updateNotes(int $id, ?string $notes, int $version, ?string $key): array
    {
        $pre = $this->requireReservation($id);
        return $this->idempotency->run('reservation.notes.' . $id, $key, ['notes' => $notes, 'version' => $version],
            function () use ($id, $pre, $notes, $version): array {
                [$old] = $this->locked($pre, $version);
                $this->reservations->updateNotes($id, $notes);
                $this->audit->record('RESERVATION_NOTES_UPDATE', 'reservation', $id, ['notes' => $old['notes']], ['notes' => $notes]);
                return $this->show($id);
            }, 200, fn () => $this->requireReservation($id));
    }

    public function reschedule(int $id, array $input, int $version, ?string $key): array
    {
        $pre = $this->requireReservation($id);
        return $this->idempotency->run('reservation.reschedule.' . $id, $key, $input + ['version' => $version],
            function () use ($id, $pre, $input, $version): array {
                $destination = (int) $input['space_id'];
                [$old, $spaces, $sale] = $this->locked($pre, $version, $destination);
                $this->assertConfirmed($old);
                $space = $spaces[$destination];
                $minutes = $this->validateSchedule($space, $input);
                $this->occupancy->assertAvailable($destination, $input['start_at'], $input['end_at'], (int) $old['occupancy_id']);
                $rate = $destination === (int) $old['space_id'] ? $old['hourly_rate_snapshot'] : $space['hourly_rate'];
                $total = Money::reservationTotal((string) $rate, $minutes);
                $summary = $this->ledger->summary('sale', (int) $sale['id'], (string) $sale['total']);
                if (Money::compare($total, $summary['net_paid']) < 0) {
                    throw new ApiException(409, 'OVERPAYMENT', 'El nuevo total no puede ser menor al neto cobrado.');
                }
                // D-15: con pagos registrados (también si ya fueron reembolsados),
                // cambiar precio/tarifa es un ajuste comercial, no solo mover agenda.
                if (Money::compare($summary['paid_amount'], '0.00') > 0
                    && (Money::compare($total, (string) $old['total']) !== 0
                        || Money::compare((string) $rate, (string) $old['hourly_rate_snapshot']) !== 0)) {
                    $this->authorizer->requireAdmin();
                }
                $this->sales->reschedule((int) $sale['id'], $total, $this->description($space, $minutes));
                $this->reservations->reschedule($id, (string) $rate, $total);
                $this->occupancy->updateOccupancy((int) $old['occupancy_id'], $destination, $input['start_at'], $input['end_at']);
                $after = $input + ['hourly_rate_snapshot' => (string) $rate, 'total' => $total, 'version' => (string) ($version + 1)];
                $this->audit->record('RESERVATION_RESCHEDULE', 'reservation', $id,
                    array_intersect_key($old, array_flip(['space_id', 'start_at', 'end_at', 'hourly_rate_snapshot', 'total', 'version'])), $after);
                $this->audit->record('SALE_RESERVATION_RESCHEDULE', 'sale', (int) $sale['id'],
                    ['total' => $sale['total'], 'version' => $sale['version']],
                    ['total' => $total, 'reservation_id' => (string) $id, 'version' => (string) ((int) $sale['version'] + 1)]);
                return $this->show($id);
            }, 200, fn () => $this->requireReservation($id));
    }

    public function transition(int $id, string $status, int $version, ?string $key, ?string $reason = null): array
    {
        $pre = $this->requireReservation($id);
        return $this->idempotency->run('reservation.transition.' . $id, $key,
            ['status' => $status, 'version' => $version, 'reason' => $reason],
            function () use ($id, $pre, $status, $version, $reason): array {
                [$old] = $this->locked($pre, $version);
                $next = ['CONFIRMED' => 'IN_PROGRESS', 'IN_PROGRESS' => 'FINISHED'];
                if (($next[$old['status']] ?? null) !== $status) {
                    throw new ApiException(409, 'RESERVATION_STATE_CONFLICT', 'La transición de reserva no está permitida.');
                }
                $this->reservations->transition($id, $status);
                $this->occupancies->transition((int) $old['occupancy_id'], $status);
                $this->audit->record('RESERVATION_TRANSITION', 'reservation', $id,
                    ['status' => $old['status']], ['status' => $status, 'reason' => $reason]);
                return $this->show($id);
            }, 200, fn () => $this->requireReservation($id));
    }

    public function cancel(int $id, string $reason, int $version, ?string $key): array
    {
        $pre = $this->requireReservation($id);
        if (!$this->authorizer->canCancelReservation($pre)) {
            throw ApiException::forbidden();
        }
        return $this->idempotency->run('reservation.cancel.' . $id, $key,
            ['reason' => $reason, 'version' => $version], function () use ($id, $pre, $reason, $version): array {
                [$old, , $sale] = $this->locked($pre, $version);
                if (!$this->authorizer->canCancelReservation($old)) {
                    throw ApiException::forbidden();
                }
                if (!in_array($old['status'], ['CONFIRMED', 'IN_PROGRESS'], true)) {
                    throw new ApiException(409, 'RESERVATION_STATE_CONFLICT', 'La reserva ya no se puede cancelar.');
                }
                $summary = $this->ledger->summary('sale', (int) $sale['id'], (string) $sale['total']);
                if (Money::compare($summary['net_paid'], '0.00') !== 0) {
                    throw new ApiException(409, 'PAYMENTS_REQUIRE_RESOLUTION', 'Resuelve los pagos mediante reembolsos antes de cancelar.');
                }
                $this->sales->cancel((int) $sale['id']);
                $this->reservations->transition($id, 'CANCELLED');
                $this->occupancies->transition((int) $old['occupancy_id'], 'CANCELLED');
                $this->audit->record('RESERVATION_CANCEL', 'reservation', $id,
                    ['status' => $old['status']], ['status' => 'CANCELLED', 'reason' => $reason]);
                $this->audit->record('SALE_CANCEL', 'sale', (int) $sale['id'],
                    ['status' => $sale['status']], ['status' => 'CANCELADA', 'reason' => $reason, 'reservation_id' => (string) $id]);
                return $this->show($id);
            }, 200, function () use ($id): void {
                if (!$this->authorizer->canCancelReservation($this->requireReservation($id))) {
                    throw ApiException::forbidden();
                }
            });
    }

    private function assertConfirmed(array $row): void
    {
        if ($row['status'] !== 'CONFIRMED') {
            throw new ApiException(409, 'RESERVATION_STATE_CONFLICT', 'Solo se puede reprogramar una reserva confirmada.');
        }
    }

    private function validateSchedule(array $space, array $input): int
    {
        if ($input['start_at'] < gmdate('Y-m-d H:i:s')) {
            if (!$this->authorizer->isAdmin() || trim($input['reason'] ?? '') === '') {
                throw ApiException::validation(['reason' => 'Una reserva retrospectiva exige administrador y motivo.']);
            }
        }
        return $this->occupancy->assertWindow($space, $input['start_at'], $input['end_at']);
    }

    private function description(array $space, int $minutes): string
    {
        return mb_substr('Reserva: ' . $space['name'] . ' (' . $minutes . ' min)', 0, 160);
    }

    private function present(array $row, bool $detail = true): array
    {
        if (!$this->authorizer->canViewReservation($row)) {
            throw ApiException::notFound('Reserva no encontrada.');
        }
        $out = [];
        foreach (['id', 'occupancy_id', 'band_client_id', 'band_name', 'space_id', 'space_name', 'sale_id', 'responsible_id',
            'hourly_rate_snapshot', 'total', 'status', 'notes', 'version'] as $key) {
            $out[$key] = $row[$key] === null ? null : (string) $row[$key];
        }
        foreach (['start_at', 'end_at', 'created_at', 'updated_at'] as $key) {
            $out[$key] = ReservationPresenter::iso($row[$key]);
        }
        $out['duration_minutes'] = intdiv(
            (new DateTimeImmutable($row['end_at'], new DateTimeZone('UTC')))->getTimestamp()
                - (new DateTimeImmutable($row['start_at'], new DateTimeZone('UTC')))->getTimestamp(),
            60,
        );
        if ($detail) {
            $out['financial_summary'] = $this->ledger->summary('sale', (int) $row['sale_id'], (string) $row['total']);
        }
        return $out;
    }

    /** §13: agenda personal, incluso ADMIN; no reutilizar listado administrativo. */
    public function agenda(array $query): array
    {
        $user = $this->authorizer->requireUser();
        $from = Dates::date($query['from'] ?? null, 'from');
        $to = Dates::date($query['to'] ?? null, 'to');
        if ($from === null || $to === null || $to < $from) {
            throw ApiException::validation(['from' => 'Envía un rango de fechas válido.']);
        }
        // Rango por fechas civiles: un mes de 31 días sigue siendo válido durante DST.
        $civilDays = (int) (new DateTimeImmutable($from, new DateTimeZone('UTC')))
            ->diff(new DateTimeImmutable($to, new DateTimeZone('UTC')))->format('%a') + 1;
        if ($civilDays > 31) {
            throw ApiException::validation(['to' => 'El rango de agenda no puede superar 31 días.']);
        }
        $zone = new DateTimeZone($user['timezone'] ?? 'UTC');
        $start = (new DateTimeImmutable($from, $zone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end = (new DateTimeImmutable($to, $zone))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $page = $this->reservations->paginate(['from' => $start, 'to' => $end, 'agenda' => true,
            'limit' => Pagination::limit($query['limit'] ?? null), 'offset' => Pagination::offset($query['offset'] ?? null)],
            (int) $this->authorizer->id(), false);
        $events = [];
        foreach ($page['rows'] as $row) {
            if (!$this->authorizer->canViewReservation($row)) {
                continue;
            }
            $events[] = ['id' => (string) $row['id'], 'reservation_id' => (string) $row['id'], 'kind' => 'RESERVATION',
                'title' => 'Reserva · ' . $row['space_name'], 'space_id' => (string) $row['space_id'], 'status' => $row['status'],
                'start_at' => ReservationPresenter::iso($row['start_at']), 'end_at' => ReservationPresenter::iso($row['end_at'])];
        }
        return ['data' => $events, 'meta' => $page['meta']];
    }
}
