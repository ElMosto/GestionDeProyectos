<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Policies\Authorizer;
use App\Repositories\PaymentDocumentRepository;
use App\Repositories\PaymentLedgerRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\RefundRepository;
use App\Repositories\SequenceRepository;
use App\Support\Version;

/** §11: a single ledger, document mutex and transaction for both origins. */
final class PaymentService
{
    public function __construct(
        private readonly PaymentDocumentRepository $documents,
        private readonly PaymentRepository $payments,
        private readonly RefundRepository $refunds,
        private readonly PaymentLedgerRepository $entries,
        private readonly PaymentLedger $ledger,
        private readonly SequenceRepository $sequences,
        private readonly Authorizer $authorizer,
        private readonly ProjectIdempotency $idempotency,
        private readonly ProjectAudit $audit,
    ) {
    }

    public function history(string $kind, int $id, array $query): array
    {
        $this->document($kind, $id, false);
        $page = $this->payments->paginate($kind, $id, $query);
        return ['data' => array_map(PaymentSerializer::payment(...), $page['rows']), 'meta' => $page['meta']];
    }

    public function refundHistory(int $id, array $query): array
    {
        $payment = $this->payment($id);
        [$kind, $documentId] = self::origin($payment);
        $this->document($kind, $documentId, false);
        $page = $this->refunds->paginate($id, $query);
        return ['data' => array_map(PaymentSerializer::refund(...), $page['rows']), 'meta' => $page['meta']];
    }

    public function registerPayment(string $kind, int $id, array $input, ?string $key, bool $markPaid = false): array
    {
        if ($markPaid && $kind !== 'sale') {
            throw new \InvalidArgumentException('Atajo disponible solo para ventas.');
        }
        $path = '/api/v1/' . ($kind === 'sale' ? 'sales' : 'projects') . '/' . $id
            . ($markPaid ? '/mark-paid' : '/payments');
        $operation = $markPaid ? 'payments.mark-paid' : 'payments.register.' . $kind;
        return $this->run($operation, $path, $input, $key, $markPaid ? 200 : 201,
            function () use ($kind, $id, $input, $key, $operation, $path, $markPaid): array {
                $document = $this->document($kind, $id, true);
                $allowed = $kind === 'sale' ? $this->authorizer->canRegisterSalePayment($document)
                    : $this->authorizer->canRegisterProjectPayment($document);
                if (!$allowed) {
                    throw ApiException::forbidden();
                }
                self::expectedVersion($document, $input);
                if ($kind === 'project' && ($document['client_id'] === null
                    || !$this->documents->clientActive((int) $document['client_id']))) {
                    throw new ApiException(409, 'CLIENT_INACTIVE', 'El proyecto necesita un cliente activo para registrar cobros.');
                }
                $this->entries->lockEntries($kind, $id);
                $summary = $this->summary($kind, $document);
                $amount = $markPaid ? $summary['balance'] : $input['amount'];
                if ($markPaid && bccomp($amount, '0.00', 2) === 0) {
                    return $this->result($kind, $document, null);
                }
                if (bccomp($amount, '0.00', 2) <= 0) {
                    throw ApiException::validation(['amount' => 'El importe debe ser positivo.']);
                }
                if (bccomp(bcadd($summary['net_paid'], $amount, 2), $summary['total'], 2) > 0) {
                    throw new ApiException(409, 'OVERPAYMENT', 'El pago supera el saldo pendiente.');
                }
                $paymentId = $this->payments->register([
                    'folio' => $this->sequences->nextPayment(),
                    'project_id' => $kind === 'project' ? $id : null,
                    'sale_id' => $kind === 'sale' ? $id : null,
                    'amount' => $amount,
                    'effective_at' => $input['effective_at'],
                    'method' => $input['method'],
                    'reference' => $input['reference'] ?? null,
                    'notes' => $input['notes'] ?? null,
                    'registered_by' => $this->authorizer->id(),
                    'idempotency_key' => $this->storageKey($operation, $path, $key),
                ]);
                $this->bump($kind, $document);
                $payment = $this->payment($paymentId, true);
                $this->audit->record('payments.register', 'payment', $paymentId, null, PaymentSerializer::payment($payment));
                return $this->result($kind, $document, $payment);
            });
    }

    public function voidPayment(int $id, array $input, ?string $key): array
    {
        $this->authorizer->requireAdmin();
        return $this->run('payments.void', '/api/v1/payments/' . $id . '/void', $input, $key, 200,
            function () use ($id, $input): array {
                [$kind, $document, $payment] = $this->lockedPayment($id);
                self::expectedVersion($document, $input);
                if ($payment['status'] !== 'REGISTRADO') {
                    throw new ApiException(409, 'PAYMENT_VOIDED', 'El pago ya está anulado.');
                }
                if ($this->refunds->hasRegistered($id)) {
                    throw new ApiException(409, 'PAYMENT_HAS_REFUNDS', 'No se puede anular un pago con reembolsos efectivos.');
                }
                $this->payments->voidPayment($id, (int) $this->authorizer->id(), $input['reason']);
                $this->bump($kind, $document);
                $after = $this->payment($id, true);
                $this->audit->record('payments.void', 'payment', $id, PaymentSerializer::payment($payment), PaymentSerializer::payment($after));
                return $this->result($kind, $document, $after);
            }, true);
    }

    public function refundPayment(int $id, array $input, ?string $key): array
    {
        $this->authorizer->requireAdmin();
        $operation = 'payments.refund';
        $path = '/api/v1/payments/' . $id . '/refunds';
        return $this->run($operation, $path, $input, $key, 201,
            function () use ($id, $input, $key, $operation, $path): array {
                [$kind, $document, $payment] = $this->lockedPayment($id);
                self::expectedVersion($document, $input);
                if ($payment['status'] !== 'REGISTRADO') {
                    throw new ApiException(409, 'PAYMENT_VOIDED', 'No se puede reembolsar un pago anulado.');
                }
                $available = bcsub((string) $payment['amount'], $this->refunds->registeredAmount($id), 2);
                if (bccomp($input['amount'], $available, 2) > 0) {
                    throw new ApiException(409, 'REFUND_EXCEEDS_PAYMENT', 'El reembolso supera el importe disponible del pago.');
                }
                $refundId = $this->refunds->register([
                    'folio' => $this->sequences->nextRefund(), 'payment_id' => $id,
                    'amount' => $input['amount'], 'effective_at' => $input['effective_at'],
                    'reason' => $input['reason'], 'registered_by' => $this->authorizer->id(),
                    'idempotency_key' => $this->storageKey($operation, $path, $key),
                ]);
                $this->bump($kind, $document);
                $refund = $this->refunds->find($refundId);
                $this->audit->record('payments.refund', 'refund', $refundId, null, PaymentSerializer::refund($refund));
                return $this->result($kind, $document, $payment, $refund);
            }, true);
    }

    private function run(string $operation, string $path, array $input, ?string $key, int $status, callable $work, bool $admin = false): array
    {
        $this->authorizer->requireUser();
        return $this->idempotency->run($operation, $key,
            ['method' => 'POST', 'path' => $path, 'body' => $input], $work, $status,
            function (array $response) use ($admin): void {
                if ($admin) {
                    $this->authorizer->requireAdmin();
                }
                $document = $response['document'];
                $this->document($document['kind'], (int) $document['id'], true);
            });
    }

    private function document(string $kind, int $id, bool $lock): array
    {
        $this->authorizer->requireUser();
        $row = $this->documents->find($kind, $id, $lock);
        $allowed = $kind === 'sale' ? $this->authorizer->canViewSaleFinance($row)
            : $this->authorizer->canViewProjectFinance($row);
        if (!$allowed) {
            throw ApiException::notFound();
        }
        if ($kind === 'project') {
            $row['total'] = (string) $row['base_amount'];
            foreach ($row['charge_amounts'] as $charge) {
                $row['total'] = bcadd($row['total'], (string) $charge['amount'], 2);
            }
            unset($row['charge_amounts']);
        }
        return $row;
    }

    private function payment(int $id, bool $lock = false): array
    {
        return $this->payments->find($id, $lock) ?? throw ApiException::notFound();
    }

    /** Resolve without lock; acquire document before any payment/refund lock. */
    private function lockedPayment(int $id): array
    {
        $before = $this->payment($id);
        [$kind, $documentId] = self::origin($before);
        $document = $this->document($kind, $documentId, true);
        $this->entries->lockEntries($kind, $documentId);
        $payment = $this->payment($id, true);
        if (self::origin($payment) !== [$kind, $documentId]) {
            throw new ApiException(409, 'PAYMENT_DOCUMENT_CHANGED', 'El documento del pago cambió. Reintenta la operación.');
        }
        return [$kind, $document, $payment];
    }

    private static function origin(array $payment): array
    {
        return $payment['sale_id'] !== null ? ['sale', (int) $payment['sale_id']]
            : ['project', (int) $payment['project_id']];
    }

    private static function expectedVersion(array $document, array $input): void
    {
        if (($input['expected_version'] ?? null) !== null) {
            Version::assert($document, $input['expected_version']);
        }
    }

    private function storageKey(string $operation, string $path, ?string $key): string
    {
        // D-19: global UNIQUE ledger keys must not collide between actors/routes.
        return hash('sha256', json_encode([(string) $this->authorizer->id(), $operation, $path, $key], JSON_THROW_ON_ERROR));
    }

    private function bump(string $kind, array &$document): void
    {
        $this->documents->recordLedgerChange($kind, (int) $document['id']);
        $document['version'] = (int) $document['version'] + 1;
    }

    private function summary(string $kind, array $document): array
    {
        return $this->ledger->summary($kind, (int) $document['id'], (string) $document['total'],
            $kind === 'sale' || (bool) $document['billable']);
    }

    private function result(string $kind, array $document, ?array $payment, ?array $refund = null): array
    {
        return [
            'payment' => $payment === null ? null : PaymentSerializer::payment($payment),
            'refund' => $refund === null ? null : PaymentSerializer::refund($refund),
            'document' => ['kind' => $kind, 'id' => (string) $document['id'], 'version' => (string) $document['version']],
            'financial_summary' => $this->summary($kind, $document),
        ];
    }
}
