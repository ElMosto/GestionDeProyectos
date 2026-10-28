<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\UserPresenter;
use App\Core\ApiException;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Policies\Capabilities;
use App\Repositories\SaleRepository;
use App\Repositories\SequenceRepository;
use App\Support\Money;
use App\Support\Version;

final class SaleService
{
    public function __construct(
        private readonly SaleRepository $sales,
        private readonly SequenceRepository $sequences,
        private readonly SaleCalculatorService $calculator,
        private readonly PaymentLedger $ledger,
        private readonly Database $db,
        private readonly Authorizer $authorizer,
        private readonly ProjectAudit $audit,
        private readonly ProjectIdempotency $idempotency,
    ) {
    }

    public function requireSale(int $id, bool $lock = false): array
    {
        $this->authorizer->requireUser();
        $sale = $this->sales->find($id, $lock);
        if ($sale === null || !$this->authorizer->canViewSale($sale)) {
            throw ApiException::notFound('Venta no encontrada.');
        }
        return $sale;
    }

    public function list(array $q): array
    {
        $this->authorizer->requireUser();
        if (isset($q['responsible_id'])) {
            $this->authorizer->requireAdmin();
        }
        $page = $this->sales->paginate($q, (int) $this->authorizer->id(), $this->authorizer->isAdmin());
        // Lista sin partidas ni ledger por fila: totales de borrador se resuelven por lote.
        $draftIds = array_map(static fn (array $s): int => (int) $s['id'],
            array_filter($page['rows'], static fn (array $s): bool => $s['status'] === 'BORRADOR'));
        $draftItems = $this->sales->itemsForSales($draftIds);
        foreach ($draftIds as $draftId) {
            $draftItems[$draftId] ??= [];
        }
        $calculated = $this->calculator->calculateBatch($draftItems);
        $data = [];
        foreach ($page['rows'] as $sale) {
            $data[] = $this->present($sale, false, $calculated[(int) $sale['id']] ?? null);
        }
        return ['data' => $data, 'meta' => $page['meta']];
    }

    public function show(int $id): array
    {
        return $this->present($this->requireSale($id));
    }

    public function create(array $input, ?string $key): array
    {
        $this->authorizer->requireCapability(Capabilities::SALES_CREATE);
        return $this->idempotency->run('sale.create', $key, $input, function () use ($input): array {
            $this->authorizer->requireCapability(Capabilities::SALES_CREATE);
            $clientId = $input['client_id'] ?? null;
            $this->assertActiveClient($clientId);
            $calc = $this->calculator->calculate($input['items'] ?? [], true, true);
            $id = $this->sales->create(['folio' => $this->sequences->nextSale(), 'client_id' => $clientId,
                'responsible_id' => $this->authorizer->id(), 'created_by' => $this->authorizer->id(),
                'notes' => $input['notes'] ?? null, 'total' => $calc['total']]);
            $this->sales->replaceItems($id, $calc['items']);
            $this->audit->record('SALE_CREATE', 'sale', $id, null, $input + ['total' => $calc['total']]);
            return $this->show($id);
        }, 201, function (array $result): void {
            $this->authorizer->requireCapability(Capabilities::SALES_CREATE);
            $this->requireSale((int) $result['id'], true);
        });
    }

    public function update(int $id, array $fields, int $version, ?string $key): array
    {
        $this->requireSale($id);
        $work = function () use ($id, $fields, $version): array {
            $old = $this->requireSale($id, true);
            $this->assertDraft($old);
            if (!$this->authorizer->canManageSale($old)) {
                throw ApiException::forbidden();
            }
            Version::assert($old, $version);
            $this->assertActiveClient(array_key_exists('client_id', $fields) ? $fields['client_id'] : $old['client_id']);
            $replacing = array_key_exists('items', $fields);
            $data = array_intersect_key($fields, array_flip(['client_id', 'notes']));
            if ($replacing) {
                $calc = $this->calculator->calculate($fields['items'], true, true);
                $data['total'] = $calc['total'];
                $this->sales->replaceItems($id, $calc['items']);
            }
            $this->sales->updateDraft($id, $data);
            $this->audit->record('SALE_UPDATE', 'sale', $id, ['total' => $old['total'], 'version' => $old['version']],
                $fields + ['total' => $data['total'] ?? $old['total']]);
            return $this->show($id);
        };
        if (array_key_exists('items', $fields)) {
            return $this->idempotency->run('sale.update.' . $id, $key,
                ['fields' => $fields, 'version' => $version], $work, 200, function () use ($id): void {
                    $this->requireSale($id, true);
                });
        }
        return $this->db->transaction($work);
    }

    public function issueSale(int $id, int $version, ?string $key): array
    {
        $this->requireSale($id);
        return $this->idempotency->run('sale.issue.' . $id, $key, ['version' => $version], function () use ($id, $version): array {
            $sale = $this->requireSale($id, true);
            $this->assertDraft($sale);
            if (!$this->authorizer->canIssueSale($sale)) {
                throw ApiException::forbidden();
            }
            Version::assert($sale, $version);
            $this->assertActiveClient($sale['client_id']);
            $items = $this->sales->items($id);
            if ($items === []) {
                throw ApiException::validation(['items' => 'La venta debe tener al menos una partida.']);
            }
            $calc = $this->calculator->calculate($items, true);
            $this->sales->freezeItems($calc['items']);
            $this->sales->issueSale($id, $calc['total']);
            $this->audit->record('SALE_ISSUE', 'sale', $id, ['status' => $sale['status'], 'total' => $sale['total']],
                ['status' => 'EMITIDA', 'total' => $calc['total'], 'items' => $calc['items']]);
            return $this->show($id);
        }, 200, function () use ($id): void {
            $this->requireSale($id, true);
        });
    }

    public function cancelSale(int $id, string $reason, int $version, ?string $key): array
    {
        $this->requireSale($id);
        return $this->idempotency->run('sale.cancel.' . $id, $key, ['reason' => $reason, 'version' => $version],
            function () use ($id, $reason, $version): array {
                $sale = $this->requireSale($id, true);
                if (!$this->authorizer->canCancelSale($sale)) {
                    throw ApiException::forbidden();
                }
                $this->assertOrdinary($sale);
                Version::assert($sale, $version);
                if ($sale['status'] === 'CANCELADA') {
                    throw new ApiException(409, 'SALE_CANCELLED', 'La venta ya está cancelada.');
                }
                $summary = $this->ledger->summary('sale', $id, $sale['total']);
                if (Money::compare($summary['net_paid'], '0.00') > 0) {
                    throw new ApiException(409, 'PAYMENTS_REQUIRE_RESOLUTION', 'Resuelve los pagos antes de cancelar la venta.');
                }
                $this->sales->cancelSale($id);
                $this->audit->record('SALE_CANCEL', 'sale', $id, ['status' => $sale['status']],
                    ['status' => 'CANCELADA', 'reason' => $reason]);
                return $this->show($id);
            }, 200, function () use ($id): void {
                $sale = $this->requireSale($id, true);
                if (!$this->authorizer->canCancelSale($sale)) {
                    throw ApiException::forbidden();
                }
            });
    }

    private function assertOrdinary(array $sale): void
    {
        if ($sale['type'] !== 'ORDINARIA') {
            throw new ApiException(409, 'RESERVATION_MANAGED', 'Gestiona esta venta desde su reserva.');
        }
    }

    private function assertDraft(array $sale): void
    {
        $this->assertOrdinary($sale);
        if ($sale['status'] !== 'BORRADOR') {
            throw new ApiException(409, 'SALE_NOT_DRAFT', 'Solo se pueden modificar ventas en borrador.');
        }
    }

    private function assertActiveClient(int|string|null $id): void
    {
        if ($id !== null) {
            $client = $this->sales->client((int) $id, true);
            if ($client === null || !$client['active']) {
                throw ApiException::validation(['client_id' => 'Selecciona un cliente activo.']);
            }
        }
    }

    private function present(array $sale, bool $detail = true, ?array $calculated = null): array
    {
        // Serializador fail-closed incluso si un llamador interno omite requireSale.
        if (!$this->authorizer->canViewSale($sale)) {
            throw ApiException::notFound('Venta no encontrada.');
        }
        $out = [];
        foreach (['id', 'folio', 'type', 'client_id', 'responsible_id', 'status', 'currency', 'total', 'notes', 'version'] as $key) {
            $out[$key] = $sale[$key] === null ? null : (string) $sale[$key];
        }
        foreach (['issued_at', 'created_at', 'updated_at'] as $key) {
            $out[$key] = UserPresenter::iso($sale[$key]);
        }
        $items = $detail ? $this->sales->items((int) $sale['id']) : [];
        if ($sale['status'] === 'BORRADOR') {
            $calc = $calculated ?? $this->calculator->calculate($items);
            $items = $calc['items'];
            $out['total'] = $calc['total'];
        }
        if ($detail) {
            $out['items'] = $this->presentItems($items);
            $out['financial_summary'] = $this->ledger->summary('sale', (int) $sale['id'], $out['total']);
        }
        return $out;
    }

    private function presentItems(array $items): array
    {
        foreach ($items as &$item) {
            foreach (['id', 'product_id'] as $key) {
                $item[$key] = isset($item[$key]) ? (string) $item[$key] : null;
            }
            $item['sort_order'] = (int) $item['sort_order'];
            foreach ($item['extras'] as &$extra) {
                foreach (['id', 'extra_id'] as $key) {
                    $extra[$key] = isset($extra[$key]) ? (string) $extra[$key] : null;
                }
            }
            unset($extra);
        }
        unset($item);
        return $items;
    }

    public function ticketData(int $id): array
    {
        $out = $this->show($id);
        $client = $out['client_id'] === null ? null : $this->sales->client((int) $out['client_id']);
        if ($client !== null) {
            $client['id'] = (string) $client['id'];
            unset($client['active']);
            $client['representative_phone'] = $client['type'] === 'BANDA' ? $client['phone'] : null;
        }
        $out['client'] = $client;
        $responsible = $this->sales->responsible((int) $out['responsible_id']);
        $out['responsible'] = ['id' => (string) $out['responsible_id'], 'name' => $responsible['name'] ?? ''];
        foreach (['payments', 'refunds'] as $kind) {
            $rows = $this->sales->{$kind}($id);
            foreach ($rows as &$row) {
                foreach (['id', 'payment_id'] as $key) {
                    if (isset($row[$key])) {
                        $row[$key] = (string) $row[$key];
                    }
                }
                foreach (['effective_at', 'voided_at'] as $key) {
                    if (array_key_exists($key, $row)) {
                        $row[$key] = UserPresenter::iso($row[$key]);
                    }
                }
            }
            unset($row);
            $out[$kind] = $rows;
        }
        return $out;
    }
}
