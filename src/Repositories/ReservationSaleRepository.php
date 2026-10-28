<?php

declare(strict_types=1);

namespace App\Repositories;

/** Excepción D-21: actualización coordinada de una venta RESERVA emitida. */
final class ReservationSaleRepository extends ProjectStorage
{
    public function find(int $id, bool $lock = false): ?array
    {
        return $this->one('SELECT * FROM sales WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
    }

    public function issue(array $fields, string $description): int
    {
        $setting = $this->one('SELECT value_json FROM settings WHERE `key`=?', ['currency']);
        $currency = $setting === null ? null : json_decode($setting['value_json'], true, 16, JSON_THROW_ON_ERROR);
        if (!is_string($currency) || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw new \LogicException('Configura la moneda del estudio antes de emitir documentos.');
        }
        $id = $this->insertRow('sales', $fields + ['currency' => $currency, 'type' => 'RESERVA', 'status' => 'EMITIDA', 'issued_at' => gmdate('Y-m-d H:i:s')],
            ['folio', 'client_id', 'responsible_id', 'created_by', 'total', 'currency', 'type', 'status', 'issued_at']);
        $this->insertRow('sale_items', ['sale_id' => $id, 'product_id' => null, 'name_snapshot' => $description,
            'quantity' => '1.000', 'unit_price' => $fields['total'], 'line_total' => $fields['total'], 'sort_order' => 0],
            ['sale_id', 'product_id', 'name_snapshot', 'quantity', 'unit_price', 'line_total', 'sort_order']);
        return $id;
    }

    public function reschedule(int $sale, string $total, string $description): void
    {
        $this->execute('UPDATE sales SET total=?,version=version+1 WHERE id=?', [$total, $sale]);
        $this->execute('UPDATE sale_items SET name_snapshot=?,unit_price=?,line_total=?,version=version+1 WHERE sale_id=?', [$description, $total, $total, $sale]);
    }

    public function cancel(int $id): void
    {
        $this->execute("UPDATE sales SET status='CANCELADA',version=version+1 WHERE id=?", [$id]);
    }

    public function ticket(int $sale): ?array
    {
        return $this->one('SELECT c.name AS band_name,s.name AS space_name,o.start_at,o.end_at FROM reservations r JOIN clients c ON c.id=r.band_client_id JOIN space_occupancies o ON o.id=r.occupancy_id JOIN spaces s ON s.id=o.space_id WHERE r.sale_id=?', [$sale]);
    }
}
