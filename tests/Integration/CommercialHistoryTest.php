<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\FinanceFixtures as H;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\ProjectFixtures as P;
use App\Tests\Support\SalesFixtures as F;
use PDOException;

/** §11/§19.G/H, D-28/D-29: immutable audit-linked commercial evidence. */
final class CommercialHistoryTest extends DatabaseTestCase
{
    public function test_G_D28_project_create_even_draft_is_explicit_issue_once_and_replay_no_new_evidence(): void
    {
        $p = new P($this->fixtures);
        [$actor, $admin] = $p->actor(Role::ADMINISTRADOR);
        [$leader] = $p->actor();
        $client = $this->fixtures->createClient($actor['id']);
        $body = P::body($leader['id'], ['base_amount' => '1000.00', 'billable' => true, 'client_id' => (string) $client['id']]);
        $key = F::key();
        $first = F::ok(Api::post('/api/v1/projects', $body, $admin, $key), 201);
        self::assertSame('BORRADOR', $first['status']);
        self::assertSame($first, F::ok(Api::post('/api/v1/projects', $body, $admin, $key), 201));
        $events = $this->pdo->query('SELECT e.*,a.action,a.entity_type,a.entity_id,a.created_at AS audited_at FROM commercial_document_events e JOIN audit_logs a ON a.id=e.audit_log_id')->fetchAll();
        self::assertCount(1, $events);
        self::assertSame('ISSUE', $events[0]['kind']);
        self::assertSame('PROJECT_CREATE', $events[0]['action']);
        self::assertSame($first['id'], (string) $events[0]['entity_id']);
        self::assertSame($events[0]['audited_at'], $events[0]['occurred_at']);
        self::assertSame($events[0]['occurred_at'], $events[0]['issued_at']);
        self::assertSame('1000.00', $events[0]['total']);
        F::ok(Api::post('/api/v1/projects/' . $first['id'] . '/payments', F::paymentBody(), $admin, F::key()), 201);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM commercial_document_events')->fetchColumn());
    }

    public function test_G_sale_draft_no_issue_then_emit_once_audit_linked(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $draft = $f->draft($admin, [F::item($f->product($admin))]);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM commercial_document_events')->fetchColumn());
        $sale = $f->issue($admin, $draft);
        $row = $this->pdo->query('SELECT e.*,a.action FROM commercial_document_events e JOIN audit_logs a ON a.id=e.audit_log_id')->fetch();
        self::assertSame('SALE_ISSUE', $row['action']);
        self::assertSame($sale['id'], (string) $row['sale_id']);
        self::assertSame('ISSUE', $row['kind']);
        self::assertNull($row['project_id']);
    }

    public function test_G_real_project_amount_charge_and_leader_changes_append_without_rewriting_first_issue(): void
    {
        $p = new P($this->fixtures);
        [$actor, $admin] = $p->actor(Role::ADMINISTRADOR);
        [$leader] = $p->actor();
        [$initialLeader] = $p->actor();
        $project = $p->project($admin, $initialLeader['id'], ['base_amount' => '1000.00']);
        $first = $this->pdo->query('SELECT * FROM commercial_document_events')->fetch();
        $updated = F::ok(Api::patch('/api/v1/projects/' . $project['id'], [
            'base_amount' => '1100.00', 'leader_id' => (string) $leader['id'], 'version' => $project['version'],
        ], $admin, F::key()));
        F::ok(Api::post('/api/v1/projects/' . $project['id'] . '/charges', [
            'concept' => 'Extra', 'quantity' => '1.000', 'unit_price' => '500.00', 'charge_date' => gmdate('Y-m-d'),
        ], $admin, F::key()), 201);
        $events = $this->pdo->query('SELECT * FROM commercial_document_events ORDER BY id')->fetchAll();
        self::assertCount(3, $events);
        self::assertSame($first, $events[0]);
        self::assertSame(['1000.00', '1100.00', '1600.00'], array_column($events, 'total'));
        self::assertSame(['ISSUE', 'CHANGE', 'CHANGE'], array_column($events, 'kind'));
        self::assertSame((string) $leader['id'], (string) $events[2]['responsible_id']);
        foreach ($events as $event) {
            self::assertSame($first['issued_at'], $event['issued_at']);
        }
        self::assertSame('1100.00', $updated['base_amount']);
    }

    public function test_G_historical_cut_uses_old_amount_owner_client_and_cancel_state_not_current_snapshot(): void
    {
        $f = new F($this->fixtures);
        [$actor, $admin] = $f->actor();
        [$other] = $f->actor(Role::LIDER_PROYECTO);
        $client = $this->fixtures->createClient($actor['id']);
        $h = new H($this->pdo);
        // Complete fictional initial source and evidence timeline; no existing event is changed.
        $sale = $h->sale($actor['id'], '1000.00', overrides: ['status' => 'CANCELADA']);
        $h->event($other['id'], null, $sale['id'], ['occurred_at' => '2026-10-05 12:00:00',
            'total' => '1500.00', 'client_id' => $client['id']]);
        $h->event($other['id'], null, $sale['id'], ['kind' => 'CANCEL', 'occurred_at' => '2026-10-20 12:00:00',
            'total' => '1500.00', 'client_id' => $client['id'], 'cancelled' => 1]);
        $sept = H::range(['from' => '2026-09-01', 'to' => '2026-09-30', 'cut_off' => '2026-09-30']);
        $before = F::ok(Api::get('/api/v1/finance/receivables', $sept, $admin));
        self::assertSame('1000.00', $before[0]['total']);
        self::assertSame((string) $actor['id'], $before[0]['responsible_id']);
        self::assertNull($before[0]['client_id']);
        $middle = F::ok(Api::get('/api/v1/finance/receivables', H::range(['to' => '2026-10-15', 'cut_off' => '2026-10-15']), $admin));
        self::assertSame('1500.00', $middle[0]['total']);
        self::assertSame((string) $other['id'], $middle[0]['responsible_id']);
        self::assertSame((string) $client['id'], $middle[0]['client_id']);
        self::assertSame([], F::ok(Api::get('/api/v1/finance/receivables', H::range(), $admin)));
        self::assertSame('1000.00', F::ok(Api::get('/api/v1/finance/summary', $sept, $admin))['commercial_total']);
    }

    public function test_G_history_triggers_reject_even_noop_update_and_delete(): void
    {
        [$actor] = (new F($this->fixtures))->actor();
        (new H($this->pdo))->sale($actor['id']);
        foreach (['UPDATE commercial_document_events SET total=total', 'DELETE FROM commercial_document_events'] as $sql) {
            $rejected = false;
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) {
                $rejected = true;
                self::assertSame('45000', $e->getCode());
            }
            self::assertTrue($rejected, '§11/D-29 immutable evidence must reject ' . $sql);
        }
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM commercial_document_events')->fetchColumn());
    }

    public function test_G_D29_legacy_project_change_records_baseline_not_fabricated_issue(): void
    {
        $p = new P($this->fixtures);
        [$leader, $token] = $p->actor();
        [, $admin] = $p->actor(Role::ADMINISTRADOR);
        // Explicit fictional legacy source; intentionally no commercial evidence.
        $this->pdo->prepare("INSERT INTO projects (folio,name,description,type,leader_id,base_amount,created_at) VALUES (?,?,?,'INTERNO',?,'1000.00','2026-09-01 12:00:00')")
            ->execute(['PRY-LEGACY-QA', 'Proyecto legado ficticio', 'Solo fixture inicial §19.G', $leader['id']]);
        $id = (string) $this->pdo->lastInsertId();
        F::ok(Api::patch('/api/v1/projects/' . $id, ['base_amount' => '1100.00', 'version' => '1'], $token, F::key()));
        $row = $this->pdo->query('SELECT * FROM commercial_document_events')->fetch();
        self::assertSame('BASELINE', $row['kind']);
        self::assertNull($row['issued_at']);
        $report = Api::get('/api/v1/finance/summary', H::currentRange(), $admin);
        self::assertSame(409, $report->status);
        self::assertSame('HISTORY_INCOMPLETE', $report->errorCode());
    }

    public function test_G_project_cancel_reopen_append_preserving_issue_and_operational_changes_do_not(): void
    {
        $p = new P($this->fixtures);
        [$leader, $token] = $p->actor();
        [, $admin] = $p->actor(Role::ADMINISTRADOR);
        $project = $p->project($token, $leader['id']);
        $path = '/api/v1/projects/' . $project['id'];
        $renamed = F::ok(Api::patch($path, ['name' => 'Nombre operativo', 'version' => $project['version']], $token, F::key()));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM commercial_document_events')->fetchColumn());
        $cancel = F::ok(Api::post($path . '/transitions', ['status' => 'CANCELADO', 'reason' => 'Cancelación QA', 'version' => $renamed['version']], $token));
        F::ok(Api::post($path . '/transitions', ['status' => 'EN_CURSO', 'reason' => 'Reapertura QA', 'version' => $cancel['version']], $admin));
        $events = $this->pdo->query('SELECT * FROM commercial_document_events ORDER BY id')->fetchAll();
        self::assertSame(['ISSUE', 'CANCEL', 'REOPEN'], array_column($events, 'kind'));
        self::assertCount(1, array_unique(array_column($events, 'issued_at')));
    }

    public function test_G_H_history_schema_enforces_xor_unique_audit_and_real_audit_fk(): void
    {
        [$actor] = (new F($this->fixtures))->actor();
        $h = new H($this->pdo);
        $sale = $h->sale($actor['id']);
        $auditId = $this->pdo->query('SELECT audit_log_id FROM commercial_document_events')->fetchColumn();
        foreach ([['sale_id' => null, 'project_id' => null, 'error' => 4025],
            ['audit_log_id' => $auditId, 'error' => 1062], ['audit_log_id' => 999999999, 'error' => 1452]] as $invalid) {
            $expected = $invalid['error'];
            unset($invalid['error']);
            $failed = false;
            try {
                $h->event($actor['id'], null, $sale['id'], $invalid + ['occurred_at' => '2026-10-10 12:00:00']);
            } catch (PDOException $e) {
                $failed = true;
                self::assertSame($expected, $e->errorInfo[1]);
            }
            self::assertTrue($failed);
        }
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM commercial_document_events')->fetchColumn());
    }

    public function test_G_H_audit_failure_rolls_back_document_history_and_idempotency_together(): void
    {
        $p = new P($this->fixtures);
        [$actor, $admin] = $p->actor();
        $h = new H($this->pdo);
        $before = $h->snapshot();
        $this->pdo->exec("CREATE TRIGGER finance_qa_reject_audit BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA finance audit failure'");
        $failed = false;
        try {
            Api::post('/api/v1/projects', P::body($actor['id']), $admin, F::key());
        } catch (PDOException $e) {
            $failed = true;
            self::assertStringContainsString('QA finance audit failure', $e->getMessage());
        } finally {
            $this->pdo->exec('DROP TRIGGER finance_qa_reject_audit');
        }
        self::assertTrue($failed);
        self::assertSame($before, $h->snapshot());
    }

    public function test_G_H_history_insert_failure_rolls_back_audit_and_project_too(): void
    {
        $p = new P($this->fixtures);
        [$actor, $admin] = $p->actor();
        $h = new H($this->pdo);
        $tables = ['projects', 'commercial_document_events', 'audit_logs', 'idempotency_requests'];
        $before = $h->snapshot($tables);
        $this->pdo->exec("CREATE TRIGGER finance_qa_reject_history BEFORE INSERT ON commercial_document_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA history failure'");
        $failed = false;
        try {
            Api::post('/api/v1/projects', P::body($actor['id']), $admin, F::key());
        } catch (PDOException $e) {
            $failed = true;
            self::assertStringContainsString('QA history failure', $e->getMessage());
        } finally {
            $this->pdo->exec('DROP TRIGGER finance_qa_reject_history');
        }
        self::assertTrue($failed);
        self::assertSame($before, $h->snapshot($tables));
    }
}
