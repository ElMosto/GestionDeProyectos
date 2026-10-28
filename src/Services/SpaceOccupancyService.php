<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Repositories\SpaceRepository;
use App\Repositories\SpaceOccupancyRepository;
use App\Repositories\StudioRepository;
use DateTimeImmutable;
use DateTimeZone;

/** §12: coordinador reutilizable; el llamador posee la transacción e idempotencia. */
final class SpaceOccupancyService
{
    public function __construct(
        private readonly SpaceRepository $spaces,
        private readonly SpaceOccupancyRepository $occupancies,
        private readonly StudioRepository $studio,
    ) {
    }

    /** @return array<int,array> Mutex canónico anterior a venta, reserva y ocupación. */
    public function lockSpaces(array $ids): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $ids)));
        sort($ids, SORT_NUMERIC);
        $locked = [];
        foreach ($ids as $id) {
            $space = $this->spaces->find($id, true);
            if ($space === null) {
                throw ApiException::notFound('Espacio no encontrado.');
            }
            $locked[$id] = $space;
        }
        return $locked;
    }

    public function assertAvailable(int $space, string $start, string $end, ?int $excludeOccupancyId = null): void
    {
        if ($this->occupancies->conflicts($space, $start, $end, $excludeOccupancyId) !== []) {
            throw new ApiException(409, 'RESERVATION_CONFLICT', 'El espacio está ocupado en ese intervalo.');
        }
    }

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone($this->studio->ticketData()['timezone']);
    }

    public function assertRange(string $start, string $end): int
    {
        $seconds = $this->utc($end)->getTimestamp() - $this->utc($start)->getTimestamp();
        if ($seconds <= 0 || $seconds > 31 * 86400) {
            throw ApiException::validation(['end_at' => 'El intervalo debe ser positivo y no superar 31 días.']);
        }
        return $seconds;
    }

    /** Duración real UTC; alineación de ambos extremos desde medianoche local. */
    public function assertWindow(array $space, string $start, string $end): int
    {
        if (!$space['active']) {
            throw new ApiException(409, 'SPACE_INACTIVE', 'El espacio no está activo.');
        }
        $seconds = $this->assertRange($start, $end);
        $block = (int) $space['block_minutes'];
        foreach ([$start, $end] as $instant) {
            $local = $this->utc($instant)->setTimezone($this->timezone());
            $minutes = (int) $local->format('G') * 60 + (int) $local->format('i');
            if ($local->format('s') !== '00' || $minutes % $block !== 0) {
                throw ApiException::validation(['start_at' => 'Alinea los extremos al bloque del espacio desde medianoche local.']);
            }
        }
        if ($seconds % (60 * $block) !== 0) {
            throw ApiException::validation(['end_at' => 'La duración debe ser múltiplo del bloque del espacio.']);
        }
        $cursor = $start;
        foreach ($this->windows((int) $space['id'], $start, $end) as $window) {
            if ($window['start_at'] > $cursor) {
                break;
            }
            if ($window['end_at'] > $cursor) {
                $cursor = $window['end_at'];
            }
            if ($cursor >= $end) {
                return intdiv($seconds, 60);
            }
        }
        throw ApiException::validation(['start_at' => 'El intervalo no está cubierto por los horarios del espacio.']);
    }

    /** Ventanas UTC recortadas al rango; nunca prometen disponibilidad concurrente. */
    public function windows(int $space, string $from, string $to): array
    {
        $this->assertRange($from, $to);
        $zone = $this->timezone();
        $date = $this->utc($from)->setTimezone($zone)->format('Y-m-d');
        $last = $this->utc($to)->setTimezone($zone)->format('Y-m-d');
        $hours = $this->spaces->hours($space);
        $windows = [];
        // Fechas civiles avanzadas en UTC para no normalizar medianoches inexistentes.
        for ($day = new DateTimeImmutable($date, new DateTimeZone('UTC')); $day->format('Y-m-d') <= $last; $day = $day->modify('+1 day')) {
            foreach ($hours as $hour) {
                if ((int) $hour['weekday'] !== (int) $day->format('N')) {
                    continue;
                }
                $start = $this->localBoundary($day->format('Y-m-d'), $hour['local_start_time'], $zone);
                $end = $this->localBoundary($day->format('Y-m-d'), $hour['local_end_time'], $zone);
                if ($start < $to && $end > $from) {
                    $windows[] = ['start_at' => max($start, $from), 'end_at' => min($end, $to)];
                }
            }
        }
        usort($windows, static fn (array $a, array $b): int => $a['start_at'] <=> $b['start_at']);
        return $windows;
    }

    /** Resolver todos los desfases posibles evita escoger silenciosamente una hora DST ambigua. */
    private function localBoundary(string $day, string $clock, DateTimeZone $zone): string
    {
        if ($clock === '24:00:00') {
            $day = (new DateTimeImmutable($day, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
            $clock = '00:00:00';
        }
        $wall = $day . ' ' . $clock;
        $naive = $this->utc($wall)->getTimestamp();
        $offsets = [];
        foreach ($zone->getTransitions($naive - 172800, $naive + 172800) ?: [] as $transition) {
            $offsets[(int) $transition['offset']] = true;
        }
        $matches = [];
        foreach (array_keys($offsets) as $offset) {
            $candidate = (new DateTimeImmutable('@' . ($naive - $offset)))->setTimezone($zone);
            if ($candidate->format('Y-m-d H:i:s') === $wall) {
                $matches[] = $candidate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        if (count($matches) !== 1) {
            throw ApiException::validation(['hours' => 'Una ventana tiene un extremo local ambiguo o inexistente por cambio horario.']);
        }
        return $matches[0];
    }

    public function createOccupancy(int $space, string $start, string $end, string $kind, int $actor): int
    {
        return $this->occupancies->create(['space_id' => $space, 'start_at' => $start, 'end_at' => $end,
            'kind' => $kind, 'status' => 'CONFIRMED', 'created_by' => $actor]);
    }

    public function updateOccupancy(int $id, int $space, string $start, string $end): void
    {
        $this->occupancies->reschedule($id, $space, $start, $end);
    }

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
