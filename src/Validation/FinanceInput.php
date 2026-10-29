<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\ApiException;
use App\Support\Dates;
use App\Support\Pagination;
use DateTimeImmutable;
use DateTimeZone;

final class FinanceInput
{
    public static function query(array $query, string $resource = 'finance'): array
    {
        $q = ['limit' => Pagination::limit($query['limit'] ?? null), 'offset' => Pagination::offset($query['offset'] ?? null)];
        foreach (['from' => gmdate('Y-m-01'), 'to' => gmdate('Y-m-d')] as $key => $default) {
            $q[$key] = Dates::date($query[$key] ?? $default, $key);
            if ($q[$key] === null) {
                throw ApiException::validation([$key => 'La fecha es obligatoria.']);
            }
        }
        $q['cut_off'] = Dates::date($query['cut_off'] ?? $q['to'], 'cut_off');
        if ($q['cut_off'] === null || $q['to'] < $q['from'] || $q['cut_off'] < $q['to'] || $q['cut_off'] === '9999-12-31') {
            throw ApiException::validation(['to' => 'El rango debe ser ordenado y no posterior al corte.']);
        }
        $zone = new DateTimeZone('UTC');
        $from = new DateTimeImmutable($q['from'], $zone);
        $to = new DateTimeImmutable($q['to'], $zone);
        if ((int) $from->diff($to)->format('%a') > 365) {
            throw ApiException::validation(['to' => 'El rango máximo es de 366 días inclusivos.']);
        }
        $q['start'] = $q['from'] . ' 00:00:00';
        $q['end'] = $to->modify('+1 day')->format('Y-m-d H:i:s');
        $q['cut_end'] = (new DateTimeImmutable($q['cut_off'], $zone))->modify('+1 day')->format('Y-m-d H:i:s');
        foreach (['client_id','responsible_id','project_id','actor_id'] as $key) {
            if (isset($query[$key]) && $query[$key] !== '') {
                $q[$key] = ProjectInput::id($query[$key]);
            }
        }
        foreach (['source_type' => ['INTERNO','EXTERNO','ORDINARIA','RESERVA'],
            'kind' => ['cash','expense'],
            'status' => $resource === 'expenses' ? ['PENDIENTE','PAGADO','CANCELADO'] : ['NO_PAGADO','PARCIAL','PAGADO','SIN_CARGO'],
            'action' => null, 'entity_type' => null] as $key => $values) {
            if (!isset($query[$key]) || $query[$key] === '') {
                continue;
            }
            if (!is_string($query[$key]) || ($values !== null ? !in_array($query[$key], $values, true)
                : preg_match('/\A[A-Za-z][A-Za-z0-9_.]{0,59}\z/', $query[$key]) !== 1)) {
                throw ApiException::validation([$key => 'Filtro no válido.']);
            }
            $q[$key] = $query[$key];
        }
        return $q;
    }
}
