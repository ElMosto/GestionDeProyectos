<?php

declare(strict_types=1);

namespace App\Repositories;

final class RefundRepository extends ProjectStorage
{
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM refunds WHERE id=? FOR UPDATE', [$id]);
    }

    public function registeredAmount(int $paymentId): string
    {
        $row = $this->one("SELECT COALESCE(SUM(amount),0.00) amount FROM refunds WHERE payment_id=? AND status='REGISTRADO' FOR UPDATE", [$paymentId]);
        return (string) $row['amount'];
    }

    public function hasRegistered(int $paymentId): bool
    {
        return $this->one("SELECT id FROM refunds WHERE payment_id=? AND status='REGISTRADO' ORDER BY id LIMIT 1 FOR UPDATE", [$paymentId]) !== null;
    }

    public function register(array $fields): int
    {
        return $this->insertRow('refunds', $fields, ['folio', 'payment_id', 'amount', 'effective_at',
            'reason', 'registered_by', 'idempotency_key']);
    }

    public function paginate(int $paymentId, array $query): array
    {
        return $this->page('r.*', 'FROM refunds r', 'r.payment_id=?', [$paymentId], 'r.effective_at DESC,r.id DESC', $query);
    }
}
