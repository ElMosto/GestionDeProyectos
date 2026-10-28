<?php

declare(strict_types=1);

namespace App\Repositories;

final class CatalogRepository extends ProjectStorage
{
    private function table(string $kind): string
    {
        return match ($kind) {
            'products' => 'products',
            'extras' => 'extras',
            default => throw new \LogicException('Catálogo desconocido.'),
        };
    }

    public function find(string $kind, int $id, bool $lock = false): ?array
    {
        return $this->one('SELECT * FROM ' . $this->table($kind) . ' WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
    }

    public function paginate(string $kind, array $q, bool $admin): array
    {
        $where = ['1=1'];
        $params = [];
        if (!$admin || isset($q['active'])) {
            $where[] = 'active=?';
            $params[] = $admin ? $q['active'] : true;
        }
        if (isset($q['q'])) {
            $where[] = 'name LIKE ?';
            $params[] = '%' . $q['q'] . '%';
        }
        if ($kind === 'products' && isset($q['type'])) {
            $where[] = 'type=?';
            $params[] = $q['type'];
        }
        return $this->page('*', 'FROM ' . $this->table($kind), implode(' AND ', $where), $params, 'name,id', $q);
    }

    public function create(string $kind, array $data): int
    {
        return $this->insertRow($this->table($kind), $data, $this->columns($kind));
    }

    public function update(string $kind, int $id, array $data): void
    {
        $this->changeRow($this->table($kind), $id, $data, $this->columns($kind));
    }

    private function columns(string $kind): array
    {
        return $kind === 'products'
            ? ['sku', 'name', 'description', 'type', 'unit', 'price', 'active', 'created_by']
            : ['name', 'price', 'active', 'created_by'];
    }

    public function extraIds(int $productId, bool $activeOnly = false): array
    {
        return array_map(static fn (array $r): int => (int) $r['extra_id'], $this->rows(
            'SELECT pe.extra_id FROM product_extras pe JOIN extras e ON e.id=pe.extra_id WHERE pe.product_id=?'
            . ($activeOnly ? ' AND e.active=1' : '') . ' ORDER BY pe.extra_id', [$productId],
        ));
    }

    public function replaceExtras(int $productId, array $ids): void
    {
        $this->execute('DELETE FROM product_extras WHERE product_id=?', [$productId]);
        foreach ($ids as $id) {
            $this->execute('INSERT INTO product_extras (product_id,extra_id) VALUES (?,?)', [$productId, $id]);
        }
        $this->update('products', $productId, []);
    }

    /** Orden global de locks: primero productos, después extras, ambos por ID. */
    public function selection(array $productIds, array $extraIds, bool $lock): array
    {
        $out = ['products' => [], 'extras' => [], 'links' => []];
        foreach (['products' => $productIds, 'extras' => $extraIds] as $kind => $ids) {
            $ids = array_values(array_unique($ids));
            sort($ids, SORT_NUMERIC);
            if ($ids === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->rows('SELECT * FROM ' . $this->table($kind) . ' WHERE id IN (' . $placeholders . ') ORDER BY id'
                . ($lock ? ' FOR UPDATE' : ''), $ids) as $row) {
                $out[$kind][(int) $row['id']] = $row;
            }
        }
        $ids = array_keys($out['products']);
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->rows('SELECT product_id,extra_id FROM product_extras WHERE product_id IN (' . $placeholders . ') ORDER BY product_id,extra_id'
                . ($lock ? ' FOR UPDATE' : ''), $ids) as $row) {
                $out['links'][(int) $row['product_id']][] = (int) $row['extra_id'];
            }
        }
        return $out;
    }
}
