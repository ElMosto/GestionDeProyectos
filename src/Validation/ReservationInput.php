<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\ApiException;
use App\Support\Pagination;
use DateTimeImmutable;
use DateTimeZone;

final class ReservationInput
{
    public const STATUSES = 'CONFIRMED|IN_PROGRESS|FINISHED|CANCELLED';

    public static function fields(array $body, string $action): array
    {
        $schema = match ($action) {
            'create' => ['band_client_id' => 'id', 'space_id' => 'id', 'notes' => '?text10000', 'reason' => 'text255'],
            'reschedule' => ['space_id' => 'id', 'reason' => 'text255'],
            'notes' => ['notes' => '?text10000'],
            'transition' => ['status' => 'IN_PROGRESS|FINISHED', 'reason' => 'text255'],
            'cancel' => ['reason' => 'text255'],
            'block' => ['space_id' => 'id', 'reason' => 'text255'],
            'unblock' => ['reason' => 'text255'],
        };
        $schedule = in_array($action, ['create', 'reschedule', 'block'], true);
        $dates = [];
        if ($schedule) {
            foreach (['start_at', 'end_at'] as $key) {
                $dates[$key] = self::instant($body[$key] ?? null, $key);
                unset($body[$key]);
            }
        }
        $required = match ($action) {
            'create' => ['space_id', 'band_client_id'],
            'reschedule' => ['space_id'],
            'notes' => ['notes'],
            'transition' => ['status'],
            'cancel' => ['reason'],
            'block' => ['space_id', 'reason'],
            default => [],
        };
        // Nullable notes is a valid explicit clearing operation.
        if ($action === 'notes' && array_key_exists('notes', $body) && $body['notes'] === null) {
            $required = [];
        }
        return ProjectInput::fields($body, $schema, $required, !in_array($action, ['create', 'block'], true)) + $dates;
    }

    public static function instant(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/', $value) !== 1) {
            throw ApiException::validation([$field => 'Envía una fecha ISO 8601 con segundos y zona horaria.']);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', str_replace('Z', '+00:00', $value));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $date->format('Y-m-d\TH:i:sP') !== str_replace('Z', '+00:00', $value)) {
            throw ApiException::validation([$field => 'La fecha no es válida.']);
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function query(array $query): array
    {
        $out = ['limit' => Pagination::limit($query['limit'] ?? null), 'offset' => Pagination::offset($query['offset'] ?? null)];
        foreach (['space_id', 'band_client_id'] as $key) {
            if (isset($query[$key]) && $query[$key] !== '') {
                $out[$key] = ProjectInput::id($query[$key]);
            }
        }
        foreach (['from', 'to'] as $key) {
            if (isset($query[$key]) && $query[$key] !== '') {
                $out[$key] = self::instant($query[$key], $key);
            }
        }
        foreach (['q' => 'text120', 'status' => self::STATUSES] as $key => $type) {
            if (isset($query[$key]) && $query[$key] !== '') {
                $out += ProjectInput::fields([$key => $query[$key]], [$key => $type]);
            }
        }
        if (isset($out['from'], $out['to']) && $out['to'] <= $out['from']) {
            throw ApiException::validation(['to' => 'El fin debe ser posterior al inicio.']);
        }
        return $out;
    }
}
