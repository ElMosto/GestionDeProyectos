<?php

declare(strict_types=1);

namespace App\Repositories;

final class PaymentRepository extends ProjectStorage
{
    public function find(int $id, bool $lock = false): ?array
    {
        return $this->one('SELECT * FROM payments WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
    }

    public function register(array $fields): int
    {
        return $this->insertRow('payments', $fields, ['folio', 'project_id', 'sale_id', 'amount',
            'effective_at', 'method', 'reference', 'notes', 'registered_by', 'idempotency_key']);
    }

    public function voidPayment(int $id, int $actor, string $reason): void
    {
        $this->execute("UPDATE payments SET status='ANULADO',voided_at=UTC_TIMESTAMP(),voided_by=?,void_reason=?,version=version+1 WHERE id=?", [$actor, $reason, $id]);
    }

    public function paginate(string $kind, int $id, array $query): array
    {
        return $this->page('p.*', 'FROM payments p', 'p.' . PaymentLedgerRepository::documentColumn($kind) . '=?',
            [$id], 'p.effective_at DESC,p.id DESC', $query);
    }
}
