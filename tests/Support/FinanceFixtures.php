<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PDO;

/** §11/§19.G, D-28/D-29: explicit fictional initial histories, never backfill real documents.
 * SQL is only setup. No UPDATE/DELETE of immutable events; reporting is exercised by API.
 */
final class FinanceFixtures
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function range(array $overrides = []): array
    {
        return array_replace(['from' => '2026-10-01', 'to' => '2026-10-31', 'cut_off' => '2026-10-31'], $overrides);
    }

    public static function currentRange(): array
    {
        return ['from' => gmdate('Y-m-01'), 'to' => gmdate('Y-m-t'), 'cut_off' => gmdate('Y-m-t')];
    }

    public static function expenseBody(int|string $actor, array $overrides = []): array
    {
        return array_replace(['concept' => 'Material QA', 'amount' => '200.00', 'expense_date' => '2026-09-05',
            'responsible_id' => (string) $actor, 'status' => 'PENDIENTE', 'project_id' => null], $overrides);
    }

    public function sale(int|string $actor, string $total = '1000.00', string $issued = '2026-09-10 12:00:00',
        array $overrides = [], bool $evidence = true): array
    {
        $row = array_replace(['folio' => 'VTA-QA-' . Fixtures::uniqueSuffix(), 'type' => 'ORDINARIA',
            'client_id' => null, 'responsible_id' => $actor, 'status' => 'EMITIDA', 'issued_at' => $issued,
            'total' => $total, 'created_at' => $issued], $overrides);
        $id = $this->insert('sales', $row);
        if ($evidence) {
            $this->event($actor, null, $id, ['kind' => 'ISSUE', 'occurred_at' => $issued, 'issued_at' => $issued,
                'total' => $total, 'source_type' => $row['type'], 'client_id' => $row['client_id']]);
        }
        return ['id' => (string) $id] + $row;
    }

    public function event(int|string $actor, int|string|null $project, int|string|null $sale, array $overrides): int
    {
        $when = $overrides['occurred_at'];
        $kind = $overrides['kind'] ?? 'CHANGE';
        $action = $project === null
            ? ($kind === 'CANCEL' ? 'SALE_CANCEL' : ($kind === 'ISSUE' ? 'SALE_ISSUE' : 'SALE_RESERVATION_RESCHEDULE'))
            : ($kind === 'ISSUE' ? 'PROJECT_CREATE' : 'PROJECT_UPDATE');
        $audit = $this->audit($actor, $action,
            $project === null ? 'sale' : 'project', $project ?? $sale, $when);
        return $this->insert('commercial_document_events', array_replace([
            'project_id' => $project, 'sale_id' => $sale, 'audit_log_id' => $audit, 'kind' => 'CHANGE',
            'issued_at' => '2026-09-10 12:00:00', 'total' => '1000.00', 'billable' => 1,
            'source_type' => $project === null ? 'ORDINARIA' : 'INTERNO', 'client_id' => null,
            'responsible_id' => $actor, 'currency' => 'MXN', 'cancelled' => 0, 'created_at' => $when,
        ], $overrides));
    }

    public function audit(int|string $actor, string $action, string $entity, int|string|null $id,
        string $when = '2026-10-01 12:00:00', ?array $after = null): int
    {
        return $this->insert('audit_logs', ['actor_id' => $actor, 'action' => $action, 'entity_type' => $entity,
            'entity_id' => $id, 'created_at' => $when, 'after_json' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'request_id' => 'qa-initial-' . Fixtures::uniqueSuffix()]);
    }

    public function payment(int|string $actor, int|string $sale, string $amount = '120.00',
        string $effective = '2026-10-05 12:00:00', array $overrides = []): int
    {
        return $this->insert('payments', array_replace(['folio' => 'PAG-QA-' . Fixtures::uniqueSuffix(),
            'sale_id' => $sale, 'amount' => $amount, 'effective_at' => $effective, 'method' => 'EFECTIVO',
            'registered_by' => $actor, 'idempotency_key' => Fixtures::uniqueSuffix(), 'created_at' => $effective], $overrides));
    }

    public function refund(int|string $actor, int $payment, string $amount, string $when): int
    {
        return $this->insert('refunds', ['folio' => 'REE-QA-' . Fixtures::uniqueSuffix(), 'payment_id' => $payment,
            'amount' => $amount, 'effective_at' => $when, 'reason' => 'Devolución inicial ficticia',
            'registered_by' => $actor, 'idempotency_key' => Fixtures::uniqueSuffix(), 'created_at' => $when]);
    }

    public function pendingExpense(int|string $actor, string $when = '2026-09-01 12:00:00'): array
    {
        $row = ['folio' => 'GAS-QA-' . Fixtures::uniqueSuffix(), 'project_id' => null,
            'concept' => 'Gasto inicial ficticio', 'amount' => '200.00', 'expense_date' => '2026-09-01',
            'responsible_id' => $actor, 'created_by' => $actor, 'status' => 'PENDIENTE',
            'paid_at' => null, 'version' => 1, 'created_at' => $when];
        $id = $this->insert('expenses', $row);
        $row['id'] = (string) $id;
        $this->audit($actor, 'EXPENSE_CREATE', 'expense', $id, $when, $row);
        return $row;
    }

    /** Only constant fixture-controlled names are passed here, never HTTP input. */
    private function insert(string $table, array $row): int
    {
        $sql = 'INSERT INTO ' . $table . ' (`' . implode('`,`', array_keys($row)) . '`) VALUES ('
            . implode(',', array_fill(0, count($row), '?')) . ')';
        $this->pdo->prepare($sql)->execute(array_values($row));
        return (int) $this->pdo->lastInsertId();
    }

    public function snapshot(array $tables = ['projects', 'project_charges', 'sales', 'payments', 'refunds',
        'expenses', 'commercial_document_events', 'idempotency_requests', 'sequences']): array
    {
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = $this->pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll();
        }
        return $snapshot;
    }
}
