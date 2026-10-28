<?php

declare(strict_types=1);

namespace App\Repositories;

/** Separate aggregates avoid multiplying a payment by its refund count (§11). */
final class PaymentLedgerRepository extends ProjectStorage
{
    public static function documentColumn(string $kind): string
    {
        return match ($kind) {
            'sale' => 'sale_id',
            'project' => 'project_id',
            default => throw new \InvalidArgumentException('Documento financiero desconocido.'),
        };
    }

    public function totals(string $kind, int $id): array
    {
        $column = self::documentColumn($kind);
        // Locking reads are current reads even when a previous non-locking read
        // established a REPEATABLE READ snapshot before waiting for the document.
        $lock = $this->db->pdo()->inTransaction() ? ' FOR UPDATE' : '';
        $paid = $this->one("SELECT COALESCE(SUM(amount),0.00) amount FROM payments WHERE {$column}=? AND status='REGISTRADO'" . $lock, [$id]);
        $refunded = $this->one("SELECT COALESCE(SUM(r.amount),0.00) amount FROM refunds r JOIN payments p ON p.id=r.payment_id WHERE p.{$column}=? AND p.status='REGISTRADO' AND r.status='REGISTRADO'" . $lock, [$id]);
        return ['paid_amount' => (string) $paid['amount'], 'refunded_amount' => (string) $refunded['amount']];
    }

    public function lockEntries(string $kind, int $id): void
    {
        $column = self::documentColumn($kind);
        $payments = $this->rows("SELECT id FROM payments WHERE {$column}=? ORDER BY id FOR UPDATE", [$id]);
        foreach ($payments as $payment) {
            $this->rows('SELECT id FROM refunds WHERE payment_id=? ORDER BY id FOR UPDATE', [(int) $payment['id']]);
        }
    }
}
