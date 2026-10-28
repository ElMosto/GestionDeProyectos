<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\ApiException;
use App\Support\Dates;
use App\Support\Pagination;

/** Forma, no reglas de negocio; esquemas cerrados para impedir asignación masiva. */
final class ProjectInput
{
    public const PROJECT = [
        'name' => 'text160', 'description' => 'text10000', 'type' => 'INTERNO|EXTERNO',
        'client_id' => '?id', 'billable' => 'bool', 'leader_id' => 'id',
        'priority' => 'BAJA|MEDIA|ALTA|URGENTE', 'planned_start' => 'date',
        'planned_end' => 'date', 'actual_start' => 'date', 'actual_end' => 'date',
        'base_amount' => 'money', 'notes' => '?text10000',
    ];
    public const STAGE = [
        'name' => 'text160', 'description' => '?text10000', 'start_date' => 'date',
        'end_date' => 'date', 'status' => 'PENDIENTE|EN_CURSO|COMPLETADA|CANCELADA',
    ];
    public const TASK = [
        'title' => 'text200', 'description' => '?text10000', 'assignee_id' => 'id',
        'priority' => 'BAJA|MEDIA|ALTA|URGENTE', 'start_date' => 'date', 'end_date' => 'date',
        'actual_start' => 'date', 'actual_end' => 'date', 'is_required' => 'bool', 'progress' => 'progress',
    ];
    public const CHARGE = [
        'concept' => 'text200', 'quantity' => 'quantity', 'unit_price' => 'money',
        'charge_date' => 'required_date', 'status' => 'ACTIVO|ANULADO',
    ];

    public static function fields(array $body, array $schema, array $required = [], bool $versioned = false): array
    {
        $result = [];
        foreach ($body as $key => $value) {
            if ($versioned && in_array($key, ['version', 'expected_version'], true)) {
                continue;
            }
            if (!isset($schema[$key])) {
                throw ApiException::validation([$key => 'Campo no permitido.']);
            }
            $result[$key] = self::value($value, $schema[$key], $key);
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $result) || $result[$key] === null) {
                throw ApiException::validation([$key => 'Este campo es obligatorio.']);
            }
        }
        return $result;
    }

    public static function id(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value))
            || preg_match('/\A[1-9][0-9]{0,18}\z/', (string) $value) !== 1
            || bccomp((string) $value, (string) PHP_INT_MAX, 0) > 0) {
            throw ApiException::validation(['id' => 'Identificador no válido.']);
        }
        return (int) $value;
    }

    public static function ids(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > 1000) {
            throw ApiException::validation(['ids' => 'Envía una lista de hasta1000 identificadores.']);
        }
        $ids = array_map(self::id(...), $values);
        if (count($ids) !== count(array_unique($ids))) {
            throw ApiException::validation(['ids' => 'No se permiten identificadores repetidos.']);
        }
        return $ids;
    }

    public static function query(array $query): array
    {
        $out = ['limit' => Pagination::limit($query['limit'] ?? null), 'offset' => Pagination::offset($query['offset'] ?? null)];
        foreach (['q', 'type', 'status', 'leader_id', 'client_id', 'project_id', 'stage_id', 'assignee_id', 'standalone', 'from', 'to'] as $key) {
            if (!isset($query[$key]) || $query[$key] === '') {
                continue;
            }
            $value = $query[$key];
            if (str_ends_with($key, '_id')) {
                $out[$key] = self::id($value);
            } elseif ($key === 'standalone') {
                if (!in_array($value, ['0', '1'], true)) {
                    throw ApiException::validation([$key => 'Usa0 o1.']);
                }
                $out[$key] = $value === '1';
            } elseif (in_array($key, ['from', 'to'], true)) {
                $out[$key] = Dates::date($value, $key);
            } elseif (!is_string($value) || mb_strlen($value) > 120) {
                throw ApiException::validation([$key => 'Filtro no válido.']);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    private static function value(mixed $v, string $type, string $key): mixed
    {
        if (str_starts_with($type, '?')) {
            if ($v === null) {
                return null;
            }
            $type = substr($type, 1);
        }
        if ($type === 'id') {
            return self::id($v);
        }
        if ($type === 'date') {
            return Dates::date($v, $key);
        }
        if ($type === 'required_date') {
            if ($v === null) {
                throw ApiException::validation([$key => 'La fecha es obligatoria.']);
            }
            return Dates::date($v, $key);
        }
        if ($type === 'ids') {
            return self::ids($v);
        }
        $valid = match ($type) {
            'bool' => is_bool($v),
            'money' => is_string($v) && preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/', $v) === 1,
            'quantity' => is_string($v) && preg_match('/\A(?:0|[1-9][0-9]{0,6})\.[0-9]{3}\z/', $v) === 1 && bccomp($v, '0', 3) > 0,
            'progress' => is_int($v) && $v >= 0 && $v <= 100,
            default => str_starts_with($type, 'text')
                ? is_string($v) && trim($v) !== '' && mb_strlen($v) <= (int) substr($type, 4)
                    && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v) !== 1
                : is_string($v) && in_array($v, explode('|', $type), true),
        };
        if (!$valid) {
            throw ApiException::validation([$key => 'El valor o formato no es válido.']);
        }
        return is_string($v) ? trim($v) : $v;
    }
}
