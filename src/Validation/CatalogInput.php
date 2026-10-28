<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\ApiException;
use App\Support\Pagination;

final class CatalogInput
{
    public static function fields(array $body, string $kind, bool $update = false): array
    {
        $schema = $kind === 'products'
            ? ['sku' => '?text40', 'name' => 'text160', 'description' => '?text10000',
                'type' => 'PRODUCTO|SERVICIO', 'unit' => 'text32', 'price' => 'money', 'active' => 'bool']
            : ['name' => 'text120', 'price' => 'money', 'active' => 'bool'];
        return ProjectInput::fields($body, $schema, $update ? [] : ['name', 'price'], $update);
    }

    public static function query(array $query): array
    {
        $out = ['limit' => Pagination::limit($query['limit'] ?? null), 'offset' => Pagination::offset($query['offset'] ?? null)];
        foreach (['q', 'active', 'type'] as $key) {
            if (!isset($query[$key]) || $query[$key] === '') {
                continue;
            }
            $v = $query[$key];
            if (!is_string($v) || mb_strlen($v) > 120
                || ($key === 'active' && !in_array($v, ['0', '1'], true))
                || ($key === 'type' && !in_array($v, ['PRODUCTO', 'SERVICIO'], true))) {
                throw ApiException::validation([$key => 'Filtro no válido.']);
            }
            $out[$key] = $key === 'active' ? $v === '1' : $v;
        }
        return $out;
    }
}
