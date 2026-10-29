<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Repositories\FinanceRepository;
use App\Support\Money;

final class FinanceService
{
    public function __construct(
        private readonly FinanceRepository $repository,
        private readonly Authorizer $authorizer,
        private readonly Database $db,
    ) {
    }

    public function report(string $kind, array $q): array
    {
        $this->authorizer->requireAdmin();
        return $this->db->transaction(function () use ($kind, $q): array {
            if (!$this->repository->historyComplete() || !$this->repository->cutCovered($q)) {
                throw new ApiException(409, 'HISTORY_INCOMPLETE', 'No existe evidencia histórica completa de emisión; no se pueden reconstruir estos indicadores.');
            }
            $meta = ['coverage' => 'COMPLETE','generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'cut_off' => $q['cut_off'],'timezone' => 'UTC','from' => $q['from'],'to' => $q['to'],
                'business_reason' => 'Los importes comerciales usan emisión; caja usa eventos efectivos y pendientes usa el corte.'];
            if ($kind === 'summary') {
                $data = array_merge($this->repository->commercial($q), $this->repository->expenses($q));
                $data['net_cash'] = '0.00';
                $data['distribution'] = ['ORDINARIA' => '0.00','RESERVA' => '0.00','PROYECTO' => '0.00'];
                foreach ($this->repository->cash($q) as $row) {
                    $data['distribution'][$row['bucket']] = $row['amount'];
                    $data['net_cash'] = Money::add($data['net_cash'], $row['amount']);
                }
                $data['cash_result'] = Money::sub($data['net_cash'], $data['paid_expenses']);
                $data['currency'] = 'MXN';
                return ['data' => $data,'meta' => $meta];
            }
            if ($kind === 'cashflow') {
                $months = [];
                $date = new \DateTimeImmutable(substr($q['from'], 0, 7) . '-01', new \DateTimeZone('UTC'));
                while ($date->format('Y-m') <= substr($q['to'], 0, 7)) {
                    $month = $date->format('Y-m');
                    $months[$month] = ['month' => $month,'net_cash' => '0.00','paid_expenses' => '0.00','cash_result' => '0.00'];
                    $date = $date->modify('+1 month');
                }
                foreach ($this->repository->cash($q, true) as $row) {
                    $months[$row['bucket']]['net_cash'] = $row['amount'];
                }
                foreach ($this->repository->expenses($q, true) as $row) {
                    $months[$row['bucket']]['paid_expenses'] = $row['amount'];
                }
                foreach ($months as &$row) {
                    $row['cash_result'] = Money::sub($row['net_cash'], $row['paid_expenses']);
                }
                unset($row);
                return ['data' => array_values($months),'meta' => $meta];
            }
            if ($kind === 'transactions' && ($q['kind'] ?? 'cash') === 'expense') {
                $result = $this->repository->expenseTransactions($q);
                $data = array_map(static fn (array $row): array => [
                    'id' => 'EXPENSE:' . $row['id'],'kind' => 'EXPENSE','expense_id' => (string) $row['id'],
                    'folio' => $row['folio'],'project_id' => $row['project_id'] === null ? null : (string) $row['project_id'],
                    'concept' => $row['concept'],'responsible_id' => (string) $row['responsible_id'],
                    'effective_at' => str_replace(' ', 'T', $row['paid_at']) . 'Z',
                    'amount' => Money::sub('0.00', $row['amount']),
                ], $result['rows']);
                return ['data' => $data,'meta' => array_merge($meta, $result['meta'])];
            }
            $result = match ($kind) {
                'receivables' => $this->repository->receivables($q),
                'documents' => $this->repository->documents($q),
                default => $this->repository->transactions($q),
            };
            $data = [];
            foreach ($result['rows'] as $row) {
                $item = ['document_type' => $row['project_id'] !== null ? 'PROJECT' : 'SALE',
                    'document_id' => (string) ($row['project_id'] ?? $row['sale_id']),
                    'folio' => $row['folio'],'source_type' => $row['source_type'],
                    'client_id' => $row['client_id'] === null ? null : (string) $row['client_id'],
                    'responsible_id' => (string) $row['responsible_id']];
                if (in_array($kind, ['receivables','documents'], true)) {
                    $item += ['issued_at' => str_replace(' ', 'T', $row['issued_at']) . 'Z','total' => $row['total'],
                        'net_paid' => $row['net_paid'],'balance' => $row['balance'],'payment_status' => $row['payment_status']];
                    if ($kind === 'documents') {
                        $item['billable'] = (int) $row['billable'] === 1;
                        $item['commercial_class'] = (int) $row['billable'] === 0 ? 'INTERNAL_ESTIMATE'
                            : ($row['sale_id'] !== null ? 'SALE' : 'PROJECT');
                    }
                } else {
                    $item += ['id' => $row['id'],'kind' => $row['kind'],'amount' => $row['amount'],
                        'effective_at' => str_replace(' ', 'T', $row['effective_at']) . 'Z'];
                }
                $data[] = $item;
            }
            return ['data' => $data,'meta' => array_merge($meta, $result['meta'])];
        });
    }
}
