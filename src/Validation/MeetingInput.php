<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\ApiException;
use App\Support\Dates;
use App\Support\Pagination;

final class MeetingInput
{
    public static function fields(array $body, string $action): array
    {
        $schema = match ($action) {
            'create', 'update' => ['title' => 'text160', 'description' => '?text10000',
                'modality' => 'PRESENCIAL|VIRTUAL|HIBRIDA', 'location' => '?text200',
                'space_id' => '?id', 'acknowledge_conflicts' => 'bool'],
            'cancel' => ['reason' => 'text255'],
            'response' => ['response' => 'ACEPTADA|RECHAZADA', 'acknowledge_conflicts' => 'bool'],
            default => throw new \LogicException('Acción de reunión no válida.'),
        };
        if ($action === 'create') {
            $schema['invited_user_ids'] = 'ids';
        }
        if ($action === 'response' && isset($body['response'])) {
            $body['response'] = match ($body['response']) {
                'ACCEPTED' => 'ACEPTADA', 'DECLINED' => 'RECHAZADA', default => $body['response'],
            };
        }
        $dates = [];
        if (in_array($action, ['create', 'update'], true)) {
            foreach (['start_at', 'end_at'] as $field) {
                if ($action === 'create' || array_key_exists($field, $body)) {
                    $dates[$field] = ReservationInput::instant($body[$field] ?? null, $field);
                    unset($body[$field]);
                }
            }
        }
        $required = match ($action) {
            'create' => ['title', 'invited_user_ids'], 'cancel' => ['reason'],
            'response' => ['response'], default => [],
        };
        $out = ProjectInput::fields($body, $schema, $required, $action !== 'create') + $dates;
        if ($action === 'update' && $out === []) {
            throw ApiException::validation(['body' => 'Envía al menos un campo editable.']);
        }
        return $out;
    }

    public static function query(array $query, bool $agenda = false): array
    {
        $out = ['limit' => Pagination::limit($query['limit'] ?? null),
            'offset' => Pagination::offset($query['offset'] ?? null)];
        if ($agenda) {
            foreach (['from', 'to'] as $field) {
                $out[$field] = Dates::date($query[$field] ?? null, $field);
                if ($out[$field] === null) {
                    throw ApiException::validation([$field => 'Este campo es obligatorio.']);
                }
            }
        }
        return $out;
    }

    public static function notificationQuery(array $query): array
    {
        $out = self::query($query);
        if (isset($query['unread'])) {
            if (!in_array($query['unread'], ['0', '1'], true)) {
                throw ApiException::validation(['unread' => 'Usa 0 o 1.']);
            }
            $out['unread'] = $query['unread'] === '1';
        }
        return $out;
    }
}
