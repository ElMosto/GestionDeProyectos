<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PaymentLedgerRepository;

/** Internal calculator: its caller must authorize the document, never an endpoint. */
final class PaymentLedger
{
    public function __construct(private readonly PaymentLedgerRepository $repository)
    {
    }

    public function summary(string $kind, int $id, string $total, bool $billable = true): array
    {
        $totals = $this->repository->totals($kind, $id);
        $paid = bcadd($totals['paid_amount'], '0', 2);
        $refunded = bcadd($totals['refunded_amount'], '0', 2);
        $net = bcsub($paid, $refunded, 2);
        $total = bcadd($total, '0', 2);
        $status = !$billable || bccomp($total, '0', 2) === 0 ? 'SIN_CARGO'
            : (bccomp($net, '0', 2) === 0 ? 'NO_PAGADO'
                : (bccomp($net, $total, 2) >= 0 ? 'PAGADO' : 'PARCIAL'));
        return [
            'total' => $total,
            'paid_amount' => $paid,
            'refunded_amount' => $refunded,
            'net_paid' => $net,
            'balance' => $billable ? bcsub($total, $net, 2) : '0.00',
            'payment_status' => $status,
            'billable' => $billable,
        ];
    }
}
