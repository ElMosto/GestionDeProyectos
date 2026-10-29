<?php

declare(strict_types=1);

namespace App\Repositories;

final class SaleRepository extends ProjectStorage
{
    public function find(int $id, bool $lock = false): ?array
    {
        $sql = 'SELECT s.*, c.name AS client_name, u.name AS responsible_name'
            . ' FROM sales s LEFT JOIN clients c ON c.id=s.client_id'
            . ' LEFT JOIN users u ON u.id=s.responsible_id WHERE s.id=?';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        return $this->one($sql, [$id]);
    }

    public function paginate(array $q, int $actor, bool $admin): array
    {
        $where = ['1=1'];
        $params = [];
        if (!$admin) {
            $where[] = 's.responsible_id=?';
            $params[] = $actor;
        }
        foreach (['status', 'type', 'client_id', 'responsible_id'] as $key) {
            if (isset($q[$key])) {
                $where[] = 's.' . $key . '=?';
                $params[] = $q[$key];
            }
        }
        if (isset($q['q'])) {
            $where[] = 's.folio LIKE ?';
            $params[] = '%' . $q['q'] . '%';
        }
        if (isset($q['from'])) {
            $where[] = 's.issued_at>=?';
            $params[] = $q['from'] . ' 00:00:00';
        }
        if (isset($q['to'])) {
            $where[] = 's.issued_at<DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $q['to'] . ' 00:00:00';
        }
        return $this->page('s.*, c.name AS client_name, u.name AS responsible_name',
            'FROM sales s LEFT JOIN clients c ON c.id=s.client_id LEFT JOIN users u ON u.id=s.responsible_id',
            implode(' AND ', $where), $params, 's.id DESC', $q);
    }

    public function create(array $data): int
    {
        return $this->insertRow('sales', $data, ['folio', 'client_id', 'responsible_id', 'notes', 'total', 'created_by']);
    }

    public function updateDraft(int $id, array $data): void
    {
        $this->changeRow('sales', $id, $data, ['client_id', 'notes', 'total']);
    }

    public function issueSale(int $id, string $total): void
    {
        $this->execute("UPDATE sales SET status='EMITIDA',issued_at=UTC_TIMESTAMP(),total=?,version=version+1 WHERE id=?", [$total, $id]);
    }

    public function cancelSale(int $id): void
    {
        $this->execute("UPDATE sales SET status='CANCELADA',version=version+1 WHERE id=?", [$id]);
    }

    public function items(int $id): array
    {
        return $this->itemsForSales([$id])[$id] ?? [];
    }

    public function itemsForSales(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $items = $this->rows('SELECT id,sale_id,product_id,name_snapshot,quantity,unit_price,line_total,sort_order FROM sale_items WHERE sale_id IN (' . $placeholders . ') ORDER BY sale_id,sort_order,id', $ids);
        $extras = $this->rows('SELECT e.id,e.sale_item_id,e.extra_id,e.name_snapshot,e.quantity_per_unit,e.unit_price FROM sale_item_extras e JOIN sale_items i ON i.id=e.sale_item_id WHERE i.sale_id IN (' . $placeholders . ') ORDER BY e.id', $ids);
        $byItem = [];
        foreach ($extras as $extra) {
            $itemId = (int) $extra['sale_item_id'];
            unset($extra['sale_item_id']);
            $byItem[$itemId][] = $extra;
        }
        $bySale = [];
        foreach ($items as $item) {
            $item['extras'] = $byItem[(int) $item['id']] ?? [];
            $saleId = (int) $item['sale_id'];
            unset($item['sale_id']);
            $bySale[$saleId][] = $item;
        }
        return $bySale;
    }

    /** Reemplazo permitido exclusivamente sobre borradores, dentro de la TX bloqueada. */
    public function replaceItems(int $saleId, array $items): void
    {
        $this->execute('DELETE e FROM sale_item_extras e JOIN sale_items i ON i.id=e.sale_item_id WHERE i.sale_id=?', [$saleId]);
        $this->execute('DELETE FROM sale_items WHERE sale_id=?', [$saleId]);
        foreach ($items as $sort => $item) {
            $id = $this->insertRow('sale_items', [
                'sale_id' => $saleId, 'product_id' => $item['product_id'], 'name_snapshot' => $item['name_snapshot'],
                'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'], 'line_total' => $item['line_total'],
                'sort_order' => $sort,
            ], ['sale_id', 'product_id', 'name_snapshot', 'quantity', 'unit_price', 'line_total', 'sort_order']);
            foreach ($item['extras'] as $extra) {
                $this->insertRow('sale_item_extras', [
                    'sale_item_id' => $id, 'extra_id' => $extra['extra_id'], 'name_snapshot' => $extra['name_snapshot'],
                    'quantity_per_unit' => $extra['quantity_per_unit'], 'unit_price' => $extra['unit_price'],
                ], ['sale_item_id', 'extra_id', 'name_snapshot', 'quantity_per_unit', 'unit_price']);
            }
        }
    }

    /** Congela sin sustituir identificadores de partidas ya existentes. */
    public function freezeItems(array $items): void
    {
        foreach ($items as $item) {
            $this->execute('UPDATE sale_items SET name_snapshot=?,unit_price=?,line_total=?,version=version+1 WHERE id=?',
                [$item['name_snapshot'], $item['unit_price'], $item['line_total'], $item['id']]);
            foreach ($item['extras'] as $extra) {
                $this->execute('UPDATE sale_item_extras SET name_snapshot=?,unit_price=? WHERE id=?',
                    [$extra['name_snapshot'], $extra['unit_price'], $extra['id']]);
            }
        }
    }

    public function client(int $id, bool $lock = false): ?array
    {
        return $this->one('SELECT id,name,type,contact_name,phone,representative_name,active FROM clients WHERE id=?'
            . ($lock ? ' FOR UPDATE' : ''), [$id]);
    }

    public function responsible(int $id): ?array
    {
        return $this->one('SELECT id,name FROM users WHERE id=?', [$id]);
    }

    public function payments(int $id): array
    {
        return $this->rows('SELECT id,folio,amount,effective_at,method,reference,status,voided_at,void_reason FROM payments WHERE sale_id=? ORDER BY effective_at,id', [$id]);
    }

    public function refunds(int $id): array
    {
        return $this->rows('SELECT r.id,r.payment_id,r.folio,r.amount,r.effective_at,r.reason,r.status FROM refunds r JOIN payments p ON p.id=r.payment_id WHERE p.sale_id=? ORDER BY r.effective_at,r.id', [$id]);
    }
}
