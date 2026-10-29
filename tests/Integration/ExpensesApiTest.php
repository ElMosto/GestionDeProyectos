<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\FinanceFixtures as H;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\SalesFixtures as F;
use PDOException;

/** §8/§11/§19.B/G/H — expense state, exact money and atomic auditing. */
final class ExpensesApiTest extends DatabaseTestCase
{
    public function test_G_create_requires_key_and_replay_is_one_expense_body_change409(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        $body = H::expenseBody($actor['id']);
        self::assertSame(422, Api::post('/api/v1/expenses', $body, $admin)->status);
        $key = F::key();
        $first = Api::post('/api/v1/expenses', $body, $admin, $key);
        $data = F::ok($first, 201);
        $again = Api::post('/api/v1/expenses', $body, $admin, $key);
        self::assertSame(201, $again->status);
        self::assertSame($data, $again->data());
        self::assertSame('200.00', $data['amount']);
        self::assertIsString($data['version']);
        self::assertSame((string) $actor['id'], $data['responsible_id']);
        self::assertNull($data['project_id']);
        $conflict = Api::post('/api/v1/expenses', array_replace($body, ['amount' => '201.00']), $admin, $key);
        self::assertSame(409, $conflict->status);
        self::assertSame('IDEMPOTENCY_KEY_REUSED', $conflict->errorCode());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='EXPENSE_CREATE'")->fetchColumn());
    }

    public function test_G_expense_paid_at_not_expense_date_controls_cash(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        $data = F::ok(Api::post('/api/v1/expenses', H::expenseBody($actor['id'], [
            'status' => 'PAGADO', 'paid_at' => '2026-10-10T06:00:00-06:00',
        ]), $admin, F::key()), 201);
        self::assertSame('2026-10-10T12:00:00Z', $data['paid_at']);
        $oct = F::ok(Api::get('/api/v1/finance/summary', H::range(), $admin));
        self::assertSame('200.00', $oct['paid_expenses']);
        self::assertSame('-200.00', $oct['cash_result']);
        self::assertSame('0.00', $oct['commercial_total']);
        $sept = F::ok(Api::get('/api/v1/finance/summary', H::range([
            'from' => '2026-09-01', 'to' => '2026-09-30', 'cut_off' => '2026-09-30',
        ]), $admin));
        self::assertSame('0.00', $sept['paid_expenses']);
    }

    public function test_G_pending_can_pay_but_paid_cannot_edit_cancel_or_revert(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        $expense = F::ok(Api::post('/api/v1/expenses', H::expenseBody($actor['id']), $admin, F::key()), 201);
        $path = '/api/v1/expenses/' . $expense['id'];
        $paid = F::ok(Api::patch($path, ['status' => 'PAGADO', 'paid_at' => '2026-10-10T12:00:00Z'], $admin,
            F::key() + ['If-Match' => '"' . $expense['version'] . '"']));
        self::assertSame('PAGADO', $paid['status']);
        self::assertNotSame($expense['version'], $paid['version']);
        foreach ([['amount' => '500.00'], ['status' => 'PENDIENTE', 'paid_at' => null],
            ['status' => 'CANCELADO', 'paid_at' => null], ['concept' => 'Changed']] as $changes) {
            $result = Api::patch($path, $changes + ['expected_version' => $paid['version']], $admin, F::key());
            self::assertSame(409, $result->status);
            self::assertSame('EXPENSE_IMMUTABLE', $result->errorCode());
        }
        self::assertSame($paid, F::ok(Api::get($path, bearer: $admin)));
        self::assertSame(405, Api::delete($path, $admin)->status);
    }

    public function test_G_pending_cancel_preserves_row_and_is_terminal(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        $expense = F::ok(Api::post('/api/v1/expenses', H::expenseBody($actor['id']), $admin, F::key()), 201);
        $path = '/api/v1/expenses/' . $expense['id'];
        $cancelled = F::ok(Api::patch($path, ['status' => 'CANCELADO', 'expected_version' => $expense['version']], $admin, F::key()));
        self::assertSame('CANCELADO', $cancelled['status']);
        self::assertNull($cancelled['paid_at']);
        self::assertSame(409, Api::patch($path, ['status' => 'PENDIENTE', 'expected_version' => $cancelled['version']], $admin, F::key())->status);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn());
        self::assertSame('0.00', F::ok(Api::get('/api/v1/finance/summary', H::currentRange(), $admin))['pending_expenses']);
    }

    public function test_G_pending_updates_require_current_version_and_key(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        $expense = F::ok(Api::post('/api/v1/expenses', H::expenseBody($actor['id']), $admin, F::key()), 201);
        $path = '/api/v1/expenses/' . $expense['id'];
        self::assertSame(422, Api::patch($path, ['amount' => '201.00'], $admin, F::key())->status);
        self::assertSame(422, Api::patch($path, ['amount' => '201.00', 'expected_version' => $expense['version']], $admin)->status);
        $updated = F::ok(Api::patch($path, ['amount' => '0.30', 'expected_version' => $expense['version']], $admin, F::key()));
        self::assertSame('0.30', $updated['amount']);
        $stale = Api::patch($path, ['amount' => '202.00', 'expected_version' => $expense['version']], $admin, F::key());
        self::assertSame(409, $stale->status);
        self::assertSame('VERSION_CONFLICT', $stale->errorCode());
    }

    public function test_G_H_amount_date_reference_and_unknown_fields_are_validated(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        foreach ([['amount' => 1.5], ['amount' => 1], ['amount' => '1'], ['amount' => '0.00'],
            ['amount' => '-1.00'], ['amount' => '0.001'], ['status' => 'PAGADO'],
            ['status' => 'PAGADO', 'paid_at' => '2026-10-10T12:00:00'],
            ['status' => 'PAGADO', 'paid_at' => '2026-02-30T12:00:00Z'],
            ['status' => 'PENDIENTE', 'paid_at' => '2026-10-10T12:00:00Z'],
            ['status' => 'CANCELADO'], ['expense_date' => '2026-02-30'],
            ['responsible_id' => '999999999'], ['project_id' => '999999999'],
            ['created_by' => (string) $actor['id']]] as $invalid) {
            $response = Api::post('/api/v1/expenses', H::expenseBody($actor['id'], $invalid), $admin, F::key());
            self::assertSame(422, $response->status, $response->json());
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn());
    }

    public function test_A_G_all_expense_operations_admin_only_even_assigned_responsible(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        [$leader, $leaderToken] = $f->actor(Role::LIDER_PROYECTO);
        [, $collaborator] = $f->actor(Role::COLABORADOR);
        $body = H::expenseBody($leader['id']);
        $expense = F::ok(Api::post('/api/v1/expenses', $body, $admin, F::key()), 201);
        foreach ([$leaderToken, $collaborator] as $denied) {
            self::assertSame(403, Api::get('/api/v1/expenses', H::range(), $denied)->status);
            self::assertSame(403, Api::get('/api/v1/expenses/' . $expense['id'], bearer: $denied)->status);
            self::assertSame(403, Api::post('/api/v1/expenses', $body, $denied, F::key())->status);
            self::assertSame(403, Api::patch('/api/v1/expenses/' . $expense['id'], [
                'amount' => '300.00', 'expected_version' => $expense['version'],
            ], $denied, F::key())->status);
        }
    }

    public function test_G_H_list_filters_and_real_sql_injection_do_not_broaden_scope(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        foreach (['PENDIENTE', 'PAGADO'] as $status) {
            F::ok(Api::post('/api/v1/expenses', H::expenseBody($actor['id'], ['status' => $status]
                + ($status === 'PAGADO' ? ['paid_at' => '2026-10-10T12:00:00Z'] : [])), $admin, F::key()), 201);
        }
        $q = H::range(['from' => '2026-09-01', 'status' => 'PENDIENTE', 'responsible_id' => (string) $actor['id'], 'limit' => '1']);
        $list = Api::get('/api/v1/expenses', $q, $admin);
        self::assertCount(1, F::ok($list));
        self::assertSame(1, $list->meta()['total']);
        self::assertSame('PENDIENTE', $list->data()[0]['status']);
        self::assertSame(422, Api::get('/api/v1/expenses', H::range(['project_id' => "' OR 1=1 --"]), $admin)->status);
    }

    public function test_G_expense_paid_detail_uses_paid_at_and_reconciles_summary_and_cashflow(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        F::ok(Api::post('/api/v1/expenses', H::expenseBody($actor['id'], [
            'status' => 'PAGADO', 'paid_at' => '2026-10-10T12:00:00Z',
        ]), $admin, F::key()), 201);
        $rows = F::ok(Api::get('/api/v1/finance/transactions', H::range(['kind' => 'expense']), $admin));
        self::assertCount(1, $rows);
        self::assertSame('EXPENSE', $rows[0]['kind']);
        self::assertSame('-200.00', $rows[0]['amount']);
        self::assertSame('2026-10-10T12:00:00Z', $rows[0]['effective_at']);
        self::assertSame('200.00', F::ok(Api::get('/api/v1/finance/summary', H::range(), $admin))['paid_expenses']);
        self::assertSame('200.00', F::ok(Api::get('/api/v1/finance/cashflow', H::range(), $admin))[0]['paid_expenses']);
        self::assertSame([], F::ok(Api::get('/api/v1/finance/transactions', H::range(['kind' => 'expense', 'source_type' => 'ORDINARIA']), $admin)));
        self::assertSame(422, Api::get('/api/v1/finance/transactions', H::range(['kind' => 'unknown']), $admin)->status);
    }

    public function test_G_later_edit_cancel_does_not_rewrite_old_pending_expense_snapshot(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        $expense = (new H($this->pdo))->pendingExpense($actor['id']);
        $oldRange = H::range(['from' => '2026-09-01', 'to' => '2026-09-30', 'cut_off' => '2026-09-30']);
        self::assertSame('200.00', F::ok(Api::get('/api/v1/finance/summary', $oldRange, $admin))['pending_expenses']);
        $path = '/api/v1/expenses/' . $expense['id'];
        $updated = F::ok(Api::patch($path, ['amount' => '500.00', 'expected_version' => '1'], $admin, F::key()));
        F::ok(Api::patch($path, ['status' => 'CANCELADO', 'expected_version' => $updated['version']], $admin, F::key()));
        self::assertSame('200.00', F::ok(Api::get('/api/v1/finance/summary', $oldRange, $admin))['pending_expenses']);
        self::assertSame('0.00', F::ok(Api::get('/api/v1/finance/summary', H::currentRange(), $admin))['pending_expenses']);
    }

    public function test_G_H_expense_audit_failure_rolls_back_money_and_idempotency(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        $h = new H($this->pdo);
        $before = $h->snapshot();
        $this->pdo->exec("CREATE TRIGGER expense_qa_reject_audit BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA expense audit failure'");
        $failed = false;
        try {
            Api::post('/api/v1/expenses', H::expenseBody($actor['id']), $admin, F::key());
        } catch (PDOException $e) {
            $failed = true;
            self::assertStringContainsString('QA expense audit failure', $e->getMessage());
        } finally {
            $this->pdo->exec('DROP TRIGGER expense_qa_reject_audit');
        }
        self::assertTrue($failed);
        self::assertSame($before, $h->snapshot());
    }
}
