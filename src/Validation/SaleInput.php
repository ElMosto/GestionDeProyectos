<?php

declare(strict_types=1);

namespace App\Validation;

use App\Core\ApiException;

final class SaleInput
{
    public static function fields(array $body, bool $update = false): array
    {
        $items = $body['items'] ?? null;
        $hasItems = array_key_exists('items', $body);
        unset($body['items']);
        $schema = ['client_id' => '?id', 'notes' => '?text10000'];
        if (!$update) {
            $schema['type'] = 'ORDINARIA';
        }
        $out = ProjectInput::fields($body, $schema, [], $update);
        if ($hasItems) {
            if (!is_array($items) || !array_is_list($items) || count($items) > 200) {
                throw ApiException::validation(['items' => 'Envía hasta200 partidas.']);
            }
            $out['items'] = array_map(self::item(...), $items);
        }
        return $out;
    }

    private static function item(mixed $item): array
    {
        if (!is_array($item) || array_is_list($item)) {
            throw ApiException::validation(['items' => 'Partida no válida.']);
        }
        $extras = array_key_exists('extras', $item) ? $item['extras'] : [];
        unset($item['extras']);
        $free = array_key_exists('product_id', $item) && $item['product_id'] === null;
        $schema = ['product_id' => '?id', 'quantity' => 'quantity'];
        if ($free) {
            $schema += ['name' => 'text160', 'unit_price' => 'money'];
        }
        $out = ProjectInput::fields($item, $schema, $free ? ['name', 'unit_price', 'quantity'] : ['product_id', 'quantity']);
        if (!is_array($extras) || !array_is_list($extras) || count($extras) > 100) {
            throw ApiException::validation(['extras' => 'Envía hasta100 extras por partida.']);
        }
        $ids = [];
        $out['extras'] = [];
        foreach ($extras as $extra) {
            if (!is_array($extra) || array_is_list($extra)) {
                throw ApiException::validation(['extras' => 'Extra no válido.']);
            }
            $e = ProjectInput::fields($extra, ['extra_id' => 'id', 'quantity_per_unit' => 'quantity'], ['extra_id', 'quantity_per_unit']);
            if (isset($ids[$e['extra_id']])) {
                throw ApiException::validation(['extras' => 'No se permiten extras repetidos.']);
            }
            $ids[$e['extra_id']] = true;
            $out['extras'][] = $e;
        }
        return $out;
    }

    public static function query(array $query): array
    {
        $out = ProjectInput::query(array_intersect_key($query,
            array_flip(['limit', 'offset', 'q', 'status', 'type', 'client_id', 'from', 'to'])));
        if (isset($query['responsible_id']) && $query['responsible_id'] !== '') {
            $out['responsible_id'] = ProjectInput::id($query['responsible_id']);
        }
        foreach (['status' => ['BORRADOR', 'EMITIDA', 'CANCELADA'], 'type' => ['ORDINARIA', 'RESERVA']] as $key => $values) {
            if (isset($out[$key]) && !in_array($out[$key], $values, true)) {
                throw ApiException::validation([$key => 'Filtro no válido.']);
            }
        }
        return $out;
    }
}
