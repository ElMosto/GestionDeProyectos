<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Support\Money;
use App\Tests\Support\FinanceFixtures as H;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\ProjectFixtures as P;
use App\Tests\Support\ReservationFixtures as R;
use App\Tests\Support\SalesFixtures as F;

/** §11/§19.B/G/H — real MariaDB, router in-process (not HTTP transport evidence). */
final class FinanceApiTest extends DatabaseTestCase
{
    public function test_G_empty_reports_have_exact_zero_strings_and_complete_coverage(): void
    {
        [, $admin] = (new F($this->fixtures))->actor();
        $response = Api::get('/api/v1/finance/summary', H::range(), $admin);
        $data = F::ok($response);
        foreach (['commercial_total', 'internal_estimated_value', 'net_cash', 'paid_expenses',
            'pending_expenses', 'cash_result', 'receivables_total'] as $key) {
            self::assertSame('0.00', $data[$key]);
        }
        self::assertSame('COMPLETE', $response->meta()['coverage']);
        self::assertSame('UTC', $response->meta()['timezone']);
        self::assertArrayNotHasKey('profit', $data);
        foreach (['transactions', 'receivables'] as $resource) {
            $empty = Api::get('/api/v1/finance/' . $resource, H::range(), $admin);
            self::assertSame([], F::ok($empty));
            self::assertSame(0, $empty->meta()['total']);
        }
    }

    public function test_B_G_internal1500_expense200_is_not_receivable_or_income(): void
    {
        $p = new P($this->fixtures);
        [$actor, $admin] = $p->actor(Role::ADMINISTRADOR);
        [$leader] = $p->actor();
        $project = $p->project($admin, $leader['id'], ['base_amount' => '1000.00']);
        F::ok(Api::post('/api/v1/projects/' . $project['id'] . '/charges', [
            'concept' => 'Cargo interno', 'quantity' => '1.000', 'unit_price' => '500.00',
            'charge_date' => gmdate('Y-m-d'),
        ], $admin, F::key()), 201);
        F::ok(Api::post('/api/v1/expenses', H::expenseBody($actor['id'], [
            'project_id' => $project['id'], 'status' => 'PAGADO', 'paid_at' => '2026-10-10T12:00:00Z',
        ]), $admin, F::key()), 201);
        $data = F::ok(Api::get('/api/v1/finance/summary', H::currentRange(), $admin));
        self::assertSame('1500.00', $data['internal_estimated_value']);
        self::assertSame('0.00', $data['commercial_total']);
        self::assertSame('0.00', $data['receivables_total']);
        self::assertSame('0.00', $data['net_cash']);
        self::assertSame('200.00', $data['paid_expenses']);
        self::assertSame('-200.00', $data['cash_result']);
        self::assertSame('1500.00', F::ok(Api::get('/api/v1/projects/' . $project['id'], bearer: $admin))['financial_summary']['total']);
        self::assertSame('0', (string) $this->pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }

    public function test_G_separate_billable_project_and_sale_total2000_payment300_pending1700(): void
    {
        $p = new P($this->fixtures);
        [$actor, $admin] = $p->actor(Role::ADMINISTRADOR);
        [$leader] = $p->actor();
        $client = $this->fixtures->createClient($actor['id']);
        $p->project($admin, $leader['id'], ['base_amount' => '1000.00', 'billable' => true, 'client_id' => (string) $client['id']]);
        $f = new F($this->fixtures);
        $sale = $f->issued($admin);
        F::pay($admin, $sale['id']);
        $summary = F::ok(Api::get('/api/v1/finance/summary', H::currentRange(), $admin));
        self::assertSame('2000.00', $summary['commercial_total']);
        self::assertSame('300.00', $summary['net_cash']);
        self::assertSame('1700.00', $summary['receivables_total']);
        $detail = F::ok(Api::get('/api/v1/finance/receivables', H::currentRange(), $admin));
        self::assertCount(2, $detail);
        self::assertSame(['1000.00', '700.00'], $this->sorted(array_column($detail, 'balance')));
        $this->assertMoneyStrings($summary);
    }

    public function test_G_old_sale_has_october_cash_but_no_october_commercial(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        $h = new H($this->pdo);
        $sale = $h->sale($actor['id']);
        $h->payment($actor['id'], $sale['id'], '300.00');
        $data = F::ok(Api::get('/api/v1/finance/summary', H::range(), $admin));
        self::assertSame('0.00', $data['commercial_total']);
        self::assertSame('300.00', $data['net_cash']);
        self::assertSame('700.00', $data['receivables_total']);
        $september = F::ok(Api::get('/api/v1/finance/summary', H::range([
            'from' => '2026-09-01', 'to' => '2026-09-30', 'cut_off' => '2026-09-30',
        ]), $admin));
        self::assertSame('1000.00', $september['commercial_total']);
        self::assertSame('0.00', $september['net_cash']);
        self::assertSame('1000.00', $september['receivables_total']);
    }

    public function test_G_two_refunds20_30_on120_do_not_multiply_payment_in_joins(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        $h = new H($this->pdo);
        $sale = $h->sale($actor['id'], '120.00');
        $payment = $h->payment($actor['id'], $sale['id']);
        $h->refund($actor['id'], $payment, '20.00', '2026-10-10 12:00:00');
        $h->refund($actor['id'], $payment, '30.00', '2026-10-20 12:00:00');
        $summary = F::ok(Api::get('/api/v1/finance/summary', H::range(), $admin));
        self::assertSame('70.00', $summary['net_cash']);
        self::assertSame('50.00', $summary['receivables_total']);
        self::assertSame('70.00', $summary['distribution']['ORDINARIA']);
        $detail = F::ok(Api::get('/api/v1/finance/transactions', H::range(), $admin));
        self::assertCount(3, $detail);
        self::assertSame(['-20.00', '-30.00', '120.00'], $this->sorted(array_column($detail, 'amount')));
        $flow = F::ok(Api::get('/api/v1/finance/cashflow', H::range(), $admin));
        self::assertSame('70.00', $flow[0]['net_cash']);
        $earlier = F::ok(Api::get('/api/v1/finance/receivables', H::range([
            'to' => '2026-10-15', 'cut_off' => '2026-10-15',
        ]), $admin));
        self::assertSame('100.00', $earlier[0]['net_paid']);
        self::assertSame('20.00', $earlier[0]['balance']);
    }

    public function test_G_void_in_october_keeps_original_september_cash_and_cut_balance(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        $h = new H($this->pdo);
        $sale = $h->sale($actor['id'], '120.00');
        $h->payment($actor['id'], $sale['id'], '120.00', '2026-09-15 12:00:00', [
            'status' => 'ANULADO', 'voided_at' => '2026-10-10 12:00:00', 'voided_by' => $actor['id'], 'void_reason' => 'Error inicial',
        ]);
        $sept = H::range(['from' => '2026-09-01', 'to' => '2026-09-30', 'cut_off' => '2026-09-30']);
        $before = F::ok(Api::get('/api/v1/finance/summary', $sept, $admin));
        self::assertSame('120.00', $before['net_cash']);
        self::assertSame('0.00', $before['receivables_total']);
        $after = F::ok(Api::get('/api/v1/finance/summary', H::range(), $admin));
        self::assertSame('-120.00', $after['net_cash']);
        self::assertSame('120.00', $after['receivables_total']);
        $events = F::ok(Api::get('/api/v1/finance/transactions', H::range(), $admin));
        self::assertCount(1, $events);
        self::assertSame('PAYMENT_VOID', $events[0]['kind']);
        self::assertSame('-120.00', $events[0]['amount']);
    }

    public function test_G_reservation120_is_counted_once_only_through_sale(): void
    {
        $r = new R($this->fixtures);
        [$actor, $admin] = $r->actor();
        $reservation = R::reserve($admin, $r->band($actor['id']), $r->space($admin), [
            'end_at' => '2030-01-07T11:00:00Z',
        ]);
        F::pay($admin, $reservation['sale_id'], '120.00');
        $data = F::ok(Api::get('/api/v1/finance/summary', H::currentRange(), $admin));
        self::assertSame('120.00', $data['commercial_total']);
        self::assertSame('120.00', $data['net_cash']);
        self::assertSame('120.00', $data['distribution']['RESERVA']);
        self::assertSame('0.00', $data['distribution']['ORDINARIA']);
        self::assertSame('0.00', $data['receivables_total']);
    }

    public function test_G_filters_scope_documents_and_cash_and_pagination_does_not_change_totals(): void
    {
        $f = new F($this->fixtures);
        [$actor, $admin] = $f->actor();
        [$other] = $f->actor(Role::LIDER_PROYECTO);
        $client = $this->fixtures->createClient($actor['id']);
        $h = new H($this->pdo);
        $sale = $h->sale($actor['id'], overrides: ['client_id' => $client['id']]);
        $h->payment($actor['id'], $sale['id'], '300.00');
        $h->sale($other['id'], '2500.00');
        $q = H::range(['client_id' => (string) $client['id'], 'responsible_id' => (string) $actor['id'],
            'source_type' => 'ORDINARIA', 'status' => 'PARCIAL', 'limit' => '1', 'offset' => '0']);
        $summary = F::ok(Api::get('/api/v1/finance/summary', $q, $admin));
        self::assertSame('300.00', $summary['net_cash']);
        self::assertSame('700.00', $summary['receivables_total']);
        $detail = Api::get('/api/v1/finance/receivables', $q, $admin);
        self::assertSame($sale['id'], F::ok($detail)[0]['document_id']);
        self::assertSame(1, $detail->meta()['total']);
        $q['offset'] = '1';
        self::assertSame([], F::ok(Api::get('/api/v1/finance/receivables', $q, $admin)));
        self::assertSame($summary, F::ok(Api::get('/api/v1/finance/summary', $q, $admin)));
        self::assertSame('0.00', F::ok(Api::get('/api/v1/finance/summary', H::range(['source_type' => 'RESERVA']), $admin))['net_cash']);
    }

    public function test_G_H_legacy_emitted_source_with_payment_fails_closed_not_silently_omitted(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        $h = new H($this->pdo);
        $legacy = $h->sale($actor['id'], evidence: false);
        $h->payment($actor['id'], $legacy['id']);
        foreach (['summary', 'transactions', 'receivables', 'cashflow'] as $resource) {
            $response = Api::get('/api/v1/finance/' . $resource, H::range(), $admin);
            self::assertSame(409, $response->status);
            self::assertSame('HISTORY_INCOMPLETE', $response->errorCode());
        }
    }

    public function test_G_commercial_detail_all201_records_reconciles_beyond_page_limits(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        $h = new H($this->pdo);
        for ($i = 0; $i < 201; $i++) {
            $h->sale($actor['id'], '1.00', '2026-10-01 12:00:00');
        }
        $summary = F::ok(Api::get('/api/v1/finance/summary', H::range(), $admin));
        self::assertSame('201.00', $summary['commercial_total']);
        self::assertSame('201.00', $summary['commercial_sales_total']);
        self::assertSame('0.00', $summary['commercial_projects_total']);
        $sum = '0.00';
        $ids = [];
        foreach ([0, 100, 200] as $offset) {
            $response = Api::get('/api/v1/finance/documents', H::range(['limit' => '100', 'offset' => (string) $offset]), $admin);
            $rows = F::ok($response);
            self::assertSame(201, $response->meta()['total']);
            self::assertCount($offset === 200 ? 1 : 100, $rows);
            foreach ($rows as $row) {
                self::assertSame('SALE', $row['commercial_class']);
                self::assertTrue($row['billable']);
                $sum = Money::add($sum, $row['total']);
                $ids[] = $row['document_id'];
            }
        }
        self::assertCount(201, array_unique($ids));
        self::assertSame($summary['commercial_total'], $sum);
    }

    public function test_G_cash_refund_of_old_payment_belongs_only_to_refund_month(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        $h = new H($this->pdo);
        $sale = $h->sale($actor['id'], '120.00');
        $payment = $h->payment($actor['id'], $sale['id'], '120.00', '2026-09-15 12:00:00');
        $h->refund($actor['id'], $payment, '20.00', '2026-10-10 12:00:00');
        $summary = F::ok(Api::get('/api/v1/finance/summary', H::range(), $admin));
        self::assertSame('-20.00', $summary['net_cash']);
        self::assertSame('20.00', $summary['receivables_total']);
        $flow = F::ok(Api::get('/api/v1/finance/cashflow', H::range(['from' => '2026-09-01']), $admin));
        self::assertSame(['120.00', '-20.00'], array_column($flow, 'net_cash'));
    }

    public function test_G_H_backdated_cash_without_evidence_at_old_cut_is_incomplete_not_zero(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $sale = $f->issued($admin);
        F::pay($admin, $sale['id']);
        $response = Api::get('/api/v1/finance/summary', H::range(['to' => '2026-10-20', 'cut_off' => '2026-10-20']), $admin);
        self::assertSame(409, $response->status);
        self::assertSame('HISTORY_INCOMPLETE', $response->errorCode());
    }

    public function test_A_G_dashboard_denies_leader_even_for_own_project_and_collaborator(): void
    {
        $p = new P($this->fixtures);
        [$leader, $token] = $p->actor();
        $p->project($token, $leader['id']);
        [, $collaborator] = $p->actor(Role::COLABORADOR);
        foreach ([$token, $collaborator] as $denied) {
            foreach (['summary', 'transactions', 'receivables', 'cashflow'] as $resource) {
                self::assertSame(403, Api::get('/api/v1/finance/' . $resource, H::range(), $denied)->status);
            }
        }
        self::assertSame(401, Api::get('/api/v1/finance/summary', H::range())->status);
    }

    public function test_G_H_real_injection_and_invalid_filters_are_rejected(): void
    {
        [, $admin] = (new F($this->fixtures))->actor();
        foreach ([['client_id' => "' OR 1=1 --"], ['source_type' => "' OR 1=1 --"],
            ['status' => 'EMITIDA'], ['from' => '2026-02-30'], ['to' => '2026-09-30'],
            ['from' => '2024-01-01'], ['cut_off' => '2026-10-01']] as $invalid) {
            self::assertSame(422, Api::get('/api/v1/finance/summary', H::range($invalid), $admin)->status);
        }
    }

    private function sorted(array $values): array
    {
        sort($values, SORT_STRING);
        return $values;
    }

    private function assertMoneyStrings(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->assertMoneyStrings($value);
            } elseif ($key !== 'currency') {
                self::assertIsString($value);
                self::assertMatchesRegularExpression('/\A-?[0-9]+\.[0-9]{2}\z/', $value);
            }
        }
    }
}
